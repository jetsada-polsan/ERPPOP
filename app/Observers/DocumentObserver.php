<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\Accounting\AccountingPeriodGuard;

class DocumentObserver
{
    public function __construct(private readonly AccountingPeriodGuard $periods) {}

    public function creating(Document $document): void
    {
        $this->periods->assertOpen($document->doc_date, $document->branch_id);
    }

    public function updating(Document $document): void
    {
        $this->periods->assertOpen($document->getOriginal('doc_date'), $document->getOriginal('branch_id'));
        $this->periods->assertOpen($document->doc_date, $document->branch_id);
    }

    public function deleting(Document $document): void
    {
        $this->periods->assertOpen($document->doc_date, $document->branch_id);
    }
}
