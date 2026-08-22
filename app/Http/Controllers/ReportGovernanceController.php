<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReportDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * หน้าตั้งค่า "เปิด/ปิดรายงาน" สำหรับผู้บริหาร/ผู้ดูแลระบบ (settings.manage)
 *
 * ปิดรายงาน = ซ่อนจากเมนูเท่านั้น definition ยังอยู่ครบและเปิดกลับได้ทุกเมื่อ
 * ไม่มีทางลบรายงานหรือประวัติจากหน้านี้ และทุกครั้งที่เปลี่ยนสถานะจะถูกบันทึก audit log
 */
class ReportGovernanceController extends Controller
{
    public function index(): View
    {
        $definitions = ReportDefinition::orderBy('category')->orderBy('sort_order')->get();

        return view('settings.reports', [
            'groups' => $definitions->groupBy('category'),
            'counts' => [
                'total' => $definitions->count(),
                'enabled' => $definitions->where('enabled', true)->count(),
                'planned' => $definitions->where('status', 'planned')->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'report_id' => ['required', 'integer', 'exists:report_definitions,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $definition = ReportDefinition::findOrFail($data['report_id']);
        $wasEnabled = $definition->enabled;

        // รายงานที่ยังไม่มีหน้าจอจริงเปิดไม่ได้ — กติกา: ต้องผ่าน mapping + UAT ก่อน
        if ($data['enabled'] && $definition->status !== 'available') {
            return back()->withErrors([
                'report' => "เปิด \"{$definition->name}\" ยังไม่ได้ เพราะสถานะเป็น {$definition->status} — ต้องมี mapping และ UAT เทียบยอดผ่านก่อน",
            ]);
        }

        if ($wasEnabled === (bool) $data['enabled']) {
            return back()->with('success', 'สถานะเดิมอยู่แล้ว ไม่มีอะไรเปลี่ยน');
        }

        $definition->update(['enabled' => (bool) $data['enabled']]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'branch_id' => auth()->user()?->branch_id,
            'action' => $data['enabled'] ? 'report_enabled' : 'report_disabled',
            'table_name' => 'report_definitions',
            'record_id' => $definition->id,
            'old_values' => ['enabled' => $wasEnabled],
            'new_values' => ['enabled' => (bool) $data['enabled'], 'code' => $definition->code],
        ]);

        return back()->with('success', ($data['enabled'] ? 'เปิด' : 'ปิด').'รายงาน "'.$definition->name.'" แล้ว');
    }
}
