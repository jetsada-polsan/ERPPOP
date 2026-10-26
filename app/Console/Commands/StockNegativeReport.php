<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * รายงานสินค้าที่สต๊อกติดลบ และ (ถ้าสั่ง) ยกยอดให้กลับมาเป็นศูนย์.
 *
 * สต๊อกติดลบเกิดจากการขาย/ตัดของที่ไม่เคยรับเข้าระบบ ทำให้มูลค่าสินค้าคงเหลือใน
 * GL 1140-00 ติดลบตามไปด้วย --fix-opening ใช้ OpeningBalanceService ชุดเดียวกับ
 * erp:opening-balances จึงได้ทั้งการลง GL คู่กับบัญชีพักยกยอด และ run ที่กันทำซ้ำ.
 */
class StockNegativeReport extends Command
{
    protected $signature = 'erp:stock-negative-report
        {--branch= : จำกัดเฉพาะสาขา (code หรือ id)}
        {--csv= : เขียนผลเป็นไฟล์ CSV ตาม path ที่ระบุ}
        {--fix-opening : ยกยอดให้รายการที่ติดลบกลับมาเป็น 0 (ลง GL ด้วย)}
        {--as-of= : วันที่ยกยอด (YYYY-MM-DD) ค่าเริ่มต้น = วันนี้}
        {--dry-run : แสดงผลที่จะเกิดขึ้นโดยไม่แก้ข้อมูล}';

    protected $description = 'Report products with negative stock, optionally opening-balance them back to zero';

    public function handle(OpeningBalanceService $openingBalances): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixOpening = (bool) $this->option('fix-opening');
        $asOf = (string) ($this->option('as-of') ?: now()->toDateString());

        try {
            $asOf = Carbon::createFromFormat('Y-m-d', $asOf)->toDateString();
        } catch (\Throwable) {
            $this->error('รูปแบบ --as-of ต้องเป็น YYYY-MM-DD');

            return self::FAILURE;
        }

        $branch = null;
        if ($key = (string) $this->option('branch')) {
            // หา code ก่อนเสมอ: รหัสอย่าง "0001" ถ้าแปลงเป็นเลขไปจับ id จะได้คนละสาขา
            $branch = Branch::where('code', $key)->first()
                ?? (ctype_digit($key) ? Branch::find((int) $key) : null);
            if (! $branch) {
                $this->error("ไม่พบสาขา [{$key}]");

                return self::FAILURE;
            }
        }

        $rows = $openingBalances->negativeStockRows($branch?->id);

        if ($rows->isEmpty()) {
            $this->info($branch ? "สาขา {$branch->code}: ไม่มีสินค้าสต๊อกติดลบ" : 'ไม่มีสินค้าสต๊อกติดลบ');

            return self::SUCCESS;
        }

        $branchCodes = Branch::whereIn('id', $rows->pluck('branch_id')->filter()->unique())->pluck('code', 'id');

        $this->warn("พบสินค้าสต๊อกติดลบ {$rows->count()} รายการ");
        $this->newLine();
        $this->table(
            ['สาขา', 'คลัง', 'รหัสสินค้า', 'ชื่อสินค้า', 'คงเหลือ', 'ต้นทุน/หน่วย'],
            $rows->map(fn ($row) => [
                $branchCodes[$row->branch_id] ?? '(ไม่มีเจ้าของ)',
                $row->location_code,
                $row->sku_code,
                mb_strimwidth((string) $row->name_th, 0, 40, '...'),
                number_format((float) $row->on_hand_qty, 4),
                number_format((float) $row->average_cost, 2),
            ])->all(),
        );

        if ($path = (string) $this->option('csv')) {
            $this->writeCsv($path, $rows, $branchCodes->all());
            $this->info("เขียน CSV แล้ว: {$path}");
        }

        if (! $fixOpening) {
            $this->newLine();
            $this->line('ใช้ --fix-opening เพื่อยกยอดให้กลับมาเป็น 0 พร้อมลง GL (ลองด้วย --dry-run ก่อนได้)');

            return self::SUCCESS;
        }

        $orphans = $rows->whereNull('branch_id');
        if ($orphans->isNotEmpty()) {
            $this->warn("ข้าม {$orphans->count()} รายการที่หาสาขาเจ้าของคลังไม่เจอ - ผูกคลังกับสาขาก่อนแล้วรันใหม่");
        }

        $byBranch = $rows->whereNotNull('branch_id')->groupBy('branch_id');
        $this->newLine();
        foreach ($byBranch as $branchId => $branchRows) {
            $value = $branchRows->sum(fn ($row) => round(abs((float) $row->on_hand_qty) * max(0, (float) $row->average_cost), 2));
            $this->line(sprintf(
                'สาขา %s: ยกยอด %d รายการ มูลค่า %s บาท (Dr สินค้าคงเหลือ / Cr บัญชีพักยกยอด) ณ %s',
                $branchCodes[$branchId] ?? $branchId,
                $branchRows->count(),
                number_format($value, 2),
                $asOf,
            ));
        }

        if ($dryRun) {
            $this->info('DRY RUN: ยังไม่มีการแก้ข้อมูล');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($byBranch->keys() as $branchId) {
            try {
                $result = $openingBalances->postNegativeStock((int) $branchId, $asOf);
                $this->info(sprintf('สาขา %s: ยกยอดแล้ว %d รายการ %s บาท', $branchCodes[$branchId] ?? $branchId, $result['lines'], number_format($result['total'], 2)));
            } catch (RuntimeException $exception) {
                $failed = true;
                $this->error(sprintf('สาขา %s: %s', $branchCodes[$branchId] ?? $branchId, $exception->getMessage()));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<int, string>  $branchCodes */
    private function writeCsv(string $path, $rows, array $branchCodes): void
    {
        $handle = fopen($path, 'wb');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่านภาษาไทยถูก
        fputcsv($handle, ['branch_code', 'location_code', 'sku_code', 'name_th', 'on_hand_qty', 'average_cost']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $branchCodes[$row->branch_id] ?? '',
                $row->location_code,
                $row->sku_code,
                $row->name_th,
                $row->on_hand_qty,
                $row->average_cost,
            ]);
        }
        fclose($handle);
    }
}
