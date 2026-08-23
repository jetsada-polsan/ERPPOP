<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ซ่อมตัวนับที่ backfill ผิดใน migration 151
 *
 * ตัวแยกเลขเดิมใช้ regex `(\d{8})(\d{3,})$` ซึ่ง greedy จับผิดตำแหน่ง:
 * "CS000720260706001" ถูกอ่านเป็น period=00072026 seq=706001 แทนที่จะเป็น
 * period=20260706 seq=1 (ตรวจพบบน production 2026-08-23 ทันทีหลัง deploy)
 *
 * ผลกระทบที่เกิดขึ้นจริง: ไม่มี — เพราะ key ที่ผิด (`00072026`) ไม่มีทางตรงกับ
 * period จริงที่เป็น Ymd เสมอ ตัวนับของวันนี้จึงเริ่มใหม่ที่ 1 ตามปกติ
 * แต่แถวขยะพวกนี้ต้องล้าง และตรรกะต้องถูกต้อง ไม่ใช่ถูกโดยบังเอิญ
 *
 * วิธีใหม่: ตัดจากท้ายสตริง — ลำดับคือ 3 หลักสุดท้าย วันที่คือ 8 หลักก่อนหน้า
 * แล้วตรวจว่าเป็นวันที่จริง เชื่อถือได้กว่าให้ regex เดาตำแหน่งเอง
 * (รหัสสาขาที่มีเลข 20 อยู่ เช่น "2007" ทำให้ regex เดิมเพี้ยนได้ด้วย)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ทิ้งแถวที่ period ไม่ใช่รูปแบบวันที่ — เป็นขยะจากตัวแยกเลขเดิมทั้งหมด
        DB::table('document_sequences')->whereRaw("period NOT LIKE '20%'")->delete();

        $highest = [];
        $rows = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->selectRaw('dt.code as type_code, d.branch_id, d.doc_number')
            ->get();

        foreach ($rows as $row) {
            $parsed = $this->parse((string) $row->doc_number);
            if (! $parsed) {
                continue;
            }
            [$period, $sequence] = $parsed;
            $key = $row->type_code.':'.$row->branch_id.'|'.$period;
            if (($highest[$key] ?? 0) < $sequence) {
                $highest[$key] = $sequence;
            }
        }

        foreach ($highest as $key => $sequence) {
            [$scope, $period] = explode('|', $key, 2);
            $existing = DB::table('document_sequences')->where('scope', $scope)->where('period', $period)->first();
            if ($existing) {
                if ((int) $existing->last_number < $sequence) {
                    DB::table('document_sequences')->where('id', $existing->id)
                        ->update(['last_number' => $sequence, 'updated_at' => now()]);
                }

                continue;
            }
            DB::table('document_sequences')->insert([
                'scope' => $scope, 'period' => $period, 'last_number' => $sequence,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // ไม่ย้อน: ตัวนับที่ถูกต้องดีกว่าตัวนับที่ผิดเสมอ
    }

    /**
     * เลขเอกสารรูปแบบ PREFIX + รหัสสาขา + YYYYMMDD + ลำดับ
     *
     * @return array{0: string, 1: int}|null
     */
    private function parse(string $number): ?array
    {
        foreach ([3, 4, 5] as $sequenceLength) {
            if (strlen($number) < $sequenceLength + 8) {
                continue;
            }
            $sequence = substr($number, -$sequenceLength);
            $period = substr($number, -($sequenceLength + 8), 8);
            if (! ctype_digit($sequence) || ! preg_match('/^20\d{6}$/', $period)) {
                continue;
            }
            if (! checkdate((int) substr($period, 4, 2), (int) substr($period, 6, 2), (int) substr($period, 0, 4))) {
                continue;
            }

            return [$period, (int) $sequence];
        }

        return null;
    }
};
