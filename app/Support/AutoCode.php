<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** เว้นรหัสว่างแล้วระบบรันให้ รูปแบบ คำนำหน้า-ปีพ.ศ.เดือน-ลำดับ เช่น PM-256911-001 */
final class AutoCode
{
    public static function fill(Request $request, string $table, string $prefix): void
    {
        if (trim((string) $request->input('code')) !== '') {
            return;
        }

        $period = sprintf('%s-%d%s-', $prefix, now()->year + 543, now()->format('m'));
        $last = DB::table($table)->where('code', 'like', $period.'%')->orderByDesc('code')->value('code');
        $next = $last ? ((int) substr($last, strlen($period))) + 1 : 1;
        $request->merge(['code' => $period.str_pad((string) $next, 3, '0', STR_PAD_LEFT)]);
    }
}
