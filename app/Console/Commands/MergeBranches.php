<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รวมสาขาที่ถูกสร้างซ้ำให้เหลือใบเดียว เช่น "0001 - สำนักงานใหญ่" กับ "HQ - สนญ."
 * ที่เป็นสำนักงานใหญ่เหมือนกัน แต่ใบหนึ่งไม่มีคลังเริ่มต้น เลยทำให้หน้าซื้อ/POS พัง.
 *
 * ย้าย FK ของทุกตารางที่มีคอลัมน์ branch_id ไปสาขาที่เก็บไว้ ใน transaction เดียว
 * แล้วปิดการใช้งานสาขาต้นทาง - ไม่ลบแถวทิ้ง เพื่อให้ตามรอยย้อนหลังได้.
 */
class MergeBranches extends Command
{
    protected $signature = 'erp:merge-branches
        {--from= : สาขาที่จะยุบ (code หรือ id)}
        {--into= : สาขาที่จะเก็บไว้ (code หรือ id)}
        {--dry-run : แสดงผลที่จะเกิดขึ้นโดยไม่แก้ข้อมูล}';

    protected $description = 'Merge a duplicate branch into the branch you keep, moving every branch_id reference';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $from = $this->resolveBranch((string) $this->option('from'));
        $into = $this->resolveBranch((string) $this->option('into'));

        if (! $from || ! $into) {
            $this->error('ต้องระบุ --from และ --into ให้ถูกต้อง (code หรือ id ของสาขา)');

            return self::FAILURE;
        }

        if ($from->id === $into->id) {
            $this->error('สาขาต้นทางและปลายทางเป็นสาขาเดียวกัน');

            return self::FAILURE;
        }

        $this->line("ยุบ  [{$from->code}] {$from->name_th}  (id {$from->id})");
        $this->line("เข้า [{$into->code}] {$into->name_th}  (id {$into->id})");

        if (! $into->default_warehouse_location_id) {
            $this->warn('สาขาปลายทางยังไม่มีคลังเริ่มต้น - ตั้งค่าก่อนรวม ไม่อย่างนั้นปัญหาเดิมจะยังอยู่');
        }

        $tables = $this->tablesWithBranchId();
        $conflicts = $this->uniqueConflicts($tables, $from->id, $into->id);

        if ($conflicts !== []) {
            $this->error('ย้ายไม่ได้: มีแถวที่จะชน unique index เมื่อเปลี่ยน branch_id เป็นสาขาปลายทาง');
            foreach ($conflicts as $conflict) {
                $this->line("  {$conflict}");
            }
            $this->error('จัดการแถวที่ซ้ำเหล่านี้ก่อน (เช่น แก้เลขที่เอกสาร หรือลบสิทธิ์/นโยบายที่ซ้ำ) แล้วค่อยรวมสาขา');

            return self::FAILURE;
        }

        $planned = [];
        $total = 0;
        foreach ($tables as $table) {
            $count = DB::table($table)->where('branch_id', $from->id)->count();
            if ($count > 0) {
                $planned[$table] = $count;
                $total += $count;
            }
        }

        if ($planned === []) {
            $this->info('ไม่มีข้อมูลผูกกับสาขาต้นทาง - เหลือแค่ปิดการใช้งานสาขา');
        } else {
            $this->newLine();
            $this->table(['ตาราง', 'จำนวนแถวที่จะย้าย'], collect($planned)
                ->map(fn ($count, $table) => [$table, number_format($count)])
                ->values()
                ->all());
        }

        if ($dryRun) {
            $this->newLine();
            $this->info("DRY RUN: จะย้าย {$total} แถว และปิดการใช้งานสาขา {$from->code} - ยังไม่มีการแก้ข้อมูล");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($planned, $from, $into) {
            foreach (array_keys($planned) as $table) {
                DB::table($table)->where('branch_id', $from->id)->update(['branch_id' => $into->id]);
            }

            $from->update(['is_active' => false]);
        });

        $this->newLine();
        $this->info("ย้าย {$total} แถวไปสาขา {$into->code} แล้ว และปิดการใช้งานสาขา {$from->code} (ไม่ได้ลบแถวทิ้ง)");

        return self::SUCCESS;
    }

    private function resolveBranch(string $key): ?Branch
    {
        if ($key === '') {
            return null;
        }

        // หา code ก่อนเสมอ: รหัสอย่าง "0001" ถ้าแปลงเป็นเลขไปจับ id จะได้คนละสาขา
        return Branch::where('code', $key)->first()
            ?? (ctype_digit($key) ? Branch::find((int) $key) : null);
    }

    /**
     * ทุกตารางที่มีคอลัมน์ branch_id - อ่านจาก schema จริงเพื่อไม่ให้ตกหล่น
     * เมื่อมีตารางใหม่เพิ่มเข้ามาภายหลัง.
     *
     * @return array<int, string>
     */
    private function tablesWithBranchId(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === 'branches') {
                continue;
            }
            foreach (Schema::getColumns($name) as $column) {
                if ($column['name'] === 'branch_id') {
                    $tables[] = $name;
                    break;
                }
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * แถวของสาขาต้นทางที่จะชน unique index ทันทีที่เปลี่ยน branch_id เป็นสาขาปลายทาง
     * เช่น documents (branch_id, doc_number), branch_stock_policies (branch_id, product_id),
     * user_branch_roles (user_id, branch_id, role_id) - อ่านจาก schema จริงทุกตาราง
     * เพื่อให้เจอตั้งแต่ dry-run แทนที่จะไปพังกลาง transaction.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function uniqueConflicts(array $tables, int $fromId, int $intoId): array
    {
        $conflicts = [];
        foreach ($tables as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (empty($index['unique']) || ! in_array('branch_id', $index['columns'], true)) {
                    continue;
                }

                $others = array_values(array_diff($index['columns'], ['branch_id']));
                $query = DB::table($table.' as src')
                    ->join($table.' as dst', function ($join) use ($others, $intoId) {
                        $join->where('dst.branch_id', '=', $intoId);
                        foreach ($others as $column) {
                            $join->on("dst.{$column}", '=', "src.{$column}");
                        }
                    })
                    ->where('src.branch_id', $fromId);

                foreach ($query->limit(20)->get(array_map(fn ($c) => "src.{$c}", $others ?: ['branch_id'])) as $row) {
                    $values = collect((array) $row)->map(fn ($v, $k) => "{$k}={$v}")->implode(', ');
                    $conflicts[] = "{$table} [".implode(',', $index['columns'])."]: {$values}";
                }
            }
        }

        return $conflicts;
    }
}
