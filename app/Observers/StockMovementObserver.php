<?php

namespace App\Observers;

use App\Models\StockMovement;
use App\Services\Inventory\InventoryCostCloseGuard;

class StockMovementObserver
{
    public function __construct(private readonly InventoryCostCloseGuard $guard) {}

    public function creating(StockMovement $movement): void
    {
        $this->guard->assertOpen($movement->movement_date);
    }

    public function deleting(StockMovement $movement): void
    {
        $this->guard->assertOpen($movement->movement_date);
    }
}
