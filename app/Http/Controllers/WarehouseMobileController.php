<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * คลังมือถือ: หน้าจอเดียวสำหรับงานคลังบนมือถือ/PDA (ยิงบาร์โค้ดเป็นหลัก)
 * - รับเข้า: สแกนสะสมรายการ → ออก "ใบซื้อ" ผ่าน PurchaseService (สต๊อก FIFO + เจ้าหนี้ + GL ครบ)
 * - รับตาม PO: ยิงเช็คของกับใบสั่งซื้อสถานะ ordered แล้วรับทั้งใบ (flow เดียวกับปุ่มรับของหน้า desktop)
 * - เช็คสต๊อก: สแกนดูยอดคงเหลือรายคลัง
 */
class WarehouseMobileController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $lockedBranchId = $user?->branchScopeId();

        $branches = Branch::query()
            ->when($lockedBranchId, fn ($q) => $q->whereKey($lockedBranchId))
            ->orderBy('id')
            ->get(['id', 'name_th', 'default_warehouse_location_id']);

        return view('wh.index', [
            'branches' => $branches,
            'lockedBranchId' => $lockedBranchId,
            // แท็บรับเข้า/รับ PO สร้างใบซื้อ = สิทธิ์จัดซื้อ; เช็คสต๊อกใช้สิทธิ์คลังของหน้า
            'canReceive' => (bool) $user?->hasPermission('purchasing.manage'),
        ]);
    }

    /** สแกนบาร์โค้ด/รหัสสินค้า → สินค้า + หน่วยของบาร์โค้ด + ยอดคงเหลือคลังสาขา */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $code = trim($data['code']);

        $product = null;
        $factor = 1.0;
        $unitLabel = null;

        $barcode = ProductBarcode::with(['product.baseUnit', 'unit'])
            ->where('barcode', $code)->where('is_active', true)->first();
        if ($barcode?->product) {
            $product = $barcode->product;
            $factor = (float) ($barcode->unit_factor ?: 1);
            $unitLabel = $barcode->unit?->displayLabel();
        } else {
            $product = Product::with('baseUnit')
                ->where('sku_code', $code)->where('is_active', true)->first();
        }

        if (! $product) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'product' => $this->productPayload($product, $factor, $unitLabel),
            'on_hand' => $this->onHandAtBranch($product->id, $request->integer('branch_id') ?: null),
        ]);
    }

    /** เลือกจากผลค้นหาชื่อ (search.products) → รายละเอียดเท่ากับ lookup */
    public function productDetail(Request $request, Product $product): JsonResponse
    {
        $product->loadMissing('baseUnit');

        return response()->json([
            'found' => true,
            'product' => $this->productPayload($product, 1.0, null),
            'on_hand' => $this->onHandAtBranch($product->id, $request->integer('branch_id') ?: null),
        ]);
    }

    /** ยอดคงเหลือของสินค้า แยกตามคลัง/ตำแหน่งเก็บ */
    public function stock(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);

        $rows = StockBalance::query()
            ->join('warehouse_locations', 'warehouse_locations.id', '=', 'stock_balances.warehouse_location_id')
            ->where('stock_balances.product_id', $data['product_id'])
            ->orderBy('warehouse_locations.id')
            ->get([
                'warehouse_locations.id as location_id',
                'warehouse_locations.name as location_name',
                'stock_balances.on_hand_qty',
            ]);

        return response()->json([
            'locations' => $rows,
            'total' => (float) $rows->sum('on_hand_qty'),
        ]);
    }

    /** บันทึกรับเข้าอิสระ → ใบซื้อ (PURCHASE) */
    public function receiveStore(Request $request, PurchaseService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'is_credit' => ['required', 'boolean'],
            'remark' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'items.*.lot_number' => ['nullable', 'string', 'max:64'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ]);

        // user ที่ผูกสาขา: บังคับรับเข้าสาขาตัวเองเสมอ
        $lockedBranchId = auth()->user()?->branchScopeId();
        $branchId = $lockedBranchId ?: (int) $data['branch_id'];

        try {
            $document = $service->create([
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => $branchId,
                'is_credit' => (bool) $data['is_credit'],
                'remark' => trim('รับเข้าผ่านคลังมือถือ '.($data['remark'] ?? '')),
                'items' => array_map(fn ($item) => [
                    'product_id' => (int) $item['product_id'],
                    'qty' => (float) $item['qty'],
                    'unit_price' => (float) $item['unit_price'],
                    'lot_number' => $item['lot_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                ], $data['items']),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'doc_number' => $document->doc_number,
            'document_id' => $document->id,
            'total_amount' => (float) $document->total_amount,
        ]);
    }

    /** ใบสั่งซื้อที่รอรับของ (status=ordered) ของสาขา */
    public function purchaseOrders(Request $request): JsonResponse
    {
        $lockedBranchId = auth()->user()?->branchScopeId();
        $branchId = $lockedBranchId ?: ($request->integer('branch_id') ?: null);

        $pos = PurchaseOrder::query()
            ->with('supplier:id,name_th')
            ->withCount('items')
            ->where('status', 'ordered')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json($pos->map(fn ($po) => [
            'id' => $po->id,
            'doc_number' => $po->doc_number,
            'doc_date' => $po->doc_date?->toDateString(),
            'supplier' => $po->supplier?->name_th,
            'is_credit' => (bool) $po->is_credit,
            'item_count' => $po->items_count,
            'total_amount' => (float) $po->total_amount,
        ]));
    }

    /** รายการสินค้าในใบสั่งซื้อ (สำหรับยิงเช็คของ) */
    public function purchaseOrderDetail(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->guardPoBranch($purchaseOrder);
        $purchaseOrder->load(['supplier:id,name_th', 'items.product:id,sku_code,name_th']);

        return response()->json([
            'id' => $purchaseOrder->id,
            'doc_number' => $purchaseOrder->doc_number,
            'supplier' => $purchaseOrder->supplier?->name_th,
            'is_credit' => (bool) $purchaseOrder->is_credit,
            'status' => $purchaseOrder->status,
            'items' => $purchaseOrder->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'sku_code' => $i->product?->sku_code,
                'name_th' => $i->product?->name_th,
                'qty' => (float) $i->qty,
                'unit_price' => (float) $i->unit_price,
            ]),
        ]);
    }

    /** รับของทั้งใบตาม PO (logic เดียวกับ PurchaseOrderController::receive แต่ตอบ JSON) */
    public function purchaseOrderReceive(PurchaseOrder $purchaseOrder, PurchaseService $service): JsonResponse
    {
        $this->guardPoBranch($purchaseOrder);
        if ($purchaseOrder->status !== 'ordered') {
            return response()->json(['message' => 'ต้องสั่งซื้อก่อนรับของ'], 422);
        }
        $purchaseOrder->loadMissing('items');

        try {
            $document = $service->create([
                'supplier_id' => $purchaseOrder->supplier_id,
                'branch_id' => $purchaseOrder->branch_id,
                'is_credit' => $purchaseOrder->is_credit,
                'remark' => 'รับของตามใบสั่งซื้อ '.$purchaseOrder->doc_number.' (คลังมือถือ)',
                'items' => $purchaseOrder->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'qty' => (float) $i->qty,
                    'unit_price' => (float) $i->unit_price,
                ])->all(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $purchaseOrder->update(['status' => 'received', 'received_document_id' => $document->id]);

        return response()->json([
            'ok' => true,
            'doc_number' => $document->doc_number,
            'po_number' => $purchaseOrder->doc_number,
        ]);
    }

    private function guardPoBranch(PurchaseOrder $purchaseOrder): void
    {
        $lockedBranchId = auth()->user()?->branchScopeId();
        abort_if($lockedBranchId && $purchaseOrder->branch_id !== $lockedBranchId, 403, 'ใบสั่งซื้อไม่ใช่ของสาขาคุณ');
    }

    /** @return array<string, mixed> */
    private function productPayload(Product $product, float $factor, ?string $unitLabel): array
    {
        $cost = (float) ($product->average_cost ?? 0);

        return [
            'id' => $product->id,
            'sku_code' => $product->sku_code,
            'name_th' => $product->name_th,
            'tracks_expiry' => (bool) $product->tracks_expiry,
            'unit_label' => $unitLabel ?: $product->baseUnit?->displayLabel() ?: 'หน่วย',
            'unit_factor' => $factor,
            'base_unit_label' => $product->baseUnit?->displayLabel() ?: 'หน่วย',
            // ราคาตั้งต้นตอนรับเข้า: ต้นทุนเฉลี่ยถ้ามี ไม่งั้นราคาขายตั้งต้น (แก้ในฟอร์มได้)
            'default_cost' => $cost > 0 ? $cost : (float) ($product->default_price ?? 0),
        ];
    }

    private function onHandAtBranch(int $productId, ?int $branchId): ?float
    {
        if (! $branchId) {
            return null;
        }
        $locationId = Branch::whereKey($branchId)->value('default_warehouse_location_id');
        if (! $locationId) {
            return null;
        }

        return (float) (StockBalance::where('product_id', $productId)
            ->where('warehouse_location_id', $locationId)
            ->value('on_hand_qty') ?? 0);
    }
}
