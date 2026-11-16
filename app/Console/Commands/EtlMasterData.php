<?php

namespace App\Console\Commands;

use App\Services\Etl\MasterDataEtlService;
use App\Services\Etl\SnapshotMasterDataSourceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EtlMasterData extends Command
{
    protected $signature = 'etl:master-data
        {--skip-stock-snapshot : Do not replace stock_balances; leave live ERP stock untouched for reconciliation.}
        {--products-only : Sync only product master data, units, barcodes, categories, departments and brands.}
        {--from-file= : Read products from a JSON file made by scripts/bplus-master-export.py instead of MSSQL ("-" = stdin). Implies --products-only.}
        {--dry-run : With --from-file, show what would change without writing.}';

    protected $description = 'Pull master data (branches, products, customers, suppliers, warehouses, stock balances, salesmen) from the BPlus MSSQL source (read-only) and upsert into PostgreSQL. Safe to re-run.';

    public function handle(MasterDataEtlService $etl): int
    {
        $file = $this->option('from-file');
        if ($file !== null) {
            return $this->fromFile((string) $file);
        }
        if ($this->option('dry-run')) {
            $this->error('--dry-run ใช้ได้กับ --from-file เท่านั้น');

            return self::FAILURE;
        }

        $this->info('Running master data ETL from BPlus MSSQL...');

        $start = microtime(true);
        $counts = $etl->run(
            skipStockSnapshot: (bool) $this->option('skip-stock-snapshot'),
            productsOnly: (bool) $this->option('products-only'),
        );
        $this->report($counts, $start);

        return self::SUCCESS;
    }

    private function fromFile(string $file): int
    {
        $json = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
        if ($json === false || $json === '') {
            $this->error("อ่านไฟล์ {$file} ไม่ได้");

            return self::FAILURE;
        }

        try {
            $source = SnapshotMasterDataSourceService::fromJson($json);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('ไฟล์สินค้าจาก BPlus: '.collect($source->counts())->map(fn ($n, $k) => "{$k} {$n}")->implode(' · '));
        $this->previewProducts($source);
        if ($this->option('dry-run')) {
            $this->comment('dry-run: ยังไม่ได้บันทึกอะไร');

            return self::SUCCESS;
        }

        $start = microtime(true);
        $counts = DB::transaction(fn () => (new MasterDataEtlService($source))->run(productsOnly: true));
        $this->report($counts, $start);

        return self::SUCCESS;
    }

    private function previewProducts(SnapshotMasterDataSourceService $source): void
    {
        $incoming = collect($source->fetchProducts())
            ->mapWithKeys(fn ($r) => [trim((string) ($r['SKU_CODE'] ?? '')) => $r])
            ->forget('');
        $existing = DB::table('products')->pluck('name_th', 'sku_code')->mapWithKeys(fn ($name, $sku) => [(string) $sku => $name]);

        $new = $incoming->keys()->diff($existing->keys())->count();
        $renamed = $incoming->filter(fn ($r, $sku) => $existing->has($sku)
            && trim((string) ($r['SKU_NAME'] ?? '')) !== '' && trim((string) $r['SKU_NAME']) !== (string) $existing[$sku])->count();
        $erpOnly = $existing->keys()->diff($incoming->keys())->count();

        $this->line("สินค้าใหม่ {$new} รายการ · เปลี่ยนชื่อตาม BPlus {$renamed} รายการ · มีใน ERP แต่ไม่มีใน BPlus {$erpOnly} รายการ (ไม่ลบ)");

        // บาร์โค้ดที่ตอนนี้ผูกสินค้าตัวหนึ่ง แต่ BPlus ผูกกับอีกตัว จะถูกย้ายไปตาม BPlus
        $current = DB::table('product_barcodes as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->pluck('p.sku_code', 'b.barcode')->mapWithKeys(fn ($sku, $barcode) => [(string) $barcode => (string) $sku]);
        $moved = collect($source->fetchProductBarcodes())->filter(function ($r) use ($current) {
            $barcode = trim((string) ($r['GOODS_CODE'] ?? ''));

            return $current->has($barcode) && $current[$barcode] !== trim((string) ($r['SKU_CODE'] ?? ''));
        })->count();
        $this->line("บาร์โค้ดที่จะย้ายไปผูกสินค้าตัวอื่นตาม BPlus {$moved} รายการ");
    }

    /** @param  array<string, int>  $counts */
    private function report(array $counts, float $start): void
    {
        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-22s %d', $table, $count));
        }
        $this->info('Done in '.round(microtime(true) - $start, 1).'s.');
    }
}
