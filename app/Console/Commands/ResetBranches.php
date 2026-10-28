<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * เริ่มโครงสาขาใหม่: เก็บเฉพาะสาขาที่ระบุ ลบสาขาอื่น และเปลี่ยนรหัสสาขาที่เก็บไว้ได้
 *
 * ใช้ตอนเปิดระบบใหม่ที่เริ่มจากสาขาเดียว (เก็บ HQ + อำนาจเจริญ แล้วให้อำนาจเจริญเป็น B001)
 * ต้องล้างธุรกรรมก่อน — ทุกตารางที่อ้างสาขาแบบห้ามว่าง (เอกสาร กะ ใบสั่งซื้อ ฯลฯ) ต้องไม่มีแถวของสาขาที่จะลบ
 *
 * สิ่งที่ผูกกับสาขาที่ลบ:
 *   ลบ: เครื่อง POS, จุดขาย, สิทธิ์รายสาขา, คลัง + ตำแหน่งเก็บ (ต้องไม่มีอะไรอ้างตำแหน่งเก็บอยู่)
 *   ตั้งเป็นว่าง: ผู้ใช้ (และปิดใช้งาน), เขตการขาย (และปิดใช้งาน), พนักงาน, audit log และตารางอื่นที่ว่างได้
 */
class ResetBranches extends Command
{
    use RequiresFreshBackup;

    /** ตารางที่ลบทั้งแถวเมื่อสาขาถูกลบ เรียงลูกก่อนแม่ */
    private const DELETE_WITH_BRANCH = ['pos_devices', 'pos_terminals', 'user_branch_roles', 'warehouses'];

    /** log ทางเทคนิคของเครื่อง POS ที่ถูกลบ ลบตามเครื่อง */
    private const DEVICE_LOGS = ['pos_api_idempotency', 'pos_auth_event_ingests'];

    /** ตารางที่ตั้งสาขาเป็นว่างแล้วปิดใช้งานด้วย */
    private const DEACTIVATE_WITH_BRANCH = ['users', 'sales_areas'];

    protected $signature = 'erp:reset-branches
        {--keep=* : รหัสสาขาที่เก็บไว้ (ระบุซ้ำได้)}
        {--rename=* : เปลี่ยนรหัส เช่น B006:B001}
        {--confirm-database= : ชื่อฐานข้อมูล ต้องพิมพ์ให้ตรง}
        {--dry-run : สรุปโดยไม่แก้ข้อมูล}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Keep the listed branches, delete the rest and optionally recode the kept ones';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        $keepCodes = array_values(array_filter((array) $this->option('keep')));
        $kept = DB::table('branches')->whereIn('code', $keepCodes)->pluck('id', 'code');
        if ($keepCodes === [] || $kept->count() !== count($keepCodes)) {
            $this->error('ต้องระบุ --keep เป็นรหัสสาขาที่มีอยู่จริง ไม่พบ: '.implode(', ', array_diff($keepCodes, $kept->keys()->all())));

            return self::FAILURE;
        }

        $renames = [];
        foreach ((array) $this->option('rename') as $pair) {
            [$from, $to] = array_pad(explode(':', (string) $pair, 2), 2, '');
            if (! isset($kept[$from]) || $to === '') {
                $this->error("--rename={$pair} ต้องเป็น รหัสที่เก็บไว้:รหัสใหม่");

                return self::FAILURE;
            }
            $renames[$from] = $to;
        }

        $doomed = DB::table('branches')->whereNotIn('id', $kept->values())->orderBy('code')->get(['id', 'code', 'name_th']);
        $doomedIds = $doomed->pluck('id')->all();

        $clash = array_diff(array_values($renames), array_merge($doomed->pluck('code')->all(), array_keys($renames)));
        if ($clash !== [] && DB::table('branches')->whereIn('code', $clash)->exists()) {
            $this->error('รหัสใหม่ซ้ำกับสาขาที่เก็บไว้: '.implode(', ', $clash));

            return self::FAILURE;
        }

        // ตารางที่อ้างสาขาและจะทำอะไรกับมัน
        $plan = [];
        $blocking = [];
        foreach ($this->foreignKeysTo('branches') as [$table, $column, $nullable]) {
            $rows = $doomedIds === [] ? 0 : DB::table($table)->whereIn($column, $doomedIds)->count();
            if ($rows === 0) {
                continue;
            }
            $action = match (true) {
                in_array($table, self::DELETE_WITH_BRANCH, true) => 'delete',
                in_array($table, self::DEACTIVATE_WITH_BRANCH, true) => 'deactivate',
                $nullable => 'null',
                default => 'block',
            };
            if ($action === 'block') {
                $blocking[] = [$table, $column, number_format($rows)];
            }
            $plan[] = compact('table', 'column', 'rows', 'action');
        }

