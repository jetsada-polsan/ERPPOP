<?php

namespace App\Services\PosImport;

use App\Models\ImportBatch;
use App\Models\ImportedReceipt;
use App\Models\PosReceipt;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a confirmed batch's "valid" receipts into the live tables: pos_receipts /
 * pos_receipt_items / pos_payments, plus stock_movements and stock_balances.
 * Only runs against batches in the "confirmed" status (see PosImportValidationService
 * for how a batch gets there) - never posts a batch that still has open errors, and
 * never re-posts a receipt that already has a posted_pos_receipt_id.
 */
class PosImportPostingService
{
    public function __construct(private readonly FifoStockService $fifo) {}

    public function post(ImportBatch $batch): ImportBatch
    {
        return $this->postValidReceipts($batch);
    }

    public function postValidReceipts(ImportBatch $batch, bool $allowErrors = false, bool $applyStock = true): ImportBatch
    {
        if ($batch->status !== ImportBatch::STATUS_CONFIRMED) {
            if (! $allowErrors || $batch->status !== ImportBatch::STATUS_HAS_ERROR) {
                throw new RuntimeException("Batch {$batch->id} must be confirmed before it can be posted (current status: {$batch->status}).");
            }
        }

        $warehouseLocation = $this->resolveWarehouseLocation($batch);

        DB::transaction(function () use ($batch, $warehouseLocation, $applyStock) {
            $batch->receipts()
                ->where('status', ImportedReceipt::STATUS_VALID)
                ->with(['items', 'payments'])
                ->each(function (ImportedReceipt $receipt) use ($warehouseLocation, $applyStock) {
                    $this->postReceipt($receipt, $warehouseLocation, $applyStock);
                });
        });

        $batch->update([
            'status' => $batch->errors()->exists() ? ImportBatch::STATUS_POSTED_WITH_ERRORS : ImportBatch::STATUS_POSTED,
            'posted_at' => now(),
        ]);

        return $batch->fresh();
    }

    private function resolveWarehouseLocation(ImportBatch $batch): WarehouseLocation
    {
        $location = $batch->terminal?->branch?->defaultWarehouseLocation;

        if (! $location) {
            throw new RuntimeException("Batch {$batch->id}: branch has no default_warehouse_location_id set - cannot post stock movements.");
        }

        return $location;
    }

    private function postReceipt(ImportedReceipt $receipt, WarehouseLocation $warehouseLocation, bool $applyStock): void
    {
        $posReceipt = PosReceipt::create([
            'pos_terminal_id' => $receipt->batch->pos_terminal_id,
            'receipt_no' => $receipt->receipt_no,
            'receipt_date' => $receipt->receipt_date->setTimeFromTimeString((string) ($receipt->receipt_time ?? '00:00:00')),
            'member_id' => null, // member master data not loaded yet; member_code kept in imported_receipts for later backfill
            'gross_sales' => $receipt->gross_amount,
            'discount_amount' => $receipt->discount_amount,
            'vat_amount' => $receipt->vat_amount,
            'net_sales' => $receipt->net_amount,
            'status' => 'completed',
        ]);

        $itemRows = [];
        foreach ($receipt->items as $item) {
            $itemRows[] = [
                'pos_receipt_id' => $posReceipt->id,
                'seq' => $item->line_no,
                'product_id' => $item->product_id,
                'qty' => $item->qty,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'vat_amount' => $item->vat_amount,
                'net_amount' => $item->net_amount,
            ];

            if ($applyStock && $item->product_id !== null && (float) $item->qty > 0) {
                $this->fifo->issue(
                    (int) $item->product_id,
                    (int) $warehouseLocation->id,
                    (float) $item->qty,
                    null,
                    'out',
                    $posReceipt->receipt_date->toDateString(),
                    true,
                );
            }
        }

        if (! empty($itemRows)) {
            DB::table('pos_receipt_items')->insert($itemRows);
        }

        $paymentRows = [];
        foreach ($receipt->payments as $payment) {
            $paymentRows[] = [
                'pos_receipt_id' => $posReceipt->id,
                'method' => $payment->payment_name ?? $payment->payment_code ?? 'unknown',
                'amount' => $payment->amount,
            ];
        }

        if (! empty($paymentRows)) {
            DB::table('pos_payments')->insert($paymentRows);
        }

        $receipt->update([
            'status' => ImportedReceipt::STATUS_POSTED,
            'posted_pos_receipt_id' => $posReceipt->id,
        ]);
    }

    /** @param array<int, float> $stockDeltas product_id => sold qty */
    private function applyStockDeltas(WarehouseLocation $warehouseLocation, array $stockDeltas): void
    {
        if ($stockDeltas === []) {
            return;
        }

        $productIds = array_keys($stockDeltas);
        $existingIds = DB::table('stock_balances')
            ->where('warehouse_location_id', $warehouseLocation->id)
            ->whereIn('product_id', $productIds)
            ->pluck('id', 'product_id')
            ->all();

        $missingRows = [];
        foreach ($stockDeltas as $productId => $qty) {
            if (! isset($existingIds[$productId])) {
                $missingRows[] = [
                    'product_id' => $productId,
                    'warehouse_location_id' => $warehouseLocation->id,
                    'on_hand_qty' => 0,
                    'reserved_qty' => 0,
                    'updated_at' => now(),
                ];
            }
        }

        if ($missingRows !== []) {
            DB::table('stock_balances')->insert($missingRows);
        }

        foreach ($stockDeltas as $productId => $qty) {
            DB::table('stock_balances')
                ->where('warehouse_location_id', $warehouseLocation->id)
                ->where('product_id', $productId)
                ->update([
                    'on_hand_qty' => DB::raw('on_hand_qty - '.((float) $qty)),
                    'updated_at' => now(),
                ]);
        }
    }
}
