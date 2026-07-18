<?php

namespace App\Services\PosImport;

use App\Models\ImportBatch;
use App\Models\ImportedPayment;
use App\Models\ImportedReceipt;
use App\Models\ImportedReceiptItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pulls one POS terminal's one sale-date worth of receipts/items/payments from the
 * legacy MSSQL source (via MssqlPosSourceService) and stages them into the
 * import_batches / imported_receipts / imported_receipt_items / imported_payments
 * tables, status = "pending". Nothing here touches pos_receipts, stock, or any other
 * live table - that only happens after validation + explicit confirm (see
 * PosImportValidationService / PosImportPostingService).
 *
 * Safe to re-run: already-staged MSSQL rows (tracked by legacy_psh_key/legacy_psd_key/
 * legacy_psp_key) are skipped, so a re-sync only picks up newly-arrived receipts.
 */
class PosImportStagingService
{
    public function __construct(
        private readonly MssqlPosSourceService $source,
    ) {}

    public function stage(string $posCode, Carbon $saleDate): ImportBatch
    {
        $batch = ImportBatch::firstOrCreate(
            ['pos_code' => $posCode, 'sale_date' => $saleDate->toDateString()],
            ['source_system' => 'mssql_pos', 'status' => ImportBatch::STATUS_UPLOADED, 'uploaded_at' => now()]
        );

        $postedStatuses = [ImportBatch::STATUS_POSTED, ImportBatch::STATUS_POSTED_WITH_ERRORS];
        $wasPosted = in_array($batch->status, $postedStatuses, true);

        $receipts = $this->source->fetchReceipts($posCode, $saleDate);
        $pshKeys = array_map('intval', array_column($receipts, 'PSH_KEY'));

        $items = $this->source->fetchItems($pshKeys, $saleDate);
        $payments = $this->source->fetchPayments($pshKeys, $saleDate);
        $paymentTypeNames = $this->source->fetchPaymentTypeNames();

        $itemsByPsh = $this->groupBy($items, 'PSD_PSH');
        $paymentsByPsh = $this->groupBy($payments, 'PSP_PSH');

        $alreadyStaged = ImportedReceipt::where('batch_id', $batch->id)
            ->pluck('legacy_psh_key')
            ->all();

        $newCount = 0;

        DB::transaction(function () use ($receipts, $itemsByPsh, $paymentsByPsh, $paymentTypeNames, $batch, $posCode, $alreadyStaged, &$newCount) {
            foreach ($receipts as $row) {
                $pshKey = (int) $row['PSH_KEY'];

                if (in_array($pshKey, $alreadyStaged, true)) {
                    continue;
                }

                $receipt = $this->stageReceipt($batch, $posCode, $row);
                $this->stageItems($batch, $receipt, $itemsByPsh[$pshKey] ?? []);
                $this->stagePayments($batch, $receipt, $paymentsByPsh[$pshKey] ?? [], $paymentTypeNames);
                $newCount++;
            }
        });

        $update = [
            'record_count' => $batch->receipts()->count(),
        ];

        if (! $wasPosted || $newCount > 0) {
            $update['status'] = ImportBatch::STATUS_PARSED;
        }

        $batch->update($update);

        return $batch->fresh();
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function groupBy(array $rows, string $key): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row[$key]][] = $row;
        }

        return $grouped;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }

    private function stageReceipt(ImportBatch $batch, string $posCode, array $row): ImportedReceipt
    {
        $date = Carbon::parse($row['PSH_DATE']);
        $start = $this->nullIfBlank($row['PSH_START'] ?? null);

        return ImportedReceipt::create([
            'batch_id' => $batch->id,
            'legacy_psh_key' => (int) $row['PSH_KEY'],
            'pos_code' => $posCode,
            'receipt_no' => trim((string) $row['PSH_NO']),
            'receipt_date' => $date->toDateString(),
            'receipt_time' => $start ? Carbon::parse($start)->toTimeString() : null,
            'cashier_code' => $this->nullIfBlank($row['PSH_CASHIER'] ?? null),
            'member_code' => $this->nullIfBlank($row['PSH_MBCODE'] ?? null),
            'gross_amount' => (float) ($row['PSH_G_SV'] ?? 0) + (float) ($row['PSH_G_NV'] ?? 0),
            'discount_amount' => (float) ($row['PSH_DSC_BAHTV'] ?? 0) + (float) ($row['PSH_CPN_BAHTV'] ?? 0) + (float) ($row['PSH_DSCH_BAHTV'] ?? 0),
            'vat_amount' => (float) ($row['PSH_N_VAT'] ?? 0),
            'net_amount' => (float) ($row['PSH_CHARGE'] ?? 0),
            'item_count' => (int) ($row['PSH_N_ITEMS'] ?? 0),
            'raw_data' => $row,
            'status' => ImportedReceipt::STATUS_PENDING,
        ]);
    }

    private function stageItems(ImportBatch $batch, ImportedReceipt $receipt, array $rows): void
    {
        $seq = 1;
        foreach ($rows as $row) {
            ImportedReceiptItem::create([
                'batch_id' => $batch->id,
                'receipt_id' => $receipt->id,
                'legacy_psd_key' => (int) $row['PSD_KEY'],
                'line_no' => $seq++,
                'product_code' => $this->nullIfBlank($row['RESOLVED_GOODS_CODE'] ?? null),
                'sku_code' => $this->nullIfBlank($row['RESOLVED_SKU_CODE'] ?? null),
                'qty' => (float) ($row['PSD_QTY'] ?? 0),
                'unit_price' => (float) ($row['PSD_U_PRC'] ?? 0),
                'discount_amount' => (float) ($row['PSD_DSC_BAHTV'] ?? 0),
                'vat_amount' => (float) ($row['PSD_N_VAT'] ?? 0),
                'net_amount' => (float) ($row['PSD_N_AMT'] ?? 0),
                'raw_data' => $row,
                'mapping_status' => ImportedReceiptItem::MAPPING_PENDING,
            ]);
        }
    }

    /** @param array<int, string> $paymentTypeNames */
    private function stagePayments(ImportBatch $batch, ImportedReceipt $receipt, array $rows, array $paymentTypeNames): void
    {
        foreach ($rows as $row) {
            $pmtCode = $this->nullIfBlank($row['PSP_PMT'] ?? null);

            ImportedPayment::create([
                'batch_id' => $batch->id,
                'receipt_id' => $receipt->id,
                'legacy_psp_key' => (int) $row['PSP_KEY'],
                'payment_code' => $pmtCode,
                'payment_name' => $pmtCode !== null ? ($paymentTypeNames[(int) $pmtCode] ?? null) : null,
                'amount' => (float) ($row['PSP_AMT'] ?? 0),
                'raw_data' => $row,
            ]);
        }
    }
}
