<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\PosImport\MssqlPosSourceService;
use App\Services\PosImport\PosImportPostingService;
use App\Services\PosImport\PosImportStagingService;
use App\Services\PosImport\PosImportValidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class PosImportSyncAll extends Command
{
    protected $signature = 'pos-import:sync-all
        {--from= : First sale date, e.g. 2026-06-01}
        {--to= : Last sale date, e.g. 2026-06-30}
        {--pos=* : POS/branch code to include, repeatable e.g. --pos=0001 --pos=0002}
        {--post : Post valid receipts into live POS tables after validation}
        {--apply-stock : Also write stock movements/decrement stock balances. Leave off for historical MSSQL migration because stock balances are loaded from MSSQL snapshots.}';

    protected $description = 'Discover all POS sale days from BPlus MSSQL, stage them into PostgreSQL, validate, and optionally post valid receipts.';

    public function handle(
        MssqlPosSourceService $source,
        PosImportStagingService $staging,
        PosImportValidationService $validation,
        PosImportPostingService $posting,
    ): int {
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : null;
        $posCodes = array_values(array_filter(array_map('trim', (array) $this->option('pos'))));
        $shouldPost = (bool) $this->option('post');
        $applyStock = (bool) $this->option('apply-stock');

        $days = $source->fetchAvailableSaleDays($from, $to, $posCodes);
        if ($days === []) {
            $this->warn('No POS receipts found in MSSQL for the selected filters.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($days).' POS/day batch(es).');
        $summary = [
            'staged' => 0,
            'validated' => 0,
            'has_error' => 0,
            'posted' => 0,
            'failed' => 0,
        ];

        foreach ($days as $index => $day) {
            $prefix = '['.($index + 1).'/'.count($days).']';
            $this->line("{$prefix} POS {$day['pos_code']} {$day['sale_date']} ({$day['receipt_count']} receipt(s))");

            try {
                $batch = $staging->stage($day['pos_code'], Carbon::parse($day['sale_date']));
                $summary['staged']++;

                if (in_array($batch->status, [ImportBatch::STATUS_POSTED, ImportBatch::STATUS_POSTED_WITH_ERRORS], true)) {
                    $summary['posted']++;
                    $this->line("    already {$batch->status}");
                    continue;
                }

                $batch = $validation->validate($batch);
                $summary[$batch->status === ImportBatch::STATUS_HAS_ERROR ? 'has_error' : 'validated']++;

                $valid = $batch->receipts()->where('status', 'valid')->count();
                $error = $batch->receipts()->where('status', 'error')->count();
                $voided = $batch->receipts()->where('status', 'voided')->count();
                $this->line("    status={$batch->status} valid={$valid} error={$error} voided={$voided}");

                if (! $shouldPost || $valid === 0) {
                    continue;
                }

                if ($batch->status === ImportBatch::STATUS_VALIDATED) {
                    $batch->update([
                        'status' => ImportBatch::STATUS_CONFIRMED,
                        'confirmed_at' => now(),
                    ]);
                    $batch = $batch->fresh();
                }

                $batch = $posting->postValidReceipts($batch, allowErrors: true, applyStock: $applyStock);
                $summary['posted']++;
                $this->line("    posted_status={$batch->status}");
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->error('    failed: '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Done.');
        foreach ($summary as $key => $count) {
            $this->line(sprintf('  %-10s %d', $key, $count));
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