        // คลังที่จะลบ: ตำแหน่งเก็บต้องไม่มีอะไรอ้างอยู่ (ยอดคงเหลือ ฯลฯ)
        $warehouseIds = $doomedIds === [] ? [] : DB::table('warehouses')->whereIn('branch_id', $doomedIds)->pluck('id')->all();
        $locationIds = $warehouseIds === [] ? [] : DB::table('warehouse_locations')->whereIn('warehouse_id', $warehouseIds)->pluck('id')->all();
        foreach ([['warehouse_locations', $locationIds], ['warehouses', $warehouseIds], ['pos_terminals', $this->ids('pos_terminals', $doomedIds)], ['pos_devices', $this->ids('pos_devices', $doomedIds)]] as [$parent, $ids]) {
            if ($ids === []) {
                continue;
            }
            foreach ($this->foreignKeysTo($parent) as [$table, $column]) {
                if (in_array($table, ['warehouse_locations', 'warehouses', 'pos_terminals', 'pos_devices', ...self::DEVICE_LOGS], true)) {
                    continue;
                }
                $query = DB::table($table)->whereIn($column, $ids);
                if ($table === 'branches') {
                    $query->whereNotIn('id', $doomedIds); // สาขาที่ลบชี้คลังตัวเอง หายไปพร้อมกัน
                }
                if (($rows = $query->count()) > 0) {
                    $blocking[] = [$table, "{$column} -> {$parent}", number_format($rows)];
                }
            }
        }

        $this->line("ฐานข้อมูล: {$database}");
        $this->table(['เก็บไว้', 'รหัสใหม่'], $kept->keys()->map(fn ($code) => [$code, $renames[$code] ?? '(เดิม)'])->all());
        $this->table(['ลบสาขา', 'ชื่อ'], $doomed->map(fn ($b) => [$b->code, $b->name_th])->all());
        $this->table(['ตาราง', 'คอลัมน์', 'แถว', 'ทำอะไร'], array_map(fn ($p) => [
            $p['table'], $p['column'], number_format($p['rows']),
            ['delete' => 'ลบ', 'deactivate' => 'ตั้งสาขาว่าง + ปิดใช้งาน', 'null' => 'ตั้งสาขาว่าง', 'block' => 'หยุด'][$p['action']],
        ], $plan));
        if ($locationIds !== []) {
            $this->line('ตำแหน่งเก็บที่ลบตามคลัง: '.number_format(count($locationIds)));
        }
        $deviceIds = $this->ids('pos_devices', $doomedIds);
        foreach (self::DEVICE_LOGS as $table) {
            if ($deviceIds !== [] && Schema::hasTable($table)) {
                $this->line("log เครื่อง POS ที่ลบตามเครื่อง ({$table}): ".number_format(DB::table($table)->whereIn('pos_device_id', $deviceIds)->count()));
            }
        }

        if ($blocking !== []) {
            $this->error('หยุด: ยังมีข้อมูลที่ต้องล้างก่อน (ธุรกรรมหรือยอดคงเหลือ)');
            $this->table(['ตาราง', 'คอลัมน์', 'แถว'], $blocking);

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันลบ '.count($doomedIds).' สาขา?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($plan, $doomedIds, $locationIds, $warehouseIds, $kept, $renames) {
                foreach ($plan as $p) {
                    $query = DB::table($p['table'])->whereIn($p['column'], $doomedIds);
                    match ($p['action']) {
                        'delete' => null,
                        'deactivate' => $query->update([$p['column'] => null, 'is_active' => false]),
                        default => $query->update([$p['column'] => null]),
                    };
                }
                $deviceIds = DB::table('pos_devices')->whereIn('branch_id', $doomedIds)->pluck('id')->all();
                foreach (self::DEVICE_LOGS as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->whereIn('pos_device_id', $deviceIds)->delete();
                    }
                }
                DB::table('branches')->whereIn('id', $doomedIds)->update(['default_warehouse_location_id' => null]);
                foreach (self::DELETE_WITH_BRANCH as $table) {
                    if ($table === 'warehouses') {
                        DB::table('warehouse_locations')->whereIn('id', $locationIds)->delete();
                        DB::table('warehouses')->whereIn('id', $warehouseIds)->delete();

                        continue;
                    }
                    DB::table($table)->whereIn('branch_id', $doomedIds)->delete();
                }
                DB::table('branches')->whereIn('id', $doomedIds)->delete();

                foreach ($renames as $from => $to) {
                    DB::table('branches')->where('id', $kept[$from])->update(['code' => $to, 'updated_at' => now()]);
                    DB::table('warehouses')->where('branch_id', $kept[$from])->where('code', 'WH-'.$from)->update(['code' => 'WH-'.$to]);
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกแก้ (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('จัดสาขาใหม่แล้ว: เหลือ '.implode(', ', array_map(fn ($code) => $renames[$code] ?? $code, $kept->keys()->all())));

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function ids(string $table, array $branchIds): array
    {
        return $branchIds === [] ? [] : DB::table($table)->whereIn('branch_id', $branchIds)->pluck('id')->all();
    }

    /** @return array<int, array{0:string, 1:string, 2:bool}> */
    private function foreignKeysTo(string $target): array
    {
        $found = [];
        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;
            try {
                $foreignKeys = Schema::getForeignKeys($table);
            } catch (Throwable) {
                continue;
            }
            foreach ($foreignKeys as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === $target && $table !== $target) {
                    $column = $foreignKey['columns'][0];
                    $nullable = collect(Schema::getColumns($table))->firstWhere('name', $column)['nullable'] ?? false;
                    $found[] = [$table, $column, (bool) $nullable];
                }
            }
        }

        return $found;
    }
}
