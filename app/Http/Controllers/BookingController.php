<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DocumentBook;
use App\Models\SaleBooking;
use App\Models\SalesArea;
use App\Models\TransportJob;
use App\Models\User;
use App\Services\Sales\BookingDeliveryService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status', '');
        $bookId = $request->integer('book') ?: null;
        $branchId = $request->integer('branch_id') ?: null;
        $salesAreaId = $request->integer('sales_area_id') ?: null;
        $salesUserId = $request->integer('sales_user_id') ?: null;

        $bookings = SaleBooking::with(['document.customer', 'document.branch', 'document.salesUser', 'document.salesArea', 'document.documentBook'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($salesAreaId, fn ($query) => $query->where('sales_area_id', $salesAreaId))
            ->when($salesUserId, fn ($query) => $query->where('sales_user_id', $salesUserId))
            ->when($branchId, fn ($query) => $query->whereHas('document', fn ($d) => $d->where('branch_id', $branchId)))
            ->when($bookId, fn ($query) => $query->whereHas('document', fn ($d) => $d->where('document_book_id', $bookId)))
            ->when($q !== '', fn ($query) => $query->whereHas('document', fn ($d) => $d
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $counts = [
            'all' => SaleBooking::count(),
            'pending' => SaleBooking::where('status', 'pending')->count(),
            'converted_to_sale' => SaleBooking::where('status', 'converted_to_sale')->count(),
        ];

        $branches = Branch::orderBy('code')->get();
        $currentUser = $request->user();
        // ต้องรวมทั้งสิทธิ์ระดับบริษัท (roles) และสิทธิ์เฉพาะสาขา (branchRoles) และต้องมีคนที่ล็อกอินอยู่เสมอ
        // เพื่อให้เลือกตัวเองเป็นผู้รับผิดชอบได้แม้ยังไม่ได้รับสิทธิ์ sales.manage/sales.assign
        $salesUsers = User::where('is_active', true)
            ->where(function ($query) use ($currentUser) {
                $query->whereHas('roles.permissions', fn ($permission) => $permission->whereIn('code', ['sales.manage', 'sales.assign']))
                    ->orWhereHas('branchRoles.permissions', fn ($permission) => $permission->whereIn('code', ['sales.manage', 'sales.assign']));
                if ($currentUser) {
                    $query->orWhere('id', $currentUser->id);
                }
            })
            ->with('salesArea:id,code,name')
            ->orderBy('username')
            ->get(['id', 'username', 'name', 'sales_area_id']);
        $salesAreas = SalesArea::with(['branch', 'defaultSalesman', 'documentBook'])
            ->where('is_active', true)
            ->where('area_type', 'route')
            ->orderBy('code')
            ->get();
        $documentBooks = DocumentBook::whereHas('documentType', fn ($query) => $query->whereIn('code', ['BOOKING', 'CREDIT_SALE']))
            ->where('is_active', true)
            ->orderBy('document_type_id')
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();
        $currentUser = $request->user()->loadMissing('salesArea');
        $bookingDefaults = [
            'branch_id' => $currentUser->branch_id,
            'sales_user_id' => $currentUser->id,
            'sales_user_name' => $currentUser->name,
            'sales_area_id' => $currentUser->sales_area_id,
        ];

        return view('bookings.index', compact(
            'bookings', 'branches', 'salesUsers', 'salesAreas',
            'documentBooks', 'bookingDefaults', 'q', 'status', 'bookId', 'branchId', 'salesAreaId', 'salesUserId', 'counts'
        ));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('bookings.index');
    }

    public function store(Request $request, BookingService $service): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'sales_area_id' => ['nullable', 'integer', 'exists:sales_areas,id'],
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            // ไม่ส่งมา = รับเองที่สาขา (ค่าเดิมของใบจองทั้งหมดก่อนมีฟิลด์นี้)
            'fulfillment_type' => ['nullable', 'in:pickup,delivery'],
            // ส่งของต้องมีกำหนดส่ง รับเองไม่ต้อง
            'delivery_due_at' => ['nullable', 'required_if:fulfillment_type,delivery', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user()->loadMissing('salesArea');
        $canAssign = $user->hasPermission('sales.assign');
        $assigneeId = (int) ($data['sales_user_id'] ?? $user->id);
        if ($assigneeId !== (int) $user->id && ! $canAssign) {
            return back()->withInput()->with('error', 'การมอบใบจองให้ผู้ใช้อื่นต้องมีสิทธิ์กำหนดผู้รับผิดชอบ');
        }
        $assignee = User::where('is_active', true)->with(['salesman', 'posCashierProfile'])->findOrFail($assigneeId);
        if (! $assignee->canAccessBranch((int) $data['branch_id'])) {
            return back()->withInput()->with('error', 'ผู้รับผิดชอบที่เลือกไม่มีสิทธิ์ทำงานในสาขาของใบจอง');
        }

        $data['sales_user_id'] = $assignee->id;
        $data['salesman_id'] = ($assignee->salesman ?: $assignee->posCashierProfile)?->id;
        $data['sales_area_id'] = array_key_exists('sales_area_id', $data)
            ? $data['sales_area_id']
            : $assignee->sales_area_id;

        if (! empty($data['sales_area_id'])) {
            $area = SalesArea::where('is_active', true)->where('area_type', 'route')->findOrFail($data['sales_area_id']);
            if ($area->branch_id && (int) $area->branch_id !== (int) $data['branch_id']) {
                return back()->withInput()->with('error', 'สาขาในใบจองไม่ตรงกับสาขาที่ผูกไว้ในสายการขาย');
            }
            $data['document_book_id'] = $area->document_book_id;
        }

        if (! $user->canAccessBranch((int) $data['branch_id']) && ! $canAssign) {
            return back()->withInput()->with('error', 'ผู้ใช้นี้สร้างใบจองได้เฉพาะสาขาประจำของตนเอง');
        }

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bookings.show', $document->saleBooking)
            ->with('success', "สร้างใบจอง {$document->doc_number} แล้ว กันสต๊อกเรียบร้อย");
    }

    public function edit(Request $request, SaleBooking $booking): View|RedirectResponse
    {
        $transportJob = $booking->isDelivery()
            ? TransportJob::where('booking_id', $booking->id)->first()
            : null;
        if ($booking->status !== SaleBooking::STATUS_PENDING
            || $booking->delivery_status !== SaleBooking::DELIVERY_PENDING
            || ($transportJob && ($transportJob->status !== 'booked' || $transportJob->loaded_at !== null))) {
            return redirect()->route('bookings.show', $booking)
                ->with('error', 'แก้ไขได้ก่อนยืนยันขึ้นรถเท่านั้น');
        }

        $booking->load([
            'document.customer', 'document.branch', 'document.salesUser', 'document.salesArea',
            'document.stockDocument.items.product.baseUnit', 'document.stockDocument.items.product.barcodes',
        ]);
        $currentUser = $request->user();
        $branches = Branch::orderBy('code')->get();
        $salesUsers = User::where(function ($query) use ($currentUser, $booking) {
            $query->where('is_active', true)->where(function ($available) use ($currentUser, $booking) {
                $available->whereHas('roles.permissions', fn ($permission) => $permission->whereIn('code', ['sales.manage', 'sales.assign']))
                    ->orWhereHas('branchRoles.permissions', fn ($permission) => $permission->whereIn('code', ['sales.manage', 'sales.assign']));
                if ($currentUser) {
                    $available->orWhere('id', $currentUser->id);
                }
                if ($booking->sales_user_id) {
                    $available->orWhere('id', $booking->sales_user_id);
                }
            });
        })->orderBy('username')->get(['id', 'username', 'name']);
        $salesAreas = SalesArea::with('branch')
            ->where('is_active', true)->where('area_type', 'route')->orderBy('code')->get();

        $document = $booking->document;
        $initialItems = $document->stockDocument?->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'product_query' => $item->product->sku_code.' - '.$item->product->name_th,
            'qty' => (float) $item->qty,
            'unit_price' => (float) $item->unit_price,
            'unit_name' => $item->product->baseUnit?->displayLabel() ?? 'หน่วยฐาน',
            'is_scale' => $item->product->barcodes->contains(fn ($barcode) => preg_match('/^80[01][0-9]{3}$/', (string) $barcode->barcode) === 1)
                || preg_match('/ชั่ง|ซั่ง/u', (string) $item->product->name_th) === 1,
        ])->values() ?? collect();

        $initialBooking = [
            'number' => $document->doc_number,
            'date' => $document->doc_date->thaiDate(),
            'branch_id' => $document->branch_id,
            'customer_id' => $document->customer_id,
            'customer_query' => $document->customer->code.' - '.$document->customer->name_th,
            'sales_area_id' => $document->sales_area_id,
            'sales_user_id' => $document->sales_user_id,
            'sales_user_name' => $document->salesUser?->name,
            'remark' => $document->remark,
            'fulfillment_type' => $booking->fulfillment_type,
            'delivery_due_at' => $booking->delivery_due_at?->format('Y-m-d H:i:s'),
            'items' => $initialItems,
        ];

        return view('bookings.edit', compact('booking', 'branches', 'salesUsers', 'salesAreas', 'initialBooking'));
    }

    public function update(Request $request, SaleBooking $booking, BookingService $service): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'sales_area_id' => ['nullable', 'integer', 'exists:sales_areas,id'],
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'fulfillment_type' => ['nullable', 'in:pickup,delivery'],
            'delivery_due_at' => ['nullable', 'required_if:fulfillment_type,delivery', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user()->loadMissing('salesArea');
        $canAssign = $user->hasPermission('sales.assign');
        $assigneeId = (int) ($data['sales_user_id'] ?? $user->id);
        if ($assigneeId !== (int) $user->id && ! $canAssign) {
            return back()->withInput()->with('error', 'การมอบใบจองให้ผู้ใช้อื่นต้องมีสิทธิ์กำหนดผู้รับผิดชอบ');
        }
        $assignee = User::where('is_active', true)->with(['salesman', 'posCashierProfile'])->findOrFail($assigneeId);
        if (! $assignee->canAccessBranch((int) $data['branch_id'])) {
            return back()->withInput()->with('error', 'ผู้รับผิดชอบที่เลือกไม่มีสิทธิ์ทำงานในสาขาของใบจอง');
        }

        $data['sales_user_id'] = $assignee->id;
        $data['salesman_id'] = ($assignee->salesman ?: $assignee->posCashierProfile)?->id;
        $data['sales_area_id'] = array_key_exists('sales_area_id', $data)
            ? $data['sales_area_id']
            : $assignee->sales_area_id;
        if (! empty($data['sales_area_id'])) {
            $area = SalesArea::where('is_active', true)->where('area_type', 'route')->findOrFail($data['sales_area_id']);
            if ($area->branch_id && (int) $area->branch_id !== (int) $data['branch_id']) {
                return back()->withInput()->with('error', 'สาขาในใบจองไม่ตรงกับสาขาที่ผูกไว้ในสายการขาย');
            }
            $data['document_book_id'] = $area->document_book_id;
        }

        if (! $user->canAccessBranch((int) $data['branch_id']) && ! $canAssign) {
            return back()->withInput()->with('error', 'ผู้ใช้นี้แก้ไขใบจองได้เฉพาะสาขาประจำของตนเอง');
        }

        try {
            $document = $service->update($booking, $data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bookings.show', $booking)
            ->with('success', "แก้ไขใบจอง {$document->doc_number} แล้ว อัปเดตยอดจองสต๊อกเรียบร้อย");
    }

    public function show(SaleBooking $booking): View
    {
        $booking->load([
            'document.customer', 'document.branch', 'document.salesUser', 'document.salesArea',
            'document.stockDocument.items.product.baseUnit', 'document.stockDocument.items.product.barcodes', 'confirmedDocument',
        ]);

        $creditSaleBooks = DocumentBook::withCount('documents')
            ->whereHas('documentType', fn ($q) => $q->where('code', 'CREDIT_SALE'))
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get();
        $transportJob = TransportJob::where('booking_id', $booking->id)->first();

        return view('bookings.show', compact('booking', 'creditSaleBooks', 'transportJob'));
    }

    public function convert(Request $request, SaleBooking $booking, CreditSaleService $service): RedirectResponse
    {
        $data = $request->validate([
            'document_book_id' => ['nullable', 'integer', 'exists:document_books,id'],
        ]);

        $book = null;
        if (! empty($data['document_book_id'])) {
            $book = DocumentBook::whereHas('documentType', fn ($q) => $q->where('code', 'CREDIT_SALE'))
                ->where('is_active', true)
                ->findOrFail($data['document_book_id']);
        }

        try {
            $saleDocument = $service->convertBookingToCreditSale($booking, $book);
        } catch (RuntimeException $e) {
            return redirect()->route('bookings.show', $booking)->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $saleDocument)
            ->with('success', "แปลงใบจองเป็นใบขายเชื่อ {$saleDocument->doc_number} แล้ว ตัดสต๊อกและตั้งลูกหนี้เรียบร้อย");
    }

    /** บันทึกผลการส่งของ — ส่งบางส่วน ส่งครบ หรือยกเลิกการส่ง */
    public function recordDelivery(Request $request, SaleBooking $booking, BookingDeliveryService $service): RedirectResponse
    {
        $data = $request->validate([
            'delivery_status' => ['required', 'in:partial,delivered,cancelled'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->record($booking, $data['delivery_status'], $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('bookings.show', $booking)->with('error', $e->getMessage());
        }

        return redirect()->route('bookings.show', $booking)->with('success', match ($data['delivery_status']) {
            'delivered' => 'บันทึกส่งครบแล้ว',
            'partial' => 'บันทึกส่งบางส่วนแล้ว ใบจองยังค้างส่งอยู่',
            default => 'ยกเลิกการส่งของใบจองนี้แล้ว',
        });
    }
}
