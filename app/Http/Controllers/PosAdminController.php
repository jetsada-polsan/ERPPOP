<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosTerminal;
use App\Models\Salesman;
use App\Models\User;
use App\Services\Pos\PosAdminOverview;
use App\Support\PythonPosInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * หน้าเดียวสำหรับดูแลเครื่อง POS: ความพร้อมรายสาขา, เพิ่มเครื่อง, สถานะ sync, PIN คนขาย และฮาร์ดแวร์
 * ปุ่มต่าง ๆ ส่งไป action เดิมใน SystemSettingController ซึ่งพากลับมาหน้านี้
 */
class PosAdminController extends Controller
{
    public function index(PosAdminOverview $overview): View
    {
        return view('pos-admin.index', [
            'branches' => $overview->branches(),
            'devices' => $overview->devices(),
            'issues' => $overview->issues(),
            'overview' => $overview,
            'allBranches' => Branch::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']),
            'terminals' => PosTerminal::with('branch:id,code,name_th')->orderBy('code')->get(),
            'installer' => PythonPosInstaller::current(),
            'pinMode' => AppSetting::get('pos_passwordless_login') === '1' ? 'selection' : 'pin',
            'onlineMinutes' => PosAdminOverview::ONLINE_MINUTES,
        ]);
    }

    /** เปลี่ยนคนประจำเครื่องโดยไม่ต้องออก Token ใหม่ เครื่องรับรายชื่อใหม่ตอน sync รอบถัดไป */
    public function assignDevice(Request $request, PosDevice $device): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $user = User::findOrFail($data['user_id']);

        if ($device->revoked_at || ! $user->is_active || ! Salesman::where('user_id', $user->id)->where('is_active', true)->exists()
            || ! $user->canAccessBranch((int) $device->branch_id, 'pos.sell')) {
            return back()->with('error', "{$user->name} ใช้เครื่องของสาขานี้ไม่ได้ ตรวจสาขาและสิทธิ์ขายก่อน");
        }
        if ($this->terminalHasOpenShift($device->terminal_code)) {
            return back()->with('error', "เครื่อง {$device->name} ยังมีกะเปิดอยู่ ปิดกะก่อนเปลี่ยนคน");
        }

        DB::transaction(function () use ($device, $user) {
            $old = $device->user_id;
            $device->update(['user_id' => $user->id, 'active_cashier_id' => null, 'active_cashier_user_id' => null, 'cashier_verified_at' => null]);
            AuditLog::create([
                'user_id' => auth()->id(), 'branch_id' => $device->branch_id, 'action' => 'pos_device_reassigned',
                'table_name' => 'pos_devices', 'record_id' => $device->id,
                'old_values' => ['user_id' => $old], 'new_values' => ['user_id' => $user->id],
            ]);
        });

        return back()->with('success', "เครื่อง {$device->name} ใช้กับ {$user->name} แล้ว กด Sync ที่เครื่องเพื่อรับรายชื่อใหม่");
    }

    /** ย้ายคนขายไปสาขาใหม่: เปลี่ยนสาขาหลัก ปิดสิทธิ์สาขาเดิม และให้บทบาทเดิมที่สาขาใหม่ */
    public function moveCashier(Request $request, Salesman $salesman): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $user = $salesman->user;
        if (! $user) {
            return back()->with('error', "{$salesman->code} ยังไม่ผูก user");
        }
        if (DB::table('pos_shifts')->where('cashier_id', $salesman->id)->where('status', 'open')->exists()) {
            return back()->with('error', "{$salesman->code} ยังมีกะเปิดอยู่ ปิดกะก่อนย้ายสาขา");
        }
        $target = (int) $data['branch_id'];
        $bound = PosDevice::where('user_id', $user->id)->whereNull('revoked_at')->where('branch_id', '!=', $target)->pluck('name');
        if ($bound->isNotEmpty()) {
            return back()->with('error', "{$salesman->code} ยังเป็นคนประจำเครื่อง {$bound->implode(', ')} เปลี่ยนคนประจำเครื่องก่อน");
        }

        DB::transaction(function () use ($salesman, $user, $target) {
            $old = $salesman->branch_id;
            DB::table('user_branch_roles')->where('user_id', $user->id)->where('branch_id', '!=', $target)
                ->update(['is_active' => false, 'effective_to' => now(), 'updated_at' => now()]);
            foreach ($user->roles()->pluck('roles.id') as $roleId) {
                DB::table('user_branch_roles')->updateOrInsert(
                    ['user_id' => $user->id, 'branch_id' => $target, 'role_id' => $roleId],
                    ['is_active' => true, 'effective_from' => null, 'effective_to' => null, 'updated_at' => now(), 'created_at' => now()],
                );
            }
            $user->update(['branch_id' => $target]);
            $salesman->update(['branch_id' => $target]);
            PosDevice::where('active_cashier_id', $salesman->id)->update([
                'active_cashier_id' => null, 'active_cashier_user_id' => null, 'cashier_verified_at' => null,
            ]);
            AuditLog::create([
                'user_id' => auth()->id(), 'branch_id' => $target, 'action' => 'pos_cashier_moved',
                'table_name' => 'salesmen', 'record_id' => $salesman->id,
                'old_values' => ['branch_id' => $old], 'new_values' => ['branch_id' => $target],
            ]);
        });

        return back()->with('success', "ย้าย {$salesman->code} แล้ว");
    }

    private function terminalHasOpenShift(?string $code): bool
    {
        $terminalId = DB::table('pos_terminals')->where('code', $code)->value('id');

        return $terminalId && DB::table('pos_shifts')->where('pos_terminal_id', $terminalId)->where('status', 'open')->exists();
    }
}
