<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcileMemberPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_passes_when_member_balance_matches_ledger(): void
    {
        $member = Member::create(['member_code' => 'M-001', 'name' => 'ทดสอบ', 'points' => 8]);
        DB::table('member_point_transactions')->insert([
            'member_id' => $member->id, 'direction' => 'earn', 'points' => 10, 'balance_after' => 10,
            'note' => 'test', 'created_at' => now(),
        ]);
        DB::table('member_point_transactions')->insert([
            'member_id' => $member->id, 'direction' => 'redeem', 'points' => 2, 'balance_after' => 8,
            'note' => 'test', 'created_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('loyalty:reconcile-points'));
    }

    public function test_reconcile_fails_without_modifying_a_mismatch(): void
    {
        $member = Member::create(['member_code' => 'M-002', 'name' => 'ทดสอบ', 'points' => 9]);
        DB::table('member_point_transactions')->insert([
            'member_id' => $member->id, 'direction' => 'earn', 'points' => 10, 'balance_after' => 10,
            'note' => 'test', 'created_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('loyalty:reconcile-points'));
        $this->assertSame('9.0000', (string) $member->fresh()->points);
    }
}
