<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Salesman;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\DocumentNumberGenerator;
use App\Support\DecimalMath;
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

            $lineTotals = [];
            foreach ($data['items'] as $item) {
                QuotationItem::create([
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                ]);
                $lineTotals[] = DecimalMath::multiply($item['qty'], $item['unit_price']);
            }
            $quotation->update([
                'total_amount' => DecimalMath::round(DecimalMath::sum($lineTotals), DecimalMath::DISPLAY_MONEY_SCALE),
            ]);

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

        // ใบที่พิมพ์ชื่อลูกค้าเอง: หาลูกค้าในทะเบียนที่ชื่อใกล้เคียงไว้ให้เลือกตอนแปลง
        $customerMatches = collect();
        if (! $quotation->customer_id && trim((string) $quotation->customer_name) !== '') {
            $customerMatches = Customer::where('is_active', true)
                ->where('name_th', 'like', '%'.trim((string) $quotation->customer_name).'%')
                ->orderBy('code')->limit(20)->get(['id', 'code', 'name_th', 'tax_id']);
        }

        return view('quotations.show', [
            'quotation' => $quotation,
            'customerMatches' => $customerMatches,
            'suggestedCustomerCode' => $this->nextCustomerCode(),
        ]);
    }

    public function updateStatus(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,accepted,expired,cancelled']]);
        $quotation->update(['status' => $data['status']]);

        return back()->with('success', 'อัปเดตสถานะใบเสนอราคาแล้ว');
    }

    /** ลูกค้าตอบรับใบเสนอราคา (ยังไม่แปลงเป็นเอกสารขาย) */
    public function accept(Quotation $quotation): RedirectResponse
    {
        if ($quotation->status !== 'open') {
            return back()->with('error', 'ใบเสนอราคานี้ไม่ได้อยู่ในสถานะรอลูกค้าตอบรับ');
        }
        if ($quotation->valid_until?->isBefore(now()->startOfDay())) {
            $quotation->update(['status' => 'expired']);

            return back()->with('error', 'ใบเสนอราคานี้หมดอายุแล้ว');
        }

        $quotation->update(['status' => 'accepted']);

        return back()->with('success', "บันทึกว่าลูกค้าตอบรับใบเสนอราคา {$quotation->doc_number} แล้ว");
    }

    /**
     * แปลงเป็นใบจอง หรือเป็นใบขายเชื่อ (ผ่านใบจองเพื่อให้เอกสารอ้างอิงกันเป็นสาย)
     *
     * ใบที่ใส่ชื่อลูกค้าแบบพิมพ์เอง ต้องเลือกลูกค้าในทะเบียน หรือสร้างลูกค้าใหม่ตรงนี้ก่อน
     */
    public function convert(Request $request, Quotation $quotation, BookingService $bookings, CreditSaleService $sales): RedirectResponse
    {
        if ($quotation->converted_booking_id) {
            return redirect()->route('bookings.show', $quotation->converted_booking_id);
        }

        $data = $request->validate([
            'target' => ['nullable', 'in:booking,sale'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'new_customer_code' => ['nullable', 'string', 'max:20', 'unique:customers,code'],
            'new_customer_name' => ['nullable', 'string', 'max:250'],
            'new_customer_tax_id' => ['nullable', 'string', 'max:20'],
        ]);
        $target = $data['target'] ?? 'booking';

        if (! in_array($quotation->status, ['open', 'accepted'], true) || $quotation->valid_until?->isBefore(now()->startOfDay())) {
            if ($quotation->status === 'open') {
                $quotation->update(['status' => 'expired']);
            }

            return back()->withErrors(['convert' => 'ใบเสนอราคานี้หมดอายุหรือถูกยกเลิกแล้ว ไม่สามารถแปลงได้']);
        }

        if (! $quotation->customer_id && empty($data['customer_id']) && trim((string) ($data['new_customer_name'] ?? '')) === '') {
            return back()->withErrors(['convert' => 'ใบเสนอราคานี้ยังไม่ได้ผูกลูกค้าในทะเบียน — เลือกลูกค้าที่มีอยู่ หรือสร้างลูกค้าใหม่ก่อนแปลง']);
        }

        $quotation->loadMissing('items');

        try {
            [$booking, $sale] = DB::transaction(function () use ($quotation, $data, $target, $bookings, $sales) {
                if (! $quotation->customer_id) {
                    $customerId = $data['customer_id'] ?? Customer::create([
                        'code' => $data['new_customer_code'] ?: $this->nextCustomerCode(),
                        'name_th' => trim($data['new_customer_name']),
                        'tax_id' => $data['new_customer_tax_id'] ?? null,
                        'branch_id' => $quotation->branch_id,
                        'is_active' => true,
                    ])->id;
                    $quotation->update(['customer_id' => $customerId]);
                }

                $bookingDocument = $bookings->create([
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
                $booking = $bookingDocument->saleBooking;

                $sale = $target === 'sale' ? $sales->convertBookingToCreditSale($booking) : null;

                $quotation->update(['status' => 'accepted', 'converted_booking_id' => $booking->id]);

                return [$booking, $sale];
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['convert' => $e->getMessage()]);
        }

        if ($sale) {
            return redirect()->route('sales.show', $sale)
                ->with('success', "แปลงใบเสนอราคา {$quotation->doc_number} เป็นใบขายเชื่อ {$sale->doc_number} แล้ว");
        }

        return redirect()->route('bookings.show', $booking)
            ->with('success', "แปลงใบเสนอราคา {$quotation->doc_number} เป็นใบจองแล้ว");
    }

    /** รหัสลูกค้าใหม่ที่ยังไม่ซ้ำ สำหรับลูกค้าที่สร้างจากใบเสนอราคา */
    private function nextCustomerCode(): string
    {
        $prefix = 'CQ'.now()->format('ymd');
        $seq = Customer::where('code', 'like', $prefix.'%')->count() + 1;
        do {
            $code = $prefix.str_pad((string) $seq++, 3, '0', STR_PAD_LEFT);
        } while (Customer::where('code', $code)->exists());

        return $code;
    }
}
