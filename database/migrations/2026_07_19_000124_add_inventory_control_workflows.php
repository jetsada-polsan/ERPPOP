<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->decimal('system_qty', 18, 4)->nullable()->after('qty');
            $table->decimal('counted_qty', 18, 4)->nullable()->after('system_qty');
            $table->foreignId('source_stock_lot_id')->nullable()->after('product_id')
                ->constrained('stock_lots')->nullOnDelete();
            $table->string('return_disposition', 20)->nullable()->after('source_stock_lot_id');
        });
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreignId('source_lot_id')->nullable()->after('source_document_id')
                ->constrained('stock_lots')->nullOnDelete();
        });
        Schema::create('stock_lot_lineages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('output_lot_id')->constrained('stock_lots')->cascadeOnDelete();
            $table->foreignId('input_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->decimal('input_qty', 18, 4);
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
            $table->unique(['output_lot_id', 'input_lot_id']);
        });

        Schema::create('stock_lot_quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_lot_id')->constrained('stock_lots')->cascadeOnDelete();
            $table->string('result', 20);
            $table->text('note')->nullable();
            $table->string('evidence_path')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['stock_lot_id', 'checked_at']);
        });

        Schema::create('recall_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_no', 40)->unique();
            $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->string('severity', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->text('reason');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('recall_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recall_case_id')->constrained('recall_cases')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('qty', 18, 4)->default(0);
            $table->string('contact_status', 20)->default('pending');
            $table->text('contact_note')->nullable();
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();
            $table->unique(['recall_case_id', 'document_id']);
        });

        Schema::create('inventory_cost_closes', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('opening_qty', 18, 4)->default(0);
            $table->decimal('received_qty', 18, 4)->default(0);
            $table->decimal('issued_qty', 18, 4)->default(0);
            $table->decimal('ending_qty', 18, 4)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('ending_value', 18, 4)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['period', 'product_id']);
        });

        foreach ([
            'stock.adjust.approve' => 'อนุมัติใบปรับปรุงสต๊อก',
            'inventory.quality.manage' => 'ตรวจคุณภาพและเรียกคืน Lot',
            'inventory.cost.close' => 'ปิดต้นทุนสินค้าปลายงวด',
        ] as $code => $name) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id')
                ?: DB::table('permissions')->insertGetId(compact('code', 'name'));
            foreach (DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR'])->pluck('id') as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId, 'permission_id' => $permissionId,
                ]);
            }
        }
        foreach ([
            'stock.damage.approve' => ['อนุมัติใบตัดสินค้าชำรุด', ['GM', 'BRANCH_MGR']],
            'finance.note.approve' => ['อนุมัติใบลดหนี้', ['GM', 'ACC_MGR']],
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
        Schema::dropIfExists('inventory_cost_closes');
        Schema::dropIfExists('recall_contacts');
        Schema::dropIfExists('recall_cases');
        Schema::dropIfExists('stock_lot_quality_checks');
        Schema::dropIfExists('stock_lot_lineages');
        Schema::table('stock_lots', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_lot_id'));
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_stock_lot_id');
            $table->dropColumn(['system_qty', 'counted_qty', 'return_disposition']);
        });
        $codes = ['stock.adjust.approve', 'inventory.quality.manage', 'inventory.cost.close', 'stock.damage.approve', 'finance.note.approve'];
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
