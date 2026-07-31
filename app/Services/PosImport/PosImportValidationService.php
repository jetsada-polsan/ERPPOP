<?php

namespace App\Services\PosImport;

use App\Models\ImportBatch;
use App\Models\ImportError;
use App\Models\ImportedReceipt;
use App\Models\ProductBarcode;
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
    // BPlus stores the customer charge rounded to the nearest baht/half-baht,
    // while line amounts retain satang precision.
    private const AMOUNT_TOLERANCE = 0.50;

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
            $postingItems = $receipts
                ->flatMap(fn ($receipt) => $receipt->items)
                ->filter(fn ($item) => PosImportLineNormalizer::isPostingLine($item->raw_data ?? []));

            $skuCodes = $postingItems
                ->pluck('sku_code')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $barcodeValues = $postingItems
                ->flatMap(fn ($item) => [$item->barcode, $item->product_code, $item->sku_code])
                ->filter()
                ->unique()
                ->values()
                ->all();

            $productsBySku = Product::whereIn('sku_code', $skuCodes)->get()->keyBy('sku_code');
            $productsByBarcode = ProductBarcode::whereIn('barcode', $barcodeValues)
                ->where('is_active', true)
                ->with('product')
                ->get()
                ->filter(fn (ProductBarcode $barcode) => $barcode->product !== null && $barcode->product->is_active)
                ->mapWithKeys(fn (ProductBarcode $barcode) => [$barcode->barcode => $barcode->product]);

            foreach ($receipts as $receipt) {
                $this->validateReceipt($batch, $receipt, $hasWarehouse, $productsBySku, $productsByBarcode);
            }
        });

        $hasErrors = $batch->errors()->exists();

        $batch->update([
            'validated_at' => now(),
            'status' => $hasErrors ? ImportBatch::STATUS_HAS_ERROR : ImportBatch::STATUS_VALIDATED,
        ]);

        return $batch->fresh();
    }

    private function validateReceipt(ImportBatch $batch, ImportedReceipt $receipt, bool $hasWarehouse, $productsBySku, $productsByBarcode): void
    {
        // Posted receipts are immutable import history. Keep them out of a
        // re-validation pass so a later retry cannot insert a duplicate POS bill.
        if ($receipt->posted_pos_receipt_id !== null || $receipt->status === ImportedReceipt::STATUS_POSTED) {
            if ($receipt->posted_pos_receipt_id !== null && $receipt->status !== ImportedReceipt::STATUS_POSTED) {
                $receipt->update(['status' => ImportedReceipt::STATUS_POSTED]);
            }

            return;
        }

        // The legacy POS daily-sales report includes only completed sales
        // (PSH_TYPE=1, PSH_STATUS=0). Keep any old staged cancel/close snapshot
        // out of a future post, even when a previous import included it.
        $legacyType = data_get($receipt->raw_data, 'PSH_TYPE');
        $legacyStatus = data_get($receipt->raw_data, 'PSH_STATUS');
        if (($legacyType !== null && (string) $legacyType !== '1')
            || ($legacyStatus !== null && (string) $legacyStatus !== '0')) {
            $receipt->update(['status' => 'voided']);

            return;
        }

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
        $postingItems = $receipt->items->filter(fn ($item) => PosImportLineNormalizer::isPostingLine($item->raw_data ?? []));
        foreach ($receipt->items as $item) {
            if (! PosImportLineNormalizer::isPostingLine($item->raw_data ?? [])) {
                $item->update(['product_id' => null, 'mapping_status' => 'ignored', 'net_amount' => 0]);

                continue;
            }

            $normalisedAmount = PosImportLineNormalizer::amount($item->raw_data ?? []);
            $product = $item->sku_code !== null ? $productsBySku->get($item->sku_code) : null;
            $product ??= collect([$item->barcode, $item->product_code, $item->sku_code])
                ->filter()
                ->map(fn ($code) => $productsByBarcode->get($code))
                ->first();

            if ($product) {
                if ($item->product_id !== $product->id || $item->mapping_status !== 'mapped' || (float) $item->net_amount !== $normalisedAmount) {
                    $item->update([
                        'product_id' => $product->id,
                        'mapping_status' => 'mapped',
                        'net_amount' => $normalisedAmount,
                    ]);
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
        $itemsTotal = round((float) $postingItems->sum(fn ($item) => PosImportLineNormalizer::amount($item->raw_data ?? [])), 2);
        $headerNet = round((float) $receipt->net_amount, 2);
        if ($postingItems->isNotEmpty() && abs($itemsTotal - $headerNet) > self::AMOUNT_TOLERANCE) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::AMOUNT_NOT_MATCH,
                "Header net_amount ({$headerNet}) does not match sum of item net_amount ({$itemsTotal}).");
        }

        // 5. Payments received cover the header net_amount (change is stored negative).
        $paymentsTotal = round((float) $receipt->payments->sum('amount'), 2);
        if ($receipt->payments->isNotEmpty() && abs($paymentsTotal - $headerNet) > self::AMOUNT_TOLERANCE) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::PAYMENT_NOT_MATCH,
                "Header net_amount ({$headerNet}) does not match sum of payments ({$paymentsTotal}).");
        } elseif ($receipt->payments->isEmpty() && $headerNet > 0 && ! $this->isLegacyUnpaidSnapshot($receipt)) {
            $receiptErrors[] = $this->logError($batch, $receipt, ImportError::PAYMENT_NOT_MATCH,
                "Receipt has a net amount of {$headerNet} but no payment lines.");
        }

        $receipt->update(['status' => $receiptErrors === [] ? ImportedReceipt::STATUS_VALID : ImportedReceipt::STATUS_ERROR]);
    }

    private function isLegacyUnpaidSnapshot(ImportedReceipt $receipt): bool
    {
        // Some completed BPlus POS snapshots have PSH_STATUS=2 and no PSP rows.
        // Keep the sale in ERP with an empty payment allocation for finance review.
        return (string) data_get($receipt->raw_data, 'PSH_STATUS') === '2';
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
