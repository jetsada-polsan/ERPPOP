<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('budget_no', 40)->unique();
            $table->char('fiscal_year', 4);
            $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->decimal('budget_amount', 18, 4);
            $table->text('note')->nullable();
            $table->unique(['budget_id', 'month', 'account_id']);
        });
        Schema::table('branch_expenses', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('branch_id')->constrained('cost_centers')->nullOnDelete();
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('monthly_salary', 18, 4)->default(0)->after('status');
            $table->boolean('social_security_enabled')->default(true)->after('monthly_salary');
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('clock_in')->nullable();
            $table->timestamp('clock_out')->nullable();
            $table->string('status', 20)->default('present');
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique();
            $table->string('status', 20)->default('draft');
            $table->decimal('gross_amount', 18, 4)->default(0);
            $table->decimal('deduction_amount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('base_salary', 18, 4);
            $table->decimal('overtime_amount', 18, 4)->default(0);
            $table->decimal('absence_deduction', 18, 4)->default(0);
            $table->decimal('social_security', 18, 4)->default(0);
            $table->decimal('withholding_tax', 18, 4)->default(0);
            $table->decimal('other_deduction', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4);
            $table->json('calculation_detail')->nullable();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
        Schema::create('ecommerce_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecommerce_channel_id')->constrained('ecommerce_channels')->cascadeOnDelete();
            $table->string('external_order_id', 100);
            $table->string('status', 30);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->unique(['ecommerce_channel_id', 'external_order_id']);
        });
        Schema::create('ecommerce_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecommerce_order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->string('external_sku', 80);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_total', 18, 4);
        });
        Schema::create('ecommerce_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecommerce_channel_id')->constrained('ecommerce_channels')->cascadeOnDelete();
            $table->string('direction', 20);
            $table->string('entity_type', 30);
            $table->string('status', 20);
            $table->unsignedInteger('record_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });
        Schema::create('monitor_events', function (Blueprint $table) {
            $table->id();
            $table->string('check_code', 50);
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->text('message');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity', 'detected_at']);
        });
        Schema::table('line_integrations', function (Blueprint $table) {
            $table->string('target_id', 150)->nullable()->after('target_name');
        });
        foreach ([
            'management.view' => ['ดูศูนย์ควบคุมบริหาร', ['GM', 'ACC_MGR', 'HR_MGR', 'IT_MGR', 'PURCHASING', 'MARKETING']],
            'budget.manage' => ['จัดการงบประมาณและศูนย์ต้นทุน', ['GM', 'ACC_MGR']],
            'payroll.manage' => ['จัดการเวลาเข้างานและเงินเดือน', ['GM', 'HR_MGR']],
            'ecommerce.sync' => ['เชื่อมคำสั่งซื้อและสต๊อก E-Commerce', ['GM', 'IT_MGR', 'MARKETING']],
            'monitoring.manage' => ['ตรวจสุขภาพและเหตุการณ์ระบบ', ['GM', 'IT_MGR']],
        ] as $code => [$name, $roleCodes]) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id')
                ?: DB::table('permissions')->insertGetId(compact('code', 'name'));
            foreach (DB::table('roles')->whereIn('code', $roleCodes)->pluck('id') as $roleId) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('line_integrations', fn (Blueprint $table) => $table->dropColumn('target_id'));
        Schema::dropIfExists('monitor_events');
        Schema::dropIfExists('ecommerce_sync_logs');
        Schema::dropIfExists('ecommerce_order_items');
        Schema::dropIfExists('ecommerce_orders');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('attendance_records');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['monthly_salary', 'social_security_enabled']));
        Schema::table('branch_expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('cost_center_id'));
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('cost_centers');
        $permissionIds = DB::table('permissions')->whereIn('code', ['management.view', 'budget.manage', 'payroll.manage', 'ecommerce.sync', 'monitoring.manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
