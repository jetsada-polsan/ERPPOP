<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replace legacy shared warehouses with one warehouse and one MAIN location per active branch.
 *
 * Stock is moved first. The command refuses to run when a location has transaction history that
 * would be lost by deleting the legacy warehouse.
 */
class RebuildBranchWarehouses extends Command
{
    protected $signature = 'erp:rebuild-branch-warehouses
        {--confirm-database= : Database name must match exactly}
        {--dry-run : Show the plan without writing data}
        {--force : Skip the interactive confirmation}';

    protected $description = 'สร้างคลังหลักหนึ่งคลังต่อสาขา ย้ายยอดสต๊อก แล้วลบคลังเก่าที่ไม่ใช้';

    /** Old locations with live stock that cannot be inferred from the current branch default. */
    private const LEGACY_LOCATION_BRANCH = [
        'HQ-02' => 'HQ',
        'OLD-001' => 'B001',
        'OLD-009' => 'B003',
        'OLD-011' => 'B004',
        'OLD-012' => 'B005',
        'OLD-016' => 'B007',
    ];

    /** Tables whose records must never be removed merely to rebuild master warehouse data. */
    private const BLOCKING_HISTORY_TABLES = [
        'stock_movements',
        'stock_document_items',
        'stock_lots',
        'stock_counts',
    ];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        $plan = $this->plan();
        if ($plan['problems'] !== []) {
            $this->error('หยุดก่อนเขียนข้อมูล:');
            foreach ($plan['problems'] as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $this->table(['สาขา', 'คลังใหม่', 'พื้นที่ใหม่', 'ยอดสต๊อกที่จะย้าย'], $plan['branches']);
        $this->line(sprintf('ยอดสต๊อก: %d แถว · คลังเก่าที่จะลบ: %d คลัง', $plan['stock_rows'], $plan['legacy_warehouse_count']));

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('สร้างคลังใหม่ ย้ายสต๊อก และลบคลังเก่า?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($plan) {
                $targets = [];
                foreach ($plan['branches'] as $branch) {
                    [$branchCode, $warehouseCode, $locationCode] = $branch;
                    $branchModel = Branch::where('code', $branchCode)->lockForUpdate()->sole();
                    $warehouseId = DB::table('warehouses')->insertGetId([
                        'branch_id' => $branchModel->id,
                        'code' => $warehouseCode,
                        'name' => 'คลังหลัก - '.$branchModel->name_th,
                    ]);
                    $locationId = DB::table('warehouse_locations')->insertGetId([
                        'warehouse_id' => $warehouseId,
                        'code' => $locationCode,
                        'name' => 'พื้นที่หลัก - '.$branchModel->name_th,
                    ]);
                    $targets[$branchCode] = $locationId;
                }

                foreach ($plan['balance_sources'] as $source) {
                    DB::table('stock_balances')
                        ->where('warehouse_location_id', $source['location_id'])
                        ->update(['warehouse_location_id' => $targets[$source['branch_code']]]);
                }

                foreach ($targets as $branchCode => $locationId) {
                    Branch::where('code', $branchCode)->update(['default_warehouse_location_id' => $locationId]);
                }

                // Inactive duplicate branches must not keep a pointer to a location being deleted.
                Branch::where('is_active', false)
                    ->whereIn('default_warehouse_location_id', $plan['legacy_location_ids'])
                    ->update(['default_warehouse_location_id' => null]);

                DB::table('warehouses')->whereIn('id', $plan['legacy_warehouse_ids'])->delete();
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลวและ rollback แล้ว: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('สร้างคลังใหม่ ผูกสาขา ย้ายยอดสต๊อก และลบคลังเดิมเรียบร้อย');

        return self::SUCCESS;
    }

    /** @return array{branches: array<int, array<int, string|int>>, balance_sources: array<int, array{location_id:int,branch_code:string}>, legacy_location_ids: array<int,int>, legacy_warehouse_ids: array<int,int>, legacy_warehouse_count:int, stock_rows:int, problems:array<int,string>} */
    private function plan(): array
    {
        $problems = [];
        foreach (self::BLOCKING_HISTORY_TABLES as $table) {
            $count = DB::table($table)->count();
            if ($count > 0) {
                $problems[] = "{$table} มี {$count} แถว — ห้ามลบคลังเก่าที่มีประวัติ";
            }
        }

        $activeBranches = Branch::where('is_active', true)->orderByRaw("case when code = 'HQ' then 0 else 1 end")->orderBy('code')->get();
        if ($activeBranches->isEmpty()) {
            $problems[] = 'ไม่พบสาขาที่ใช้งาน';
        }

        $sourceByLocation = [];
        foreach ($activeBranches as $branch) {
            if (! $branch->default_warehouse_location_id) {
                $problems[] = "สาขา {$branch->code} ไม่มีพื้นที่เก็บเริ่มต้น";
                continue;
            }
            $sourceByLocation[(int) $branch->default_warehouse_location_id] = $branch->code;
        }

        foreach (self::LEGACY_LOCATION_BRANCH as $locationCode => $branchCode) {
            $locationId = DB::table('warehouse_locations')->where('code', $locationCode)->value('id');
            if ($locationId !== null) {
                $sourceByLocation[(int) $locationId] = $branchCode;
            }
        }

        $balanceRows = DB::table('stock_balances')
            ->select('warehouse_location_id', DB::raw('count(*) as rows'))
            ->groupBy('warehouse_location_id')->get();
        $balanceSources = [];
        foreach ($balanceRows as $row) {
            $locationId = (int) $row->warehouse_location_id;
            $branchCode = $sourceByLocation[$locationId] ?? null;
            if ($branchCode === null) {
                $location = DB::table('warehouse_locations')->where('id', $locationId)->first(['code', 'name']);
                $problems[] = "ยอดสต๊อก {$row->rows} แถวอยู่ที่พื้นที่ {$locationId} ({$location->code}/{$location->name}) ซึ่งไม่รู้ว่าเป็นของสาขาใด";
                continue;
            }
            $balanceSources[] = ['location_id' => $locationId, 'branch_code' => $branchCode];
        }

        $branches = $activeBranches->map(function (Branch $branch) use ($balanceSources) {
            $rows = collect($balanceSources)->where('branch_code', $branch->code)->sum(function ($source) {
                return DB::table('stock_balances')->where('warehouse_location_id', $source['location_id'])->count();
            });

            return [$branch->code, 'WH-'.$branch->code, 'MAIN', $rows];
        })->all();

        $legacyWarehouseIds = DB::table('warehouses')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $legacyLocationIds = DB::table('warehouse_locations')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'branches' => $branches,
            'balance_sources' => $balanceSources,
            'legacy_location_ids' => $legacyLocationIds,
            'legacy_warehouse_ids' => $legacyWarehouseIds,
            'legacy_warehouse_count' => count($legacyWarehouseIds),
            'stock_rows' => (int) DB::table('stock_balances')->count(),
            'problems' => $problems,
        ];
    }
}
