<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileMemberPoints extends Command
{
    protected $signature = 'loyalty:reconcile-points {--member= : Check one member id}';

    protected $description = 'Compare member point balances with the immutable point ledger';

    public function handle(): int
    {
        $members = Member::query()
            ->when($this->option('member'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('member_code')
            ->get(['id', 'member_code', 'name', 'points']);

        $mismatches = [];
        foreach ($members as $member) {
            $ledger = (float) DB::table('member_point_transactions')
                ->where('member_id', $member->id)
                ->selectRaw("coalesce(sum(case when direction = 'redeem' then -points else points end), 0) as balance")
                ->value('balance');
            $stored = (float) $member->points;
            if (abs($stored - $ledger) > 0.0001) {
                $mismatches[] = [$member->id, $member->member_code, $member->name, $stored, $ledger, $stored - $ledger];
            }
        }

        $this->line('ตรวจสมาชิก '.number_format($members->count()).' ราย');
        if ($mismatches !== []) {
            $this->table(['ID', 'รหัส', 'ชื่อ', 'ยอดในสมาชิก', 'ยอดจาก ledger', 'ส่วนต่าง'], $mismatches);
            $this->error('พบยอดแต้มไม่ตรงกัน '.number_format(count($mismatches)).' รายการ — ยังไม่มีการแก้ข้อมูล');

            return self::FAILURE;
        }

        $this->info('ยอด members.points ตรงกับ ledger ทั้งหมด — ไม่มีการแก้ข้อมูล');

        return self::SUCCESS;
    }
}
