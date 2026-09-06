<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $employees = DB::table('employees')
                ->where('employee_code', 'like', 'EMP%')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'employee_code']);

            $next = (int) DB::table('employees')
                ->where('employee_code', 'like', 'POP%')
                ->pluck('employee_code')
                ->reduce(function (int $max, string $code): int {
                    return preg_match('/^POP(\d+)$/i', $code, $matches)
                        ? max($max, (int) $matches[1])
                        : $max;
                }, 0) + 1;

            foreach ($employees as $employee) {
                do {
                    $code = 'POP'.str_pad((string) $next++, 3, '0', STR_PAD_LEFT);
                } while (DB::table('employees')->where('employee_code', $code)->exists());

                DB::table('employees')->where('id', $employee->id)->update(['employee_code' => $code]);
            }
        });
    }

    public function down(): void
    {
        // Legacy codes are business data; they are intentionally not recreated on rollback.
    }
};
