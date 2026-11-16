<?php

namespace App\Console\Commands;

use App\Services\Bplus\BplusDocumentMaterializer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\TableSeparator;

class BplusRepostGl extends Command
{
    protected $signature = 'bplus:repost-gl {route=B18 : รหัสสายขาย} {--dry-run : แสดงจำนวนใบและยอด Dr/Cr ต่อบัญชี โดยไม่บันทึก}';

    protected $description = 'Repost GL entries for BPlus route documents using current account roles';

    public function handle(BplusDocumentMaterializer $materializer): int
    {
        $route = (string) $this->argument('route');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("สายขาย: {$route}".($dryRun ? ' [DRY-RUN]' : ''));

        $result = $materializer->repostGl($route, $dryRun);

        $rows = [];
        foreach ($result['accounts'] as $item) {
            $rows[] = [
                $item['code'],
                $item['name'],
                number_format((float) $item['debit'], 2),
                number_format((float) $item['credit'], 2),
            ];
        }

        if (! empty($rows)) {
            $rows[] = new TableSeparator;
            $rows[] = [
                'รวม',
                '',
                number_format((float) $result['total_debit'], 2),
                number_format((float) $result['total_credit'], 2),
            ];
        }

        $this->table(['รหัสบัญชี', 'ชื่อบัญชี', 'เดบิต (บาท)', 'เครดิต (บาท)'], $rows);
        $this->line("จำนวนเอกสาร: {$result['count']} ใบ");

        if ($dryRun) {
            $this->comment('โหมด DRY-RUN: ไม่ได้บันทึกการเปลี่ยนแปลงลงสมุดรายวัน');
        } else {
            $this->info("ลงบัญชีใหม่เรียบร้อยแล้ว {$result['count']} ใบ");
        }

        if (! empty($result['problems'])) {
            $this->warn('พบปัญหา '.count($result['problems']).' รายการ:');
            foreach ($result['problems'] as $ref => $msg) {
                $this->warn(" - {$ref}: {$msg}");
            }
        }

        return self::SUCCESS;
    }
}
