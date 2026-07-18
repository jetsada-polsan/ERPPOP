<?php

namespace App\Console\Commands;

use App\Services\PosImport\PosImportStagingService;
use App\Services\PosImport\PosImportValidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PosImportSync extends Command
{
    protected $signature = 'pos-import:sync {pos_code : POS terminal code, e.g. 0001} {date : Sale date, e.g. 2025-07-24}';

    protected $description = 'Pull one POS terminal\'s one sale-date worth of receipts from the BPlus MSSQL source (read-only), stage them, and run validation.';

    public function handle(PosImportStagingService $staging, PosImportValidationService $validation): int
    {
        $posCode = $this->argument('pos_code');
        $saleDate = Carbon::parse($this->argument('date'));

        $this->info("Staging POS {$posCode} for {$saleDate->toDateString()}...");
        $batch = $staging->stage($posCode, $saleDate);
        $this->info("Batch #{$batch->id}: {$batch->record_count} receipt(s) staged.");

        $this->info('Validating...');
        $batch = $validation->validate($batch);

        $this->line("Status: {$batch->status}");
        $this->line('Valid: '.$batch->receipts()->where('status', 'valid')->count());
        $this->line('Error: '.$batch->receipts()->where('status', 'error')->count());
        $this->line('Voided: '.$batch->receipts()->where('status', 'voided')->count());

        if ($batch->status === \App\Models\ImportBatch::STATUS_HAS_ERROR) {
            $this->warn('Errors found - review via the pos-import batch detail before confirming.');
            foreach ($batch->errors()->select('error_type')->groupBy('error_type')->selectRaw('error_type, count(*) as c')->get() as $row) {
                $this->line("  {$row->error_type}: {$row->c}");
            }
        }

        return self::SUCCESS;
    }
}
