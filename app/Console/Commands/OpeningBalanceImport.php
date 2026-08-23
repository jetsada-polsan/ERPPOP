<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\OpeningBalanceRun;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * นำเข้ายอดยกมาจาก CSV
 *
 * หัวคอลัมน์ที่ต้องมี
 *   stock : sku,location,qty,unit_cost
 *   ar    : customer,document_no,document_date,due_date,amount
 *   ap    : supplier,document_no,document_date,due_date,amount
 *   cash  : type,description,amount        (type = cash | bank)
 */
class OpeningBalanceImport extends Command
{
    protected $signature = 'erp:opening-balances
        {kind : stock | ar | ap | cash}
        {--branch= : รหัสสาขา (code) ที่จะยกยอดเข้า}
        {--as-of= : วันที่ยกยอด (YYYY-MM-DD)}
        {--file= : ไฟล์ CSV}
        {--dry-run : ตรวจข้อมูลอย่างเดียว ไม่เขียนอะไรลงฐาน}';

    protected $description = 'ยกยอดตั้งต้นเข้าระบบ: สต๊อก ลูกหนี้ เจ้าหนี้ เงินสด';

    public function handle(OpeningBalanceService $service): int
    {
        $kind = (string) $this->argument('kind');
        if (! in_array($kind, OpeningBalanceService::KINDS, true)) {
            $this->error('ชนิดต้องเป็น '.implode(' / ', OpeningBalanceService::KINDS));

            return self::FAILURE;
        }

        $branch = Branch::where('code', $this->option('branch'))->first();
        if (! $branch) {
            $this->error('ไม่พบสาขา code='.($this->option('branch') ?: '(ไม่ได้ระบุ)'));

            return self::FAILURE;
        }

        $asOf = (string) ($this->option('as-of') ?: '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            $this->error('ต้องระบุ --as-of เป็นวันที่รูปแบบ YYYY-MM-DD');

            return self::FAILURE;
        }

        $file = (string) ($this->option('file') ?: '');
        if (! is_file($file)) {
            $this->error("ไม่พบไฟล์ {$file}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($file);
        if ($rows === []) {
            $this->error('ไฟล์ไม่มีข้อมูล');

            return self::FAILURE;
        }

        $checked = $service->validate($kind, $branch->id, $rows);
        $this->line("สาขา {$branch->code} · ณ {$asOf} · อ่านได้ ".count($rows).' บรรทัด');

        if ($checked['errors'] !== []) {
            $this->error('ข้อมูลไม่ผ่านการตรวจ '.count($checked['errors']).' บรรทัด:');
            foreach (array_slice($checked['errors'], 0, 20) as $error) {
                $this->line('  '.$error);
            }
            if (count($checked['errors']) > 20) {
                $this->line('  ... อีก '.(count($checked['errors']) - 20).' บรรทัด');
            }
            $this->line('');
            $this->line('ไม่มีอะไรถูกเขียนลงฐาน — แก้ไฟล์แล้วรันใหม่');

            return self::FAILURE;
        }

        $this->info(sprintf('ผ่านการตรวจ %d บรรทัด รวม %s บาท', $checked['lines'], number_format($checked['total'], 2)));

        if ($this->option('dry-run')) {
            $this->table(
                ['#', 'อ้างอิง', 'ยอด'],
                collect($checked['preview'])->take(10)
                    ->map(fn ($row, $index) => [$index + 1, $row['label'], number_format($row['amount'], 2)])
                    ->all(),
            );
            $this->info('dry-run: ไม่มีข้อมูลใดถูกเขียน');

            return self::SUCCESS;
        }

        try {
            $result = $service->post($kind, $branch->id, $asOf, $rows, null, basename($file));
        } catch (Throwable $exception) {
            $this->error('ยกยอดไม่สำเร็จ (ไม่มีอะไรถูกเขียน): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('ยกยอด %s สำเร็จ %d บรรทัด รวม %s บาท (run #%d)',
            $kind, $result['lines'], number_format($result['total'], 2), $result['run']->id));
        $this->line(sprintf('ยอดค้างในบัญชีพัก 3030 ตอนนี้: %s บาท', number_format($service->suspenseBalance(), 2)));

        $done = OpeningBalanceRun::where('branch_id', $branch->id)->pluck('kind')->all();
        $left = array_diff(OpeningBalanceService::KINDS, $done);
        $this->line($left === []
            ? 'ยกครบทุกชนิดแล้วสำหรับสาขานี้ — ให้นักบัญชีปิดบัญชีพัก 3030 เข้ากำไรสะสม'
            : 'ยังเหลือ: '.implode(', ', $left));

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'rb');
        if (! $handle) {
            return [];
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return [];
        }
        // ตัด BOM ที่ Excel ใส่มาให้ ไม่งั้นคอลัมน์แรกจะหาไม่เจอ
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
        $header = array_map(fn ($column) => strtolower(trim((string) $column)), $header);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }
        fclose($handle);

        return $rows;
    }
}
