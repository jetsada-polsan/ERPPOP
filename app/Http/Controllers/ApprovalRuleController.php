<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalRuleController extends Controller
{
    public function index()
    {
        return view('settings.approval-rules', ['rules' => DB::table('approval_rules')->orderBy('document_type')->orderBy('min_amount')->get(),
            'branches' => Branch::orderBy('code')->get(), 'permissions' => Permission::orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'document_type' => 'required|in:SALE_RETURN,SUPPLIER_CREDIT_NOTE,SUPPLIER_DEBIT_NOTE,PURCHASE_ORDER',
            'branch_id' => 'nullable|integer|exists:branches,id', 'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'nullable|numeric|gte:min_amount', 'permission' => 'required|exists:permissions,code',
        ]);
        DB::transaction(function () use ($data) {
            $id = DB::table('approval_rules')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
            AuditLog::create(['user_id' => auth()->id(), 'action' => 'approval_rule.created', 'table_name' => 'approval_rules', 'record_id' => $id, 'new_values' => $data]);
        });
        return back()->with('success', 'เพิ่มกติกาอนุมัติแล้ว');
    }

    public function toggle(Request $request, int $rule)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        DB::transaction(function () use ($rule, $data) {
            $old = DB::table('approval_rules')->where('id', $rule)->lockForUpdate()->firstOrFail();
            DB::table('approval_rules')->where('id', $rule)->update($data + ['updated_at' => now()]);
            AuditLog::create(['user_id' => auth()->id(), 'action' => 'approval_rule.updated', 'table_name' => 'approval_rules', 'record_id' => $rule, 'old_values' => (array)$old, 'new_values' => $data]);
        });
        return back()->with('success', 'บันทึกสถานะแล้ว');
    }
}
