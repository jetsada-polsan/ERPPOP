<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairProductNamesFromLegacy extends Command
{
    protected $signature = 'erp:repair-product-names
        {file : JSON exported by the approved legacy product-name agent}
        {--dry-run : Report changes without writing them}';

    protected $description = 'Repair missing or mojibake product names from a SELECT-only BPlus product-master export.';

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
        $missingName = 0;
        $mojibake = 0;
        $unmatched = 0;
        $invalidSourceName = 0;

        DB::table('products')->select('id', 'sku_code', 'name_th')->orderBy('id')->eachById(function (object $product) use ($namesBySku, &$changed, &$missingName, &$mojibake, &$unmatched, &$invalidSourceName): void {
            $sku = trim((string) $product->sku_code);
            $current = trim((string) $product->name_th);
            $isMojibake = str_contains($current, 'เธ');
            $isMissingName = $current === '' || $current === $sku;

            if (! $isMojibake && ! $isMissingName) {
                return;
            }

            $sourceName = trim((string) $namesBySku->get($sku, ''));
            if ($sourceName === '') {
                $unmatched++;

                return;
            }
            if ($sourceName === $sku) {
                $invalidSourceName++;

                return;
            }
            if ($sourceName === $current) {
                return;
            }

            $changed++;
            $missingName += $isMissingName ? 1 : 0;
            $mojibake += $isMojibake ? 1 : 0;
            if (! $this->option('dry-run')) {
                DB::table('products')->where('id', $product->id)->update([
                    'name_th' => $sourceName,
                    'updated_at' => now(),
                ]);
            }
        });

        $this->info(($this->option('dry-run') ? 'Would repair' : 'Repaired')." {$changed} product names.");
        if ($changed > 0) {
            $this->line("- {$missingName} missing/duplicate SKU names; {$mojibake} mojibake names.");
        }
        if ($unmatched > 0) {
            $this->warn("{$unmatched} products did not match a legacy SKU and were not changed.");
        }
        if ($invalidSourceName > 0) {
            $this->warn("{$invalidSourceName} legacy rows had no usable product name and were not changed.");
        }

        return self::SUCCESS;
    }
}
