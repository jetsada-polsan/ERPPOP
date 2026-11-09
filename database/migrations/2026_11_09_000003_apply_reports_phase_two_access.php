<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Owner's Phase 2 policy: accounting/company, executive/company, manager/assigned branches.
        foreach (['ACC' => ['management.view', 'reports.all_branches'], 'EXECUTIVE' => ['management.view'], 'BRANCH_MGR' => ['management.view']] as $role => $permissions) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');
            foreach ($permissions as $permission) {
                $permissionId = DB::table('permissions')->where('code', $permission)->value('id');
                if ($roleId && $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
        DB::table('report_definitions')->whereIn('code', ['sales.daily_by_channel', 'inventory.stock_card_detail', 'payment.received_and_unidentified', 'tax.vat_sales', 'tax.vat_purchase'])->update(['enabled' => true, 'status' => 'available', 'updated_at' => now()]);
        DB::table('report_definitions')->insertOrIgnore([
            'code' => 'booking.delivery_status', 'category' => 'booking', 'category_title' => 'ใบจอง / จัดส่ง',
            'name' => 'สถานะจัดส่งตามใบจอง', 'view_permission' => 'transport.manage', 'sort_order' => 35,
            'status' => 'available', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('report_definitions')->whereIn('code', ['sales.daily_by_channel', 'inventory.stock_card_detail', 'payment.received_and_unidentified', 'tax.vat_sales', 'tax.vat_purchase'])->update(['enabled' => false, 'updated_at' => now()]);
        foreach (['ACC' => ['management.view', 'reports.all_branches'], 'EXECUTIVE' => ['management.view'], 'BRANCH_MGR' => ['management.view']] as $role => $permissions) {
            DB::table('permission_role')->where('role_id', DB::table('roles')->where('code', $role)->value('id'))
                ->whereIn('permission_id', DB::table('permissions')->whereIn('code', $permissions)->pluck('id'))->delete();
        }
        DB::table('report_definitions')->where('code', 'booking.delivery_status')->delete();
    }
};
