<?php

namespace App\Observers;

use App\Models\StockDocumentItem;
use App\Services\Inventory\InventoryCostCloseGuard;

class StockDocumentItemObserver
{
    public function __construct(private readonly InventoryCostCloseGuard $guard) {}

    public function creating(StockDocumentItem $item): void
    {
        $this->assertPeriodOpen($item);
    }

    public function updating(StockDocumentItem $item): void
    {
        $this->assertPeriodOpen($item);
    }

    public function deleting(StockDocumentItem $item): void
    {
        $this->assertPeriodOpen($item);
    }

    private function assertPeriodOpen(StockDocumentItem $item): void
    {
        $document = $item->stockDocument?->document;
        if ($document?->documentType()->value('affects_stock')) {
            $this->guard->assertOpen($document->doc_date, 'doc_date');
        }
    }
}
