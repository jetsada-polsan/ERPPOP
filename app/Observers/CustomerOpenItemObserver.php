<?php

namespace App\Observers;

use App\Models\BillingNote;
use App\Models\CustomerOpenItem;

class CustomerOpenItemObserver
{
    public function saved(CustomerOpenItem $item): void
    {
        if (! $item->wasChanged(['balance_amount', 'status'])) {
            return;
        }

        // Run synchronously inside the payment/adjustment transaction so a failed
        // posting rolls back the billing status together with the AR balance.
        BillingNote::whereIn('status', ['open', 'collected'])
            ->whereHas('items', fn ($query) => $query->where('customer_open_item_id', $item->id))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (BillingNote $note): void {
                $outstanding = $note->items()
                    ->whereHas('openItem', fn ($query) => $query->where('balance_amount', '>', '0.01'))
                    ->exists();
                $status = $outstanding ? 'open' : 'collected';
                if ($note->status !== $status) {
                    $note->update(['status' => $status]);
                }
            });
    }
}
