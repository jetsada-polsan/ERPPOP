<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairProductNamesFromLegacy extends Command
{
    protected $signature = 'erp:repair-product-names
        {file : JSON exported by the approved legacy product-name agent}
        {--dry-run : Report changes without writing them}';

    protected $description = 'Repair mojibake product names in ERP from a SELECT-only BPlus product-master export.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->error('Invalid product-master JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (($payload['source'] ?? null) !== 'legacy_product_master' || ! is_array($payload['rows'] ?? null)) {
            $this->error('This is not a legacy product-master export.');

            return self::FAILURE;
        }

        $namesBySku = collect($payload['rows'])
            ->mapWithKeys(function (array $row): array {
                $sku = trim((string) ($row['SKU_CODE'] ?? ''));
                $name = trim((string) ($row['SKU_NAME'] ?? ''));

                return $sku !== '' && $name !== '' ? [$sku => $name] : [];
            });

        $changed = 0;
        $unmatched = 0;
        DB::table('products')->select('id', 'sku_code', 'name_th')->orderBy('id')->eachById(function (object $product) use ($namesBySku, &$changed, &$unmatched): void {
            $current = (string) $product->name_th;
            if (! str_contains($current, 'เธ')) {
                return;
            }

            $sourceName = $namesBySku->get(trim((string) $product->sku_code));
            if (! is_string($sourceName) || $sourceName === '') {
                $unmatched++;

                return;
            }
            if ($sourceName === $current) {
                return;
            }

            $changed++;
            if (! $this->option('dry-run')) {
                DB::table('products')->where('id', $product->id)->update([
                    'name_th' => $sourceName,
                    'updated_at' => now(),
                ]);
            }
        });

        $this->info(($this->option('dry-run') ? 'Would repair' : 'Repaired')." {$changed} product names.");
        if ($unmatched > 0) {
            $this->warn("{$unmatched} mojibake products did not match a legacy SKU and were not changed.");
        }

        return self::SUCCESS;
    }
}
