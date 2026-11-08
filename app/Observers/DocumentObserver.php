<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\DocumentType;
use App\Services\Accounting\AccountingPeriodGuard;
use App\Services\Inventory\InventoryCostCloseGuard;

class DocumentObserver
{
    public function __construct(
        private readonly AccountingPeriodGuard $periods,
        private readonly InventoryCostCloseGuard $inventoryPeriods,
    ) {}

    public function creating(Document $document): void
    {
        $this->periods->assertOpen($document->doc_date, $document->branch_id);
        $this->assertInventoryPeriodOpen($document->doc_date, $document->document_type_id);
    }

    public function updating(Document $document): void
    {
        $this->periods->assertOpen($document->getOriginal('doc_date'), $document->getOriginal('branch_id'));
        $this->periods->assertOpen($document->doc_date, $document->branch_id);
        $this->assertInventoryPeriodOpen($document->getOriginal('doc_date'), $document->getOriginal('document_type_id'));
        $this->assertInventoryPeriodOpen($document->doc_date, $document->document_type_id);
    }

    public function deleting(Document $document): void
    {
        $this->periods->assertOpen($document->doc_date, $document->branch_id);
        $this->assertInventoryPeriodOpen($document->doc_date, $document->document_type_id);
    }

    private function assertInventoryPeriodOpen(mixed $date, mixed $documentTypeId): void
    {
        if ($documentTypeId && DocumentType::whereKey($documentTypeId)->value('affects_stock')) {
            $this->inventoryPeriods->assertOpen($date, 'doc_date');
        }
    }
}
