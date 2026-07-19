<?php

namespace App\Observers;

use App\Models\GlJournal;
use App\Services\Accounting\AccountingPeriodGuard;
use Illuminate\Support\Facades\DB;

class GlJournalObserver
{
    public function __construct(private readonly AccountingPeriodGuard $periods) {}

    public function creating(GlJournal $journal): void
    {
        $this->periods->assertOpen($journal->entry_date, $this->branchId($journal), 'entry_date');
    }

    public function updating(GlJournal $journal): void
    {
        $this->periods->assertOpen($journal->getOriginal('entry_date'), $this->branchId($journal), 'entry_date');
        $this->periods->assertOpen($journal->entry_date, $this->branchId($journal), 'entry_date');
    }

    public function deleting(GlJournal $journal): void
    {
        $this->periods->assertOpen($journal->entry_date, $this->branchId($journal), 'entry_date');
    }

    private function branchId(GlJournal $journal): ?int
    {
        if ($journal->document_id) {
            return DB::table('documents')->where('id', $journal->document_id)->value('branch_id');
        }

        if ($journal->payment_document_id) {
            return DB::table('payment_documents')
                ->join('documents', 'documents.id', '=', 'payment_documents.document_id')
                ->where('payment_documents.id', $journal->payment_document_id)
                ->value('documents.branch_id');
        }

        return null;
    }
}
