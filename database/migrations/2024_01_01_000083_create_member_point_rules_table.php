<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Member points (แต้มทอง/แต้มทวีคูณ): rules decide how POS bills convert to
// points on members.points. An 'earn' rule sets the base rate (spend X baht =
// 1 point) plus the redeem value of 1 point in baht; 'multiplier' rules are
// time-boxed campaigns (แต้มทวีคูณ) that multiply earned points while active.
// Every earn/redeem writes a member_point_transactions ledger row for audit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_point_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('rule_type', 20)->default('earn'); // earn|multiplier
            $table->decimal('baht_per_point', 18, 4)->nullable();
            $table->decimal('point_value_baht', 18, 4)->nullable();
            $table->decimal('multiplier', 8, 4)->nullable();
            $table->date('starts_date')->nullable();
            $table->date('ends_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('member_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('direction', 10); // earn|redeem|adjust
            $table->decimal('points', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_point_transactions');
        Schema::dropIfExists('member_point_rules');
    }
};
