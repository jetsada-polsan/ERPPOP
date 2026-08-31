<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_branch_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'branch_id', 'role_id'], 'user_branch_role_unique');
            $table->index(['branch_id', 'is_active'], 'user_branch_roles_branch_active_idx');
        });

        Schema::create('user_pos_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('pin_hash')->nullable();
            $table->boolean('force_pin_change')->default(false);
            $table->timestamp('pin_changed_at')->nullable();
            $table->timestamp('credential_version')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Existing global role + home branch becomes the first explicit branch assignment.
        DB::table('users')->whereNotNull('branch_id')->orderBy('id')->get(['id', 'branch_id'])
            ->each(function ($user): void {
                DB::table('role_user')->where('user_id', $user->id)->pluck('role_id')
                    ->each(fn ($roleId) => DB::table('user_branch_roles')->insertOrIgnore([
                        'user_id' => $user->id,
                        'branch_id' => $user->branch_id,
                        'role_id' => $roleId,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
            });

        // Copy the existing verifier without exposing or re-hashing the PIN. Salesman stays
        // as a legacy document reference, but credentials are user-owned from this point on.
        DB::table('salesmen')->whereNotNull('user_id')->orderBy('id')->get()
            ->each(function ($salesman): void {
                DB::table('user_pos_credentials')->updateOrInsert(
                    ['user_id' => $salesman->user_id],
                    [
                        'pin_hash' => $salesman->pos_pin_hash,
                        'force_pin_change' => (bool) ($salesman->must_change_pin ?? false),
                        'pin_changed_at' => $salesman->pin_changed_at,
                        'credential_version' => $salesman->pos_credential_version,
                        'revoked_at' => $salesman->is_active ? null : now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_pos_credentials');
        Schema::dropIfExists('user_branch_roles');
    }
};
