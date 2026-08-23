<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\PosTerminalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * รหัสเครื่อง POS — ระบบจ่ายเลขเอง คนไม่ต้องพิมพ์
 */
class PosTerminalCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_branch_counts_its_own_tills(): void
    {
        $first = Branch::create(['code' => 'B001', 'name_th' => 'สาขาหนึ่ง', 'is_active' => true]);
        $second = Branch::create(['code' => 'B002', 'name_th' => 'สาขาสอง', 'is_active' => true]);

        $this->assertSame('POS-B001-01', PosTerminalCode::next($first));
        $this->device('POS-B001-01', $first);
        $this->assertSame('POS-B001-02', PosTerminalCode::next($first));

        // สาขาอื่นเริ่มนับใหม่ ไม่ใช่เลขวิ่งข้ามสาขา
        $this->assertSame('POS-B002-01', PosTerminalCode::next($second));
    }

    public function test_a_retired_number_is_never_handed_out_again(): void
    {
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขา', 'is_active' => true]);
        $this->device('POS-B001-01', $branch);
        $retired = $this->device('POS-B001-02', $branch);
        DB::table('pos_devices')->where('id', $retired)->update(['revoked_at' => now()]);

        // เครื่องที่ยกเลิกไปแล้วยังกินเลข 02 อยู่ เพราะบิลเก่าอ้างรหัสนั้น
        $this->assertSame('POS-B001-03', PosTerminalCode::next($branch));
    }

    public function test_two_tills_cannot_share_a_code(): void
    {
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขา', 'is_active' => true]);
        $this->device('POS-B001-01', $branch);

        $this->expectException(QueryException::class);
        $this->device('POS-B001-01', $branch);
    }

    public function test_tills_without_a_code_do_not_collide(): void
    {
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขา', 'is_active' => true]);

        // ยังไม่ได้ตั้งรหัสมีได้หลายเครื่อง unique จึงต้องไม่นับ null
        $this->device(null, $branch);
        $this->device(null, $branch);

        $this->assertSame(2, DB::table('pos_devices')->whereNull('terminal_code')->count());
        $this->assertSame('POS-B001-01', PosTerminalCode::next($branch));
    }

    private function device(?string $terminalCode, Branch $branch): int
    {
        static $sequence = 0;
        $user = User::factory()->create(['username' => 'till-owner-'.++$sequence, 'branch_id' => $branch->id]);

        return DB::table('pos_devices')->insertGetId([
            'name' => 'เครื่อง '.$sequence, 'terminal_code' => $terminalCode,
            'branch_id' => $branch->id, 'user_id' => $user->id,
            'token_hash' => hash('sha256', 'tok'.$sequence),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
