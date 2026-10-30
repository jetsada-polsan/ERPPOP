<?php

namespace App\Services\Sales;

use App\Models\SaleBooking;
use App\Models\TransportJob;
use App\Support\DecimalMath;

class BookingLoadSheetValidator
{
    public function isComplete(SaleBooking $booking, TransportJob $job): bool
    {
        $bookingDocument = $booking->document()->with('stockDocument.items')->first();
        $bookingItems = $bookingDocument?->stockDocument?->items ?? collect();
        if ($bookingItems->isEmpty()) {
            return false;
        }

        $loadItems = $job->loadItems()->lockForUpdate()->get()->keyBy('stock_document_item_id');
        if ($loadItems->count() !== $bookingItems->count()) {
            return false;
        }

        foreach ($bookingItems as $bookingItem) {
            $loadItem = $loadItems->get($bookingItem->id);
            if (! $loadItem
                || $loadItem->availability !== 'available'
                || DecimalMath::compare($loadItem->planned_qty, $bookingItem->qty) !== 0
                || DecimalMath::compare($loadItem->loaded_qty, $bookingItem->qty) !== 0) {
                return false;
            }
        }

        return true;
    }
}
