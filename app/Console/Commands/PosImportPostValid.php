<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Models\ImportedReceipt;
use App\Services\PosImport\PosImportPostingService;
use Illuminate\Console\Command;

class PosImportPostValid extends Command
{
    protected $signature = 'pos-import:post-valid {batch_id : Import batch id}';

    protected $description = 'Post only valid receipts from a staged POS batch; error receipts remain in staging for review.';

    public function handle(PosImportPostingService $posting): int
    {
        $batch = ImportBatch::findOrFail((int) $this->argument('batch_id'));

        $validBefore = $batch->receipts()
            ->where('status', ImportedReceipt::STATUS_VALID)
            ->whereNull('posted_pos_receipt_id')
            ->count();

        $this->info("Posting {$validBefore} valid receipt(s) from batch #{$batch->id} ({$batch->pos_code} {$batch->sale_date?->toDateString()})...");

        $batch = $posting->postValidReceipts($batch, allowErrors: true);

        $posted = $batch->receipts()->where('status', ImportedReceipt::STATUS_POSTED)->count();
        $errors = $batch->receipts()->where('status', ImportedReceipt::STATUS_ERROR)->count();
        $voided = $batch->receipts()->where('status', 'voided')->count();

        $this->line("Status: {$batch->status}");
        $this->line("Posted: {$posted}");
        $this->line("Error left for review: {$errors}");
        $this->line("Voided: {$voided}");

        return self::SUCCESS;
    }
}
