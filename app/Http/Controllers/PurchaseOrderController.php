<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $orders = PurchaseOrder::with(['supplier', 'branch', 'requester'])->withCount('items')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name_th', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        $statusCounts = PurchaseOrder::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return view('purchase-orders.index', [
            'orders' => $orders,
            'status' => $status,
            'q' => $q,
            'statusCounts' => $statusCounts,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code', 'name_th']),
        ]);
    }

    // สร้างใบขอซื้อ (requisition) - ยังไม่ต้องมีซัพพลายเออร์/ราคา
    public function store(Request $request, DocumentNumberGenerator $numbers): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'need_by_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'items.required' => 'กรุณาเพิ่มรายการสินค้าที่ขอซื้ออย่างน้อย 1 รายการ',
        ]);

        $order = DB::transaction(function () use ($data, $numbers) {
            $order = PurchaseOrder::create([
                'doc_number' => $numbers->nextPurchaseOrder($data['branch_id']),
                'branch_id' => $data['branch_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'doc_date' => now()->toDateString(),
                'need_by_date' => $data['need_by_date'] ?? null,
                'status' => 'requested',
                'requested_by' => Auth::id(),
                'note' => $data['note'] ?? null,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                $price = (float) ($item['unit_price'] ?? 0);
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'unit_price' => $price,
                ]);
                $total += (float) $item['qty'] * $price;
            }
            $order->update(['total_amount' => round($total, 2)]);

            return $order;
        });

        return redirect()->route('purchase-orders.show', $order)
            ->with('success', "สร้างใบขอซื้อ {$order->doc_number} แล้ว");
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['supplier', 'branch', 'requester', 'approver', 'receivedDocument', 'items.product.baseUnit']);
        $suppliers = Supplier::where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code', 'name_th']);

        return view('purchase-orders.show', compact('purchaseOrder', 'suppliers'));
    }

    // ใบสั่งซื้อแบบพิมพ์ A4 หัวบริษัทครบ (ส่งให้ผู้ขาย)
    public function print(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['supplier', 'branch', 'requester', 'approver', 'items.product.baseUnit']);

        return view('purchase-orders.print', compact('purchaseOrder'));
    }

    public function approve(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($purchaseOrder->status === 'requested', 422, 'สถานะไม่ถูกต้อง');
        abort_if($purchaseOrder->requested_by === Auth::id(), 403, 'ผู้ขอซื้อไม่สามารถอนุมัติรายการของตนเอง');
        $purchaseOrder->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);

        return back()->with('success', "อนุมัติใบขอซื้อ {$purchaseOrder->doc_number} แล้ว");
    }

    // ยืนยันสั่งซื้อ: ต้องมีซัพพลายเออร์และราคาครบ
    public function order(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($purchaseOrder->status === 'approved', 422, 'ต้องอนุมัติก่อนสั่งซื้อ');

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'is_credit' => ['nullable', 'boolean'],
            'unit_price' => ['required', 'array'],
            'unit_price.*' => ['required', 'numeric', 'min:0'],
        ], [
            'supplier_id.required' => 'กรุณาเลือกซัพพลายเออร์ก่อนสั่งซื้อ',
        ]);

        DB::transaction(function () use ($purchaseOrder, $data, $request) {
            $total = 0;
            foreach ($purchaseOrder->items as $item) {
                $price = (float) ($data['unit_price'][$item->id] ?? $item->unit_price);
                $item->update(['unit_price' => $price]);
                $total += (float) $item->qty * $price;
            }
            $purchaseOrder->update([
                'status' => 'ordered',
                'supplier_id' => $data['supplier_id'],
                'is_credit' => $request->boolean('is_credit', true),
                'total_amount' => round($total, 2),
            ]);
        });

        return back()->with('success', "ยืนยันสั่งซื้อ {$purchaseOrder->doc_number} แล้ว");
    }

    // รับของ -> สร้างใบซื้อจริง (ตัดสต๊อก+ตั้งหนี้+GL) ผ่าน PurchaseService
    public function receive(Request $request, PurchaseOrder $purchaseOrder, PurchaseService $service): RedirectResponse
    {
        abort_unless($purchaseOrder->status === 'ordered', 422, 'ต้องสั่งซื้อก่อนรับของ');
        $purchaseOrder->loadMissing('items.product');
        $lots = $request->validate([
            'lots' => ['nullable', 'array'],
            'lots.*.lot_number' => ['nullable', 'string', 'max:80'],
            'lots.*.manufacture_date' => ['nullable', 'date'],
            'lots.*.expiry_date' => ['nullable', 'date'],
        ])['lots'] ?? [];

        try {
            $document = $service->create([
                'supplier_id' => $purchaseOrder->supplier_id,
                'branch_id' => $purchaseOrder->branch_id,
                'is_credit' => $purchaseOrder->is_credit,
                'remark' => 'รับของตามใบสั่งซื้อ '.$purchaseOrder->doc_number,
                'items' => $purchaseOrder->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'qty' => (float) $i->qty,
                    'unit_price' => (float) $i->unit_price,
                    'lot_number' => $lots[$i->id]['lot_number'] ?? null,
                    'manufacture_date' => $lots[$i->id]['manufacture_date'] ?? null,
                    'expiry_date' => $lots[$i->id]['expiry_date'] ?? null,
                ])->all(),
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['receive' => $e->getMessage()]);
        }

        $purchaseOrder->update(['status' => 'received', 'received_document_id' => $document->id]);

        return redirect()->route('purchases.show', $document)
            ->with('success', "รับของตามใบสั่งซื้อ {$purchaseOrder->doc_number} แล้ว → ใบซื้อ {$document->doc_number}");
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_if(in_array($purchaseOrder->status, ['received', 'cancelled'], true), 422, 'ยกเลิกไม่ได้');
        $purchaseOrder->update(['status' => 'cancelled']);

        return back()->with('success', "ยกเลิกใบขอซื้อ {$purchaseOrder->doc_number} แล้ว");
    }
}
