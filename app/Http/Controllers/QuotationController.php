<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Salesman;
use App\Services\Sales\BookingService;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class QuotationController extends Controller
{
    public function index(Request $request): View
    {
        Quotation::where('status', 'open')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $quotations = Quotation::with(['customer', 'branch', 'salesman'])->withCount('items')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhere('customer_name', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('quotations.index', [
            'quotations' => $quotations,
            'status' => $status,
            'q' => $q,
            'customers' => Customer::where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code', 'name_th']),
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'salesmen' => Salesman::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, DocumentNumberGenerator $numbers): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:250'],
            'salesman_id' => ['nullable', 'integer', 'exists:salesmen,id'],
            'valid_until' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ], [
            'items.required' => 'กรุณาเพิ่มรายการสินค้าที่เสนอราคาอย่างน้อย 1 รายการ',
        ]);

        $quotation = DB::transaction(function () use ($data, $numbers) {
            $quotation = Quotation::create([
                'doc_number' => $numbers->nextQuotation($data['branch_id']),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'branch_id' => $data['branch_id'],
                'salesman_id' => $data['salesman_id'] ?? null,
                'doc_date' => now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? now()->addDays(15)->toDateString(),
                'status' => 'open',
                'note' => $data['note'] ?? null,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                QuotationItem::create([
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                ]);
                $total += (float) $item['qty'] * (float) $item['unit_price'];
            }
            $quotation->update(['total_amount' => round($total, 2)]);

            return $quotation;
        });

        return redirect()->route('quotations.show', $quotation)
            ->with('success', "สร้างใบเสนอราคา {$quotation->doc_number} แล้ว");
    }

    public function show(Quotation $quotation): View
    {
        if ($quotation->status === 'open' && $quotation->valid_until?->isBefore(now()->startOfDay())) {
            $quotation->update(['status' => 'expired']);
        }
        $quotation->load(['customer.addresses', 'branch', 'salesman', 'items.product.baseUnit']);

        return view('quotations.show', ['quotation' => $quotation]);
    }

    public function updateStatus(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,accepted,expired,cancelled']]);
        $quotation->update(['status' => $data['status']]);

        return back()->with('success', 'อัปเดตสถานะใบเสนอราคาแล้ว');
    }

    // แปลงเป็นใบจอง (ต้องมีลูกค้าที่ลงทะเบียนแล้ว)
    public function convert(Quotation $quotation, BookingService $service): RedirectResponse
    {
        if ($quotation->converted_booking_id) {
            return redirect()->route('bookings.show', $quotation->converted_booking_id);
        }
        if (! $quotation->customer_id) {
            return back()->withErrors(['convert' => 'ต้องเลือกลูกค้าที่ลงทะเบียนแล้วก่อนแปลงเป็นใบจอง']);
        }
        if ($quotation->status === 'cancelled' || $quotation->valid_until?->isBefore(now()->startOfDay())) {
            if ($quotation->status !== 'cancelled') {
                $quotation->update(['status' => 'expired']);
            }
            return back()->withErrors(['convert' => 'ใบเสนอราคานี้หมดอายุหรือถูกยกเลิกแล้ว ไม่สามารถแปลงเป็นใบจองได้']);
        }

        $quotation->loadMissing('items');

        try {
            $booking = $service->create([
                'customer_id' => $quotation->customer_id,
                'branch_id' => $quotation->branch_id,
                'salesman_id' => $quotation->salesman_id,
                'remark' => 'จากใบเสนอราคา '.$quotation->doc_number,
                'items' => $quotation->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'qty' => (float) $i->qty,
                    'unit_price' => (float) $i->unit_price,
                ])->all(),
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['convert' => $e->getMessage()]);
        }

        // booking document -> หา sale_booking id เพื่อลิงก์หน้า booking
        $bookingId = $booking->saleBooking?->id ?? $booking->id;
        $quotation->update(['status' => 'accepted', 'converted_booking_id' => $bookingId]);

        return redirect()->route('bookings.show', $bookingId)
            ->with('success', "แปลงใบเสนอราคา {$quotation->doc_number} เป็นใบจองแล้ว");
    }
}
