<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ตัวนับเลขที่เอกสารแบบล็อกได้ — แทนการ COUNT แถวแล้วบวกหนึ่ง
 *
 * ของเดิม DocumentNumberGenerator นับเอกสารของวันนั้นด้วย COUNT(*) โดยไม่ล็อกอะไรเลย
 * สอง transaction ที่วิ่งพร้อมกันจึงนับได้เลขเดียวกันและสร้างเลขที่ซ้ำกัน
 *
 * ทดสอบจริงบน PostgreSQL (staging, 2026-08-23) ยิง 10 process × 5 เอกสาร:
 * สำเร็จ 8 ล้มเหลว 42 ทั้งหมดเป็น unique violation ของ documents_branch_id_doc_number_unique
 * แปลว่าถ้ามีคนขายพร้อมกันสิบคน บิลพังไป 84%
 *
 * ตารางนี้ให้แถวเดียวต่อ (ประเภทเอกสาร+สาขา, วัน) ไว้ล็อกก่อนออกเลข
 * และ backfill ค่าเริ่มต้นจากเอกสารที่มีอยู่แล้ว เลขจึงเดินต่อ ไม่เริ่มใหม่
 */
return new class extends Migration
{
    /** ตารางที่มีเลขที่เอกสารของตัวเองแต่ยังไม่มี unique index กันซ้ำ */
    private const NUMBERED_TABLES = ['stock_counts', 'billing_notes', 'purchase_orders', 'quotations'];

    public function up(): void
    {
        // สร้างเฉพาะเมื่อยังไม่มี — migration นี้เคยล้มกลางทางแล้วต้องรันซ้ำได้
        if (! Schema::hasTable('document_sequences')) {
            Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);      // เช่น CASH_SALE:3 หรือ BOOK:12:3
            $table->string('period', 8);      // Ymd
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
                $table->unique(['scope', 'period']);
            });

            $this->backfillFromDocuments();
        }

        // กันเลขซ้ำในตารางที่ออกเลขเอง — เดิมไม่มี unique เลยแม้แต่ตารางเดียว
        // จึงเขียนเลขซ้ำได้เงียบ ๆ ต่างจาก documents ที่อย่างน้อยยัง error ให้รู้
        foreach (self::NUMBERED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'doc_number')) {
                continue;
            }
            // migration นี้เคยล้มกลางทางได้ ข้าม index ที่สร้างไปแล้วเพื่อให้รันซ้ำได้
            $indexName = $table.'_doc_number_unique';
            if (collect(Schema::getIndexes($table))->contains(fn ($index) => $index['name'] === $indexName)) {
                continue;
            }
            if (DB::table($table)->exists()) {
                $duplicates = DB::table($table)
                    ->select('doc_number')->groupBy('doc_number')
                    ->havingRaw('count(*) > 1')->exists();
                if ($duplicates) {
                    // มีของซ้ำอยู่ก่อนแล้ว สร้าง unique ไม่ได้ ต้องให้คนตัดสินใจก่อน
                    continue;
                }
            }
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique('doc_number', $indexName));
        }
    }

    public function down(): void
    {
        foreach (self::NUMBERED_TABLES as $table) {
            $indexName = $table.'_doc_number_unique';
            if (Schema::hasTable($table) && collect(Schema::getIndexes($table))->contains(fn ($index) => $index['name'] === $indexName)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($indexName));
            }
        }

        Schema::dropIfExists('document_sequences');
    }

    /** เริ่มตัวนับจากเลขสูงสุดที่ออกไปแล้ว ไม่ให้เลขย้อนกลับไปชนของเก่า */
    private function backfillFromDocuments(): void
    {
        $rows = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->selectRaw("dt.code as type_code, d.branch_id, d.doc_number")
            ->get();

        $highest = [];
        foreach ($rows as $row) {
            // ตัดจากท้าย: ลำดับคือหลักสุดท้าย วันที่คือ 8 หลักก่อนหน้า แล้วตรวจว่าเป็นวันที่จริง
            // ห้ามใช้ regex เดาตำแหน่ง เพราะ greedy จะจับผิดเมื่อรหัสสาขามีเลข 20 อยู่ด้วย
            $number = (string) $row->doc_number;
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
                $key = $row->type_code.':'.$row->branch_id.'|'.$period;
                if (($highest[$key] ?? 0) < (int) $sequence) {
                    $highest[$key] = (int) $sequence;
                }
                break;
            }
        }

        foreach ($highest as $key => $sequence) {
            [$scope, $period] = explode('|', $key, 2);
            DB::table('document_sequences')->insert([
                'scope' => $scope,
                'period' => $period,
                'last_number' => $sequence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
