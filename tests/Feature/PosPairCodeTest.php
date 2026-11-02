<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosPairCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pair_code_returns_the_device_token_until_revoked(): void
    {
        $branch = Branch::create(['code' => 'PR1', 'name_th' => 'x', 'is_active' => true]);
        $user = User::factory()->create();
        $device = PosDevice::create(['name' => 'POS-1', 'user_id' => $user->id, 'branch_id' => $branch->id, 'terminal_code' => 'POS-PR1-01', 'token_hash' => 'h', 'token_encrypted' => 'secret-token']);

        $this->assertMatchesRegularExpression('/^\d{8}$/', $device->pair_code);
        $this->postJson('/api/pos/pair', ['code' => $device->pair_code])->assertOk()
            ->assertJson(['token' => 'secret-token', 'terminal_code' => 'POS-PR1-01']);

        $device->update(['revoked_at' => now()]);
        $this->postJson('/api/pos/pair', ['code' => $device->pair_code])->assertStatus(422);
    }
}
