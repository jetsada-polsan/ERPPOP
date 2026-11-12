<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ProductBarcode;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferReceiptItem;
use App\Services\Inventory\StockTransferReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * ตรวจรับสินค้าโอนย้ายด้วยการสแกนบาร์โค้ดที่ปลายทาง (เสริมจาก StockTransferController
 * รับจากสถานะระหว่างทางเข้าสต๊อกปลายทาง)
 * ดู App\Services\Inventory\StockTransferReceiptService สำหรับตรรกะ/เหตุผลเชิงธุรกิจ
 */
class StockTransferReceiptController extends Controller
{
    public function create(Document $stockTransfer, StockTransferReceiptService $service): View|RedirectResponse
    {
        $this->assertTransfer($stockTransfer);

        $this->authorizeBranch($stockTransfer);
        try {
            $receipt = $service->openOrGet($stockTransfer);
        } catch (RuntimeException $e) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('error', $e->getMessage());
        }

        $this->authorizeBranch($stockTransfer);

        $items = StockTransferReceiptItem::where('stock_transfer_receipt_id', $receipt->id)
            ->join('products', 'products.id', '=', 'stock_transfer_receipt_items.product_id')
            ->leftJoin('product_units', 'product_units.id', '=', 'products.base_unit_id')
            ->orderBy('products.sku_code')
            ->get([
                'stock_transfer_receipt_items.id',
                'stock_transfer_receipt_items.expected_qty',
                'stock_transfer_receipt_items.scanned_qty',
                'products.sku_code', 'products.name_th', 'products.id as product_id',
                'product_units.name as unit_name',
            ]);

        $barcodes = ProductBarcode::whereIn('product_id', $items->pluck('product_id'))
            ->where('is_active', true)->get(['product_id', 'barcode', 'unit_factor'])
            ->groupBy('product_id');

        $products = \App\Models\Product::whereIn('id', $items->pluck('product_id')->unique())
            ->with(['baseUnit', 'barcodes.unit'])
            ->get()
            ->keyBy('id');

        $itemsJson = $items->map(function ($i) use ($barcodes, $products) {
            $product = $products->get($i->product_id);
            return [
                'id' => $i->id,
                'sku' => $i->sku_code,
                'name' => $i->name_th,
                'unit' => $i->unit_name ?? '-',
                'barcodes' => ($barcodes[$i->product_id] ?? collect())->pluck('barcode')->values()->all(),
                'pack' => (float) (($barcodes[$i->product_id] ?? collect())->max('unit_factor') ?: 1),
                'search' => mb_strtolower($i->sku_code.' '.$i->name_th.' '.($barcodes[$i->product_id] ?? collect())->pluck('barcode')->implode(' ')),
                'expected' => (float) $i->expected_qty,
                'expected_display' => $product ? \App\Support\UnitQty::format($product, $i->expected_qty) : (string) (float) $i->expected_qty,
                'scanned' => $i->scanned_qty !== null ? (float) $i->scanned_qty : null,
                'scanned_display' => $i->scanned_qty !== null
                    ? ($product ? \App\Support\UnitQty::format($product, $i->scanned_qty) : (string) (float) $i->scanned_qty)
                    : null,
            ];
        })->values();

        return view('stock-transfers.receipt', [
            'transfer' => $stockTransfer,
            'receipt' => $receipt,
            'itemsJson' => $itemsJson,
        ]);
    }

    // Bulk save scanned qty: { items: [{id, scanned}] }
    public function saveItems(Request $request, Document $stockTransfer, StockTransferReceiptService $service): JsonResponse
    {
        $this->assertTransfer($stockTransfer);
        $this->authorizeBranch($stockTransfer);
        $receipt = StockTransferReceipt::where('document_id', $stockTransfer->id)->firstOrFail();

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.scanned' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $rows = collect($data['items'])->map(fn ($r) => ['id' => $r['id'], 'scanned_qty' => $r['scanned'] ?? null])->all();
            $updated = $service->saveScans($receipt, $rows);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    public function complete(Request $request, Document $stockTransfer, StockTransferReceiptService $service): RedirectResponse
    {
        $this->assertTransfer($stockTransfer);
        $this->authorizeBranch($stockTransfer);
        $receipt = StockTransferReceipt::where('document_id', $stockTransfer->id)->firstOrFail();

        try {
            $mismatches = $service->complete($receipt, $request->input('note'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($mismatches->isEmpty()) {
            return redirect()->route('stock-transfers.show', $stockTransfer)
                ->with('success', 'ตรวจรับครบถ้วน ยอดสแกนตรงกับใบโอนย้ายทุกรายการ');
        }

        $lines = $mismatches->map(fn ($i) => "{$i->product->sku_code} (สแกนได้ {$i->scanned_qty} / ในใบ {$i->expected_qty})")->implode(' | ');

        $next = $stockTransfer->stockDocument?->transit_location_id
            ? 'ตรวจหมายเหตุใบตรวจรับและใบตัดชำรุดรออนุมัติ'
            : 'ใบโอนเก่าลงสต๊อกแล้ว หากพบส่วนต่างจริงให้สร้างใบปรับสต๊อกตามขั้นตอน';

        return redirect()->route('stock-transfers.show', $stockTransfer)
            ->with('success', "บันทึกการตรวจรับแล้ว แต่พบยอดไม่ตรง {$mismatches->count()} รายการ {$next}: {$lines}");
    }

    private function assertTransfer(Document $document): void
    {
        abort_unless($document->documentType?->code === 'STOCK_TRANSFER', 404);
    }

    // อนุญาตเฉพาะผู้มีสิทธิ์ stock.manage (ดูแลได้ทุกสาขา) หรือพนักงานสาขาปลายทางของ
    // ใบนี้เอง (คนที่ควรเป็นคนแกะของ/สแกนจริง) กันคนละสาขามายืนยันรับของแทนกัน
    private function authorizeBranch(Document $document): void
    {
        $user = auth()->user();
        if ($user?->hasGlobalPermission('stock.manage')) {
            return;
        }
        abort_unless($user && $user->canAccessBranch((int) $document->stockDocument?->toWarehouseLocation?->warehouse?->branch_id, 'stock.view'), 403, 'ตรวจรับได้เฉพาะพนักงานสาขาปลายทางของใบนี้');
    }
}
