<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PosOfflineAuthEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_can_upload_an_offline_auth_event_once_even_when_it_retries(): void
    {
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $cashier = Salesman::create(['branch_id' => $branch->id, 'code' => 'C001', 'name' => 'แคชเชียร์', 'is_active' => true]);
        $device = PosDevice::create([
            'name' => 'POS001',
            'user_id' => User::factory()->create(['username' => 'pos_auth_test', 'branch_id' => $branch->id])->id,
            'branch_id' => $branch->id,
            'terminal_code' => 'POS001',
            'token_hash' => hash('sha256', 'offline-auth-event'),
        ]);
        $payload = ['events' => [[
            'event_uuid' => '51d587c7-8baa-4bc6-8e2d-71011b93e2a1',
            'cashier_code' => $cashier->code,
            'event_type' => 'offline_login',
            'success' => true,
            'reason' => null,
            'terminal_code' => 'POS001',
            'branch_code' => 'B001',
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ]]];

        $first = Request::create('/api/pos/auth-events', 'POST', $payload);
        $first->attributes->set('pos_device', $device);
        $second = Request::create('/api/pos/auth-events', 'POST', $payload);
        $second->attributes->set('pos_device', $device);

        $controller = app(PosApiController::class);
        $this->assertSame(1, $controller->authEvents($first)->getData(true)['accepted']);
        $this->assertSame(0, $controller->authEvents($second)->getData(true)['accepted']);
        $this->assertSame(1, AuditLog::where('action', 'pos_offline_login')->count());
    }
}
