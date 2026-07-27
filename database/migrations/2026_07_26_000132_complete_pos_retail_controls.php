<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_held_bills', function (Blueprint $table) {
            $table->foreignId('pos_shift_id')->nullable()->after('pos_terminal_id')->constrained('pos_shifts')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->after('pos_shift_id')->constrained('salesmen')->nullOnDelete();
            $table->foreignId('held_by')->nullable()->after('cashier_id')->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable()->after('note');
            $table->timestamp('held_at')->nullable()->after('payload');
            $table->timestamp('resumed_at')->nullable()->after('held_at');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('maximum_sale_price', 20, 8)->nullable()->after('default_price');
            $table->decimal('minimum_margin_percent', 13, 8)->nullable()->after('maximum_sale_price');
            $table->string('margin_control_policy', 20)->default('warn')->after('minimum_margin_percent');
        });

        Schema::create('supplier_price_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->decimal('minimum_qty', 20, 8)->default(1);
            $table->decimal('unit_price', 20, 8);
            $table->string('vat_mode', 20)->default('included');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'supplier_id', 'effective_from'], 'supplier_price_lookup');
        });

        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->string('movement_type', 20);
            $table->decimal('amount', 20, 8);
            $table->string('reference_no', 80)->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['pos_shift_id', 'movement_type']);
        });

        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->json('hardware_profile')->nullable()->after('name');
        });

        $permissionId = DB::table('permissions')->where('code', 'pos.cash.manage')->value('id')
            ?: DB::table('permissions')->insertGetId([
                'code' => 'pos.cash.manage',
                'name' => 'อนุมัติเงินเข้าออกลิ้นชัก POS',
            ]);
        $roleIds = DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', 'pos.cash.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::table('pos_terminals', fn (Blueprint $table) => $table->dropColumn('hardware_profile'));
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('supplier_price_schedules');

        Schema::table('products', fn (Blueprint $table) => $table->dropColumn([
            'maximum_sale_price', 'minimum_margin_percent', 'margin_control_policy',
        ]));

        Schema::table('pos_held_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_shift_id');
            $table->dropConstrainedForeignId('cashier_id');
            $table->dropConstrainedForeignId('held_by');
            $table->dropColumn(['payload', 'held_at', 'resumed_at']);
        });
    }
};
