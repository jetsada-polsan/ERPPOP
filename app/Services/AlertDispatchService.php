<?php

namespace App\Services;

use App\Models\LineIntegration;
use Illuminate\Support\Facades\Http;
use Throwable;

class AlertDispatchService
{
    public function send(string $message, string $category = 'stock'): int
    {
        $sent = 0;
        foreach (LineIntegration::where('is_active', true)->get() as $integration) {
            if ($category === 'stock' && ! $integration->notify_stock_alert) {
                continue;
            }
            if (blank($integration->token) || blank($integration->target_id)) {
                continue;
            }
            try {
                $response = Http::withToken($integration->token)->timeout(10)
                    ->post('https://api.line.me/v2/bot/message/push', [
                        'to' => $integration->target_id,
                        'messages' => [['type' => 'text', 'text' => mb_substr($message, 0, 4900)]],
                    ]);
                if ($response->successful()) {
                    $sent++;
                }
            } catch (Throwable) {
                // Monitoring must continue even when LINE itself is unavailable.
            }
        }

        return $sent;
    }
}
