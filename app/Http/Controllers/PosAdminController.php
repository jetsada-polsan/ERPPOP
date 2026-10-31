<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosTerminal;
use App\Models\Salesman;
use App\Services\Pos\PosAdminOverview;
use App\Support\PythonPosInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

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
        try {
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
                // เครื่องสาขาเดิมต้องไม่ค้างชื่อคนที่ย้ายออก ไม่งั้นเขายังเข้าเครื่องนั้นได้
                foreach (PosDevice::where('user_id', $user->id)->whereNull('revoked_at')->where('branch_id', '!=', $target)->get() as $device) {
                    $replacement = Salesman::where('branch_id', $device->branch_id)->where('is_active', true)
                        ->whereNotNull('user_id')->where('user_id', '!=', $user->id)->orderBy('code')->value('user_id');
                    if (! $replacement) {
                        throw new RuntimeException("สาขาเดิมของเครื่อง {$device->name} ไม่เหลือคนขาย เพิ่มคนขายก่อนย้าย");
                    }
                    $device->update(['user_id' => $replacement, 'active_cashier_id' => null, 'active_cashier_user_id' => null, 'cashier_verified_at' => null]);
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
        } catch (RuntimeException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', "ย้าย {$salesman->code} แล้ว");
    }
}
