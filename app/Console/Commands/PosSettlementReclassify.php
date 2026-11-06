<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Accounting\GlPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PosSettlementReclassify extends Command
{
    protected $signature = 'erp:pos-settlement-reclassify {--apply} {--from=} {--receipt-id=}';

    protected $description = 'Dry-run POS payment reclassification; --apply appends reversals and replacement entries';

    public function handle(GlPostingService $gl): int
    {
        $failed = false;
        $query = DB::table('pos_receipts')->where('status', 'completed')->whereNotNull('document_id')
            ->when($this->option('from'), fn ($q, $date) => $q->whereDate('receipt_date', '>=', $date))
            ->when($this->option('receipt-id'), fn ($q, $id) => $q->where('id', $id))->orderBy('id');
        foreach ($query->cursor() as $receipt) {
            try {
                $changed = $gl->reclassifyPosSettlement(Document::findOrFail($receipt->document_id), $receipt->id, (bool) $this->option('apply'));
                $this->line(($this->option('apply') ? 'APPLY' : 'DRY RUN').' '.$receipt->receipt_no.' '.($changed ? 'reclassify' : 'unchanged'));
            } catch (\Throwable $e) {
                $failed = true;
                $this->error($receipt->receipt_no.': '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
