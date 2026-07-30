<?php

namespace App\Console\Commands;

use App\Services\Etl\MasterDataEtlService;
use Illuminate\Console\Command;

class EtlMasterData extends Command
{
    protected $signature = 'etl:master-data
        {--skip-stock-snapshot : Do not replace stock_balances; leave live ERP stock untouched for reconciliation.}
        {--products-only : Sync only product master data, units, barcodes, categories, departments and brands.}';

    protected $description = 'Pull master data (branches, products, customers, suppliers, warehouses, stock balances, salesmen) from the BPlus MSSQL source (read-only) and upsert into PostgreSQL. Safe to re-run.';

    public function handle(MasterDataEtlService $etl): int
    {
        $this->info('Running master data ETL from BPlus MSSQL...');

        $start = microtime(true);
        $counts = $etl->run(
            skipStockSnapshot: (bool) $this->option('skip-stock-snapshot'),
            productsOnly: (bool) $this->option('products-only'),
        );
        $elapsed = round(microtime(true) - $start, 1);

        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-22s %d', $table, $count));
        }

        $this->info("Done in {$elapsed}s.");

        return self::SUCCESS;
    }
}
