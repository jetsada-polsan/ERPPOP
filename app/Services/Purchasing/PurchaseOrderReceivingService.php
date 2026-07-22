<?php

namespace App\Services\Purchasing;

use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseOrderReceivingService
{
    public function __construct(private readonly PurchaseService $purchases) {}

    /**
     * @param  array<int, float|int|string|null>  $quantities  Purchase-order item ID => quantity received now
     * @param  array<int, array<string, mixed>>  $lots  Purchase-order item ID => lot attributes
     */
    public function receive(PurchaseOrder $purchaseOrder, array $quantities, array $lots = [], ?int $receivedBy = null, string $remarkSuffix = ''): Document
    {
        return DB::transaction(function () use ($purchaseOrder, $quantities, $lots, $receivedBy, $remarkSuffix) {
            $locked = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['ordered', 'partially_received'], true)) {
                throw new RuntimeException('ใบสั่งซื้อนี้รับครบหรือสถานะเปลี่ยนแล้ว');
            }

            $items = PurchaseOrderItem::where('purchase_order_id', $locked->id)
                ->with('product')->orderBy('id')->lockForUpdate()->get();
            $receiving = $items->map(function ($item) use ($quantities, $lots) {
                $qty = (float) ($quantities[$item->id] ?? 0);
                $outstanding = round((float) $item->qty - (float) $item->received_qty, 4);
                if ($qty > $outstanding + 0.0001) {
                    throw new RuntimeException("รับ {$item->product->name_th} เกินยอดค้างรับ");
                }
                if ($qty <= 0.0001) {
                    return null;
                }

                return [
                    'purchase_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'qty' => $qty,
                    'unit_price' => (float) $item->unit_price,
                    'lot_number' => $lots[$item->id]['lot_number'] ?? null,
                    'manufacture_date' => $lots[$item->id]['manufacture_date'] ?? null,
                    'expiry_date' => $lots[$item->id]['expiry_date'] ?? null,
                ];
            })->filter()->values();
            if ($receiving->isEmpty()) {
                throw new RuntimeException('กรุณาระบุจำนวนรับอย่างน้อย 1 รายการ');
            }

            $document = $this->purchases->create([
                'supplier_id' => $locked->supplier_id,
                'branch_id' => $locked->branch_id,
                'is_credit' => $locked->is_credit,
                'remark' => trim('รับของตามใบสั่งซื้อ '.$locked->doc_number.' '.$remarkSuffix),
                'items' => $receiving->map(fn ($item) => collect($item)->except('purchase_order_item_id')->all())->all(),
            ]);

            foreach ($receiving as $received) {
                PurchaseOrderItem::whereKey($received['purchase_order_item_id'])->increment('received_qty', $received['qty']);
            }
            PurchaseOrderReceipt::create([
                'purchase_order_id' => $locked->id,
                'document_id' => $document->id,
                'received_by' => $receivedBy,
                'received_at' => now(),
            ]);
            $hasOutstanding = PurchaseOrderItem::where('purchase_order_id', $locked->id)
                ->whereColumn('received_qty', '<', 'qty')->exists();
            $locked->update([
                'status' => $hasOutstanding ? 'partially_received' : 'received',
                'received_document_id' => $locked->received_document_id ?: $document->id,
            ]);

            return $document;
        });
    }
}
