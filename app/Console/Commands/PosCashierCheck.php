<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\Salesman;
use Illuminate\Console\Command;

/**
 * ตรวจว่าทำไมแคชเชียร์เข้าเครื่อง POS ด้วย PIN ไม่ได้ อ่านอย่างเดียว ไม่แสดง PIN
 * เงื่อนไขเดียวกับ PosApiController::cashierCandidates + pinMatches
 */
class PosCashierCheck extends Command
{
    protected $signature = 'erp:pos-cashier-check {branch : รหัสสาขา เช่น B001}';

    protected $description = 'Show why cashiers of a branch can or cannot sign in to POS with a PIN (no PINs shown)';

    public function handle(): int
    {
        $branch = Branch::where('code', $this->argument('branch'))->first();
        if (! $branch) {
            $this->error('ไม่พบสาขา');

            return self::FAILURE;
        }

        $this->info("เครื่อง POS ของสาขา {$branch->code}");
        $this->table(['เครื่อง', 'สาขาที่ผูก', 'ผู้ใช้ที่ผูก', 'สถานะ'], PosDevice::with('user')->where(fn ($q) => $q->where('branch_id', $branch->id)->orWhere('terminal_code', 'like', "%{$branch->code}%"))->get()
            ->map(fn (PosDevice $d) => [$d->terminal_code, $d->branch_id === $branch->id ? 'ตรง' : 'ไม่ตรง ('.$d->branch_id.')', $d->user?->username ?? '-', $d->revoked_at ? 'ถูกเพิกถอน' : 'ใช้งาน']));

        $rows = Salesman::with(['user.roles.permissions', 'user.branchRoles.permissions', 'user.posCredential'])
            ->where(fn ($q) => $q->where('branch_id', $branch->id)->orWhereHas('user', fn ($u) => $u->where('branch_id', $branch->id)))
            ->get()
            ->map(function (Salesman $s) use ($branch) {
                $user = $s->user;
                $branchRole = $user?->branchRoles->first(fn ($r) => (int) $r->pivot->branch_id === $branch->id && $r->pivot->is_active && $r->permissions->contains('code', 'pos.sell'));
                $homeRole = $user && $user->branchRoles->where('pivot.is_active', true)->isEmpty()
                    && in_array($user->branch_id, [null, $branch->id], true)
                    && $user->roles->contains(fn ($r) => $r->permissions->contains('code', 'pos.sell'));
                $pin = filled($user?->posCredential?->pin_hash ?: $s->pos_pin_hash);
                $ok = $s->is_active && $user?->is_active && ($branchRole || $homeRole) && $pin;

                return [$s->code, $s->name, $s->is_active ? '✓' : '✗ ปิดอยู่', $user ? ($user->is_active ? $user->username : '✗ ผู้ใช้ปิดอยู่') : '✗ ไม่ผูกผู้ใช้',
                    $branchRole || $homeRole ? '✓' : '✗ ไม่มีสิทธิ์ pos.sell ที่สาขานี้', $pin ? '✓' : '✗ ยังไม่ตั้ง PIN', $ok ? 'เข้าได้' : 'เข้าไม่ได้'];
            });
        $this->info("แคชเชียร์สาขา {$branch->code}");
        $this->table(['รหัส', 'ชื่อ', 'ใช้งาน', 'ผู้ใช้', 'สิทธิ์ขาย', 'PIN', 'ผล'], $rows);

        return self::SUCCESS;
    }
}
