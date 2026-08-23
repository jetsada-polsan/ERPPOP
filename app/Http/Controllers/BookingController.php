<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DocumentBook;
use App\Models\SaleBooking;
use App\Models\SalesArea;
use App\Models\User;
use App\Services\Sales\BookingService;
use App\Services\Sales\BookingDeliveryService;
use App\Services\Sales\CreditSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $legacyType = trim((string) $request->query('legacy_type', ''));

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
            'legacy' => 0,
        ];

        $legacyBookings = null;
        $legacyTypes = collect();
        if (($status === '' || $status === 'pending') && $this->legacyBookingsReady()) {
            $legacyTypes = $this->legacyBookingTypes();

            $legacyQuery = $this->legacyBookingQuery($q, $legacyType);
            $counts['legacy'] = (clone $legacyQuery)->count();
            $counts['all'] += $counts['legacy'];
            if ($status === 'pending') {
                $counts['pending'] += $counts['legacy'];
            }

            $legacyBookings = $legacyQuery
                ->orderByRaw('di."DI_DATE" desc')
                ->orderByRaw('di."DI_KEY" desc')
                ->paginate(50, ['*'], 'legacy_page')
                ->withQueryString();
        }

        $branches = Branch::orderBy('code')->get();
        $salesUsers = User::where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'sales.manage'))
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
            'bookings', 'legacyBookings', 'legacyTypes', 'branches', 'salesUsers', 'salesAreas',
            'documentBooks', 'bookingDefaults', 'q', 'status', 'bookId', 'branchId', 'salesAreaId', 'salesUserId', 'legacyType', 'counts'
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
        $customer = \App\Models\Customer::findOrFail($data['customer_id']);
        $canAssign = $user->hasPermission('sales.assign');

        if ($customer->sales_user_id && (int) $customer->sales_user_id !== (int) $user->id && ! $canAssign) {
            return back()->withInput()->with('error', 'ลูกค้ารายนี้มีผู้ดูแลคนอื่น กรุณาให้หัวหน้าฝ่ายขายโอนผู้ดูแลก่อน');
        }

        $data['sales_user_id'] = $canAssign && $customer->sales_user_id
            ? $customer->sales_user_id
            : $user->id;
        $data['salesman_id'] = $user->salesman_id;
        $data['sales_area_id'] = $customer->sales_area_id
            ?? $user->sales_area_id
            ?? ($data['sales_area_id'] ?? null);
        $data['claim_customer_owner'] = $customer->sales_user_id === null || $customer->sales_area_id === null;

        if (! empty($data['sales_area_id'])) {
            $area = SalesArea::where('is_active', true)->where('area_type', 'route')->findOrFail($data['sales_area_id']);
            if ($area->branch_id && (int) $area->branch_id !== (int) $data['branch_id']) {
                return back()->withInput()->with('error', 'สาขาในใบจองไม่ตรงกับสาขาที่ผูกไว้ในสายการขาย');
            }
            $data['document_book_id'] = $area->document_book_id;
        }

        if ($user->branch_id && (int) $user->branch_id !== (int) $data['branch_id'] && ! $canAssign) {
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

    public function show(SaleBooking $booking): View
    {
        $booking->load([
            'document.customer', 'document.branch', 'document.salesUser', 'document.salesArea',
            'document.stockDocument.items.product.baseUnit', 'document.stockDocument.items.product.barcodes', 'confirmedDocument',
        ]);

        $creditSaleBooks = DocumentBook::withCount('documents')
            ->whereHas('documentType', fn ($q) => $q->where('code', 'CREDIT_SALE'))
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get();

        return view('bookings.show', compact('booking', 'creditSaleBooks'));
    }

    public function legacyShow(string $diKey): View
    {
        abort_unless($this->legacyBookingsReady(), 404);

        $header = $this->legacyBookingQuery('')
            ->whereRaw('di."DI_KEY" = ?', [$diKey])
            ->first();

        abort_if($header === null, 404);

        $items = DB::table(DB::raw('legacy.dbo__transtkd as trd'))
            ->join(DB::raw('legacy.dbo__transtkh as trh'), DB::raw('trh."TRH_KEY"'), '=', DB::raw('trd."TRD_TRH"'))
            ->leftJoin(DB::raw('legacy.dbo__skumaster as sm'), DB::raw('sm."SKU_KEY"'), '=', DB::raw('trd."TRD_SKU"'))
            ->whereRaw('trh."TRH_DI" = ?', [$diKey])
            ->selectRaw('
                trd."TRD_SEQ"::int as seq,
                trd."TRD_KEYIN" as barcode,
                trd."TRD_SKU" as legacy_sku_key,
                sm."SKU_CODE" as sku_code,
                sm."SKU_NAME" as sku_name,
                trd."TRD_UTQNAME" as unit_name,
                NULLIF(trd."TRD_QTY", \'\')::numeric as qty,
                NULLIF(trd."TRD_U_PRC", \'\')::numeric as unit_price,
                NULLIF(trd."TRD_G_KEYIN", \'\')::numeric as line_total
            ')
            ->orderByRaw('trd."TRD_SEQ"::int')
            ->get();

        return view('bookings.legacy-show', compact('header', 'items'));
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

    private function legacyBookingsReady(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        foreach (['dbo__docinfo', 'dbo__doctype', 'dbo__transtkh', 'dbo__aroe', 'dbo__arfile'] as $table) {
            $exists = DB::selectOne('select to_regclass(?) as r', ['legacy.'.$table])->r ?? null;
            if ($exists === null) {
                return false;
            }
        }

        return true;
    }

    private function legacyBookingQuery(string $q, string $legacyType = '')
    {
        $query = DB::table(DB::raw('legacy.dbo__docinfo as di'))
            ->join(DB::raw('legacy.dbo__doctype as dt'), DB::raw('dt."DT_KEY"'), '=', DB::raw('di."DI_DT"'))
            ->leftJoin(DB::raw('legacy.dbo__transtkh as trh'), DB::raw('trh."TRH_DI"'), '=', DB::raw('di."DI_KEY"'))
            ->leftJoin(DB::raw('legacy.dbo__aroe as aroe'), DB::raw('aroe."AROE_DI"'), '=', DB::raw('di."DI_KEY"'))
            ->leftJoin(DB::raw('legacy.dbo__arfile as ar'), DB::raw('ar."AR_KEY"'), '=', DB::raw('aroe."AROE_AR"'))
            ->selectRaw('
                di."DI_KEY" as di_key,
                di."DI_REF" as doc_number,
                di."DI_DATE"::date as doc_date,
                NULLIF(di."DI_AMOUNT", \'\')::numeric as total_amount,
                dt."DT_DOCCODE" as legacy_type_code,
                dt."DT_THAIDESC" as legacy_type_name,
                trh."TRH_KEY" as trh_key,
                NULLIF(trh."TRH_N_ITEMS", \'\')::numeric as item_count,
                NULLIF(trh."TRH_N_QTY", \'\')::numeric as total_qty,
                ar."AR_CODE" as customer_code,
                ar."AR_NAME" as customer_name
            ');

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($where) use ($like) {
                $where->whereRaw('di."DI_REF" ilike ?', [$like])
                    ->orWhereRaw('dt."DT_DOCCODE" ilike ?', [$like])
                    ->orWhereRaw('ar."AR_CODE" ilike ?', [$like])
                    ->orWhereRaw('ar."AR_NAME" ilike ?', [$like]);
            });
        }

        if ($legacyType !== '') {
            $query->whereRaw('dt."DT_DOCCODE" = ?', [$legacyType]);
        }

        return $query;
    }

    private function legacyBookingTypes()
    {
        return DB::table(DB::raw('legacy.dbo__docinfo as di'))
            ->join(DB::raw('legacy.dbo__doctype as dt'), DB::raw('dt."DT_KEY"'), '=', DB::raw('di."DI_DT"'))
            ->selectRaw('dt."DT_DOCCODE" as code, dt."DT_THAIDESC" as name, count(*) as count')
            ->groupByRaw('dt."DT_DOCCODE", dt."DT_THAIDESC"')
            ->orderByRaw('dt."DT_DOCCODE"')
            ->get();
    }
}
