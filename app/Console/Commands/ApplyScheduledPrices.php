<?php

namespace App\Console\Commands;

use App\Services\Pricing\ScheduledPriceChangeService;
use Illuminate\Console\Command;

class ApplyScheduledPrices extends Command
{
    protected $signature = 'erp:apply-scheduled-prices';

    protected $description = 'Apply pending scheduled price changes that have reached their effective date/time.';

    public function handle(ScheduledPriceChangeService $service): int
    {
        $result = $service->applyDue();

        if ($result['total'] > 0) {
            $this->info("Scheduled prices applied: {$result['applied']} succeeded, {$result['failed']} failed (total {$result['total']}).");
        } else {
            $this->line('No scheduled price changes due.');
        }

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
