<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Primary route assignments confirmed from the current employee master and
     * the predominant Business Plus booking owner. Old SALESMAN rows remain
     * available for historical reports but are not the identity for new work.
     *
     * @var array<string, string>
     */
    private const EMPLOYEE_ROUTES = [
        'EMP0022' => 'B15', // กาญ
        'EMP0023' => 'B17', // บีบี
        'EMP0025' => 'B26', // รุ่ง
        'EMP0026' => 'B16', // เก่ง
        'EMP0027' => 'B11', // จิน
        'EMP0028' => 'B14', // วุ้น
        'EMP0029' => 'BK6', // แอนนา
        'EMP0030' => 'B12', // โบว์ (BK7 remains a customer-level route)
        'EMP0033' => 'B20', // ยีนส์
        'EMP0034' => 'B18', // แบงค์
        'EMP0095' => 'B39', // บุ๋มบิ๋ม
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sales_area_id')->nullable()
                ->constrained('sales_areas')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('sales_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('sales_area_id')->nullable()
                ->constrained('sales_areas')->nullOnDelete();
            $table->index(['sales_user_id', 'sales_area_id'], 'customers_sales_owner_route_idx');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('sales_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->index(['sales_user_id', 'doc_date'], 'documents_sales_user_date_idx');
        });

        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->foreignId('sales_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->index(['status', 'sales_user_id'], 'sale_bookings_status_sales_user_idx');
        });

        Schema::table('customer_open_items', function (Blueprint $table) {
            $table->foreignId('sales_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->index(['sales_user_id', 'status'], 'customer_open_items_sales_user_status_idx');
        });

        $permissionId = DB::table('permissions')->where('code', 'sales.assign')->value('id')
            ?: DB::table('permissions')->insertGetId([
                'code' => 'sales.assign',
                'name' => 'กำหนดผู้ดูแลลูกค้าและสายการขาย',
            ]);

        DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR'])->pluck('id')->each(
            fn ($roleId) => DB::table('permission_role')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ])
        );

        foreach (self::EMPLOYEE_ROUTES as $employeeCode => $routeCode) {
            $userId = DB::table('employees')->where('employee_code', $employeeCode)->value('user_id');
            $routeId = DB::table('sales_areas')
                ->where('code', $routeCode)
                ->where('area_type', 'route')
                ->value('id');

            if ($userId && $routeId) {
                DB::table('users')->where('id', $userId)->update(['sales_area_id' => $routeId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customer_open_items', function (Blueprint $table) {
            $table->dropIndex('customer_open_items_sales_user_status_idx');
            $table->dropConstrainedForeignId('sales_user_id');
        });
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->dropIndex('sale_bookings_status_sales_user_idx');
            $table->dropConstrainedForeignId('sales_user_id');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_sales_user_date_idx');
            $table->dropConstrainedForeignId('sales_user_id');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_sales_owner_route_idx');
            $table->dropConstrainedForeignId('sales_area_id');
            $table->dropConstrainedForeignId('sales_user_id');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_area_id'));
    }
};
