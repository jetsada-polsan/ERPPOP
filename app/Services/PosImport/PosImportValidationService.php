<?php

namespace App\Services\PosImport;

use App\Models\ImportBatch;
use App\Models\ImportError;
use App\Models\ImportedReceipt;
use App\Models\PosTerminal;
use App\Models\Product;

/**
 * Runs the pre-posting checks against a staged batch. Nothing here writes to
 * pos_receipts/stock - it only sets imported_receipts.status (valid/error/voided)
 * and writes import_errors rows. A batch can only move to "confirmed" once every
 * non-voided receipt in it is "valid" (see PosImportPostingService).
 */
class PosImportValidationService
{
    private const AMOUNT_TOLERANCE = 0.01;

    public function validate(ImportBatch $batch): ImportBatch
    {
        if ($batch->pos_terminal_id === null) {
            $terminal = PosTerminal::where('code', $batch->pos_code)->first();
            if ($terminal) {
                $batch->update(['pos_terminal_id' => $terminal->id]);
            }
        }

        $branch = $batch->terminal?->branch;
        $hasWarehouse = $branch !== null && $branch->default_warehouse_location_id !== null;

        $batch->errors()->delete();

        $batch->receipts()->with(['items', 'payments'])->chunk(50, function ($receipts) use ($batch, $hasWarehouse) {
            $skuCodes = $receipts
                ->flatMap(fn ($receipt) => $receipt->items->pluck('sku_code'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $productsBySku = Product::whereIn('sku_code', $skuCodes)->get()->keyBy('sku_code');

            foreach ($receipts as $receipt) {
                $this->validateReceipt($batch, $receipt, $hasWarehouse, $productsBySku);
            }
        });

        $hasErrors = $batch->errors()->exists();

        $batch->update([
            'validated_at' => now(),
            'status' => $hasErrors ? ImportBatch::STATUS_HAS_ERROR : ImportBatch::STATUS_VALIDATED,
        ]);

        return $batch->fresh();
    }

    private function validateReceipt(ImportBatch $batch, ImportedReceipt $receipt, bool $hasWarehouse, $productsBySku): void
    {
        // Empty void/cancelled transactions (no items, no amount) carry no business
        // value to post - exclude them from the pipeline instead of flagging errors.
        if ($receipt->item_count === 0 && (float) $receipt->net_amount === 0.0 && $receipt->items->isEmpty()) {
            $receipt->update(['status' => 'voided']);

            return;
        }

        $receiptErrors = [];

        // 1. Duplicate receipt already posted under a different batch.
        $duplicate = ImportedReceipt::where('pos_code', $receipt->pos_code)
            ->where('receipt_no', $receipt->receipt_no)
            ->where('receipt_date', $receipt->receipt_date)
            ->where('id', '!=', $receipt->id)
            ->where('status', ImportedReceipt::STATUS_POSTED)
            ->exists();

        if ($duplicate) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::DUPLICATE_RECEIPT,
                "Receipt {$receipt->receipt_no} on {$receipt->receipt_date} is already posted under a different batch.");
        }

        // 2. POS terminal resolves to a branch with at least one warehouse.
        if (! $hasWarehouse) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::WAREHOUSE_NOT_FOUND,
                "POS terminal '{$receipt->pos_code}' has no branch/warehouse configured to post stock movements into.");
        }

        // 3. Every line item maps to a known product.
        foreach ($receipt->items as $item) {
            $product = $item->sku_code !== null ? $productsBySku->get($item->sku_code) : null;

            if ($product) {
                if ($item->product_id !== $product->id || $item->mapping_status !== 'mapped') {
                    $item->update(['product_id' => $product->id, 'mapping_status' => 'mapped']);
                }
            } else {
                $item->update(['mapping_status' => 'not_found']);
                $receiptErrors[] = $this->logError(
                    $batch, $receipt, ImportError::PRODUCT_NOT_FOUND,
                    "Line {$item->line_no}: SKU '{$item->sku_code}' (legacy PSD_KEY {$item->legacy_psd_key}) not found in products.",
                    $item->line_no
                );
            }
        }

        // 4. Header net_amount matches the sum of line items.
        $itemsTotal = round((float) $receipt->items->sum('net_amount'), 2);
        $headerNet = round((float) $receipt->net_amount, 2);
        if ($receipt->items->isNotEmpty() && abs($itemsTotal - $headerNet) > self::AMOUNT_TOLERANCE) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::AMOUNT_NOT_MATCH,
                "Header net_amount ({$headerNet}) does not match sum of item net_amount ({$itemsTotal}).");
        }

        // 5. Payments received cover the header net_amount (change is stored negative).
        $paymentsTotal = round((float) $receipt->payments->sum('amount'), 2);
        if ($receipt->payments->isNotEmpty() && abs($paymentsTotal - $headerNet) > self::AMOUNT_TOLERANCE) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::PAYMENT_NOT_MATCH,
                "Header net_amount ({$headerNet}) does not match sum of payments ({$paymentsTotal}).");
        } elseif ($receipt->payments->isEmpty() && $headerNet > 0) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::PAYMENT_NOT_MATCH,
                "Receipt has a net amount of {$headerNet} but no payment lines.");
        }

        $receipt->update(['status' => $receiptErrors === [] ? ImportedReceipt::STATUS_VALID : ImportedReceipt::STATUS_ERROR]);
    }

    private function logError(ImportBatch $batch, ImportedReceipt $receipt, string $type, string $message, ?int $lineNo = null): ImportError
    {
        return ImportError::create([
            'batch_id' => $batch->id,
            'receipt_no' => $receipt->receipt_no,
            'line_no' => $lineNo,
            'error_type' => $type,
            'error_message' => $message,
            'raw_data' => ['receipt_id' => $receipt->id, 'legacy_psh_key' => $receipt->legacy_psh_key],
        ]);
    }
}
