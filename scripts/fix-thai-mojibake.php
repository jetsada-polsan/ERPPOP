<?php
// แก้ชื่อภาษาไทยที่เพี้ยน (เช่น "à·¤ÍÔ¹") จากการซิงค์บนเครื่อง Windows ระหว่าง 17-19/11/2569
// ดูก่อน: sed '1d' scripts/fix-thai-mojibake.php | ssh root@27.254.143.219 "cd /var/www/jeterp && php artisan tinker"
// แก้จริง: erp:backup ก่อน แล้ว sed '1d' scripts/fix-thai-mojibake.php | sed 's/^\$apply = false;/$apply = true;/' | ssh ...
$apply = false;
$fix = function (?string $s): ?string {
    if ($s === null || $s === '' || preg_match('/[^\x{00}-\x{FF}]/u', $s) || ! preg_match('/[\x{A1}-\x{FB}]/u', $s)) {
        return null;
    }
    $bytes = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    $thai = @iconv('CP874', 'UTF-8', $bytes);
    return ($thai !== false && $thai !== $s) ? $thai : null;
};
$targets = [['customers', 'name_th'], ['customer_addresses', 'address_line'], ['products', 'name_th'], ['product_units', 'name'],
    ['bplus_sync_documents', 'customer_name'], ['bplus_sync_lines', 'unit_name']];
foreach ($targets as [$table, $col]) {
    $rows = DB::table($table)->whereNotNull($col)->get(['id', $col]);
    $changes = $rows->map(fn ($r) => [$r->id, $fix($r->$col)])->filter(fn ($c) => $c[1] !== null);
    $sample = $changes->first();
    echo "{$table}.{$col}: ".$changes->count()." แถวเพี้ยน".($sample ? ' · ตัวอย่างหลังแก้: '.mb_substr($sample[1], 0, 3).'…' : '')."\n";
    if ($apply) {
        DB::transaction(function () use ($changes, $table, $col) {
            foreach ($changes as [$id, $value]) {
                DB::table($table)->where('id', $id)->update([$col => $value]);
            }
        });
    }
}
echo $apply ? "แก้แล้ว\n" : "ยังไม่แก้ (ดูก่อน)\n";
