<?php

namespace App\Console\Commands;

use App\Support\PosReleasePruner;
use Illuminate\Console\Command;

class PrunePosReleases extends Command
{
    protected $signature = 'pos:prune-releases {--dry-run : แสดงรายการที่จะลบโดยไม่ลบไฟล์}';

    protected $description = 'เก็บไฟล์ติดตั้ง POS รุ่นล่าสุดและไฟล์ที่ manifest อ้างถึง';

    public function handle(PosReleasePruner $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = $pruner->prune($dryRun);
        if ($rows === []) {
            $this->info('ไม่พบไฟล์ที่ต้องลบ');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(($dryRun ? '[DRY RUN] ' : '[ลบแล้ว] ').$row['path'].' · '.$this->formatBytes($row['size']));
        }
        $this->info($dryRun ? 'จบการตรวจแบบ dry-run ไม่มีการลบไฟล์' : 'ลบไฟล์เก่าแล้ว '.count($rows).' รายการ');

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024 / 1024, 2).' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2).' MB';
        }

        return number_format($bytes / 1024, 2).' KB';
    }
}
