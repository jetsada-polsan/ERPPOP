<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $employees = DB::table('employees')
                ->whereNotNull('user_id')
                ->where('employee_code', 'like', 'POP%')
                ->get(['user_id', 'employee_code']);

            foreach ($employees as $employee) {
                $user = DB::table('users')->where('id', $employee->user_id)->first(['id', 'username']);
                if (! $user || ! preg_match('/^emp\d+$/i', (string) $user->username)) {
                    continue;
                }

                $taken = DB::table('users')->where('username', $employee->employee_code)->where('id', '<>', $user->id)->exists();
                if (! $taken) {
                    DB::table('users')->where('id', $user->id)->update(['username' => $employee->employee_code]);
                }
            }
        });
    }

    public function down(): void
    {
        // Login identifiers are business data; do not guess legacy values on rollback.
    }
};
