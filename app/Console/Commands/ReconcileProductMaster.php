<?php

namespace App\Console\Commands;

use App\Services\Etl\MssqlMasterDataSourceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileProductMaster extends Command
{
    protected $signature = 'mssql:reconcile-product-master
        {--output=reports/product-master-reconciliation.md : Report path relative to storage/app}';

    protected $description = 'Read-only reconciliation of Bplus products and barcodes against the ERP product master.';

    public function handle(MssqlMasterDataSourceService $source): int
    {
        $sourceProducts = $source->fetchProducts();
        $sourceBarcodes = $source->fetchProductBarcodes();
        $sourceSkus = array_values(array_filter(array_map(
            fn (array $row): string => trim((string) ($row['SKU_CODE'] ?? '')),
            $sourceProducts,
        )));
        $sourceBarcodeValues = array_values(array_filter(array_map(
            fn (array $row): string => trim((string) ($row['GOODS_CODE'] ?? '')),
            $sourceBarcodes,
        )));

        $targetSkus = DB::table('products')->pluck('sku_code')->map(fn ($value): string => trim((string) $value))->all();
        $targetBarcodeValues = DB::table('product_barcodes')->pluck('barcode')->map(fn ($value): string => trim((string) $value))->all();

        $sourceSkuCounts = array_count_values($sourceSkus);
        $sourceBarcodeCounts = array_count_values($sourceBarcodeValues);
        $missingSkus = array_values(array_diff(array_unique($sourceSkus), $targetSkus));
        $missingBarcodes = array_values(array_diff(array_unique($sourceBarcodeValues), $targetBarcodeValues));
        $duplicateSkus = array_keys(array_filter($sourceSkuCounts, fn (int $count): bool => $count > 1));
        $duplicateBarcodes = array_keys(array_filter($sourceBarcodeCounts, fn (int $count): bool => $count > 1));

        $lines = [
            '# Product Master Reconciliation',
            '',
            '> Read-only report. Microsoft SQL Bplus was queried with SELECT only; no source table was changed.',
            '',
            'Generated at: '.now()->toDateTimeString(),
            'Source database: `'.config('mssql_source.database').'`',
            '',
            '## Summary',
            '',
            '| Metric | Count |',
            '|---|---:|',
            '| Bplus product rows | '.count($sourceProducts).' |',
            '| Bplus unique SKU | '.count(array_unique($sourceSkus)).' |',
            '| ERP product rows | '.count($targetSkus).' |',
            '| Bplus SKU missing in ERP | '.count($missingSkus).' |',
            '| Duplicate Bplus SKU | '.count($duplicateSkus).' |',
            '| Bplus barcode rows | '.count($sourceBarcodes).' |',
            '| Bplus unique barcode | '.count(array_unique($sourceBarcodeValues)).' |',
            '| ERP barcode rows | '.count($targetBarcodeValues).' |',
            '| Bplus barcode missing in ERP | '.count($missingBarcodes).' |',
            '| Duplicate Bplus barcode | '.count($duplicateBarcodes).' |',
            '',
            '## Interpretation',
            '',
            '- This report checks product master data only. It does not compare or import `stock_balances`.',
            '- Products that exist only in ERP are retained for local ERP work and are not deleted by reconciliation.',
            '- Missing or duplicate identifiers must be reviewed before product master sign-off.',
        ];

        $this->writeReport(implode("\n", $lines)."\n");

        $this->info('Product master reconciliation complete.');
        $this->line('Bplus SKU: '.count(array_unique($sourceSkus)).' | ERP SKU: '.count($targetSkus).' | Missing: '.count($missingSkus).' | Duplicate: '.count($duplicateSkus));
        $this->line('Bplus barcode: '.count(array_unique($sourceBarcodeValues)).' | ERP barcode: '.count($targetBarcodeValues).' | Missing: '.count($missingBarcodes).' | Duplicate: '.count($duplicateBarcodes));

        return self::SUCCESS;
    }

    private function writeReport(string $report): void
    {
        $relative = ltrim((string) $this->option('output'), '/');
        $path = storage_path('app/'.$relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $report);
        $this->info('Report written: '.$path);
    }
}
