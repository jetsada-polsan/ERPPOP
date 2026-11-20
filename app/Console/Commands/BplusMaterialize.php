<?php

namespace App\Console\Commands;

use App\Services\Bplus\BplusDocumentMaterializer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BplusMaterialize extends Command
{
    protected $signature = 'bplus:materialize
        {route=B18 : รหัสสายขาย}
        {--full : ประมวลผลทุกใบ ไม่ข้ามใบที่ไม่เปลี่ยน}
        {--all-routes : ทำทุกสายที่มีสำเนา BPlus (ไม่สนใจ route)}';

    protected $description = 'Turn the BPlus copies of one sales route into ERP bookings, credit sales and receipts (no stock, no GL)';

    public function handle(BplusDocumentMaterializer $materializer): int
    {
        $routes = $this->option('all-routes')
            ? DB::table('bplus_sync_documents')->distinct()->orderBy('route_code')->pluck('route_code')->all()
            : [(string) $this->argument('route')];

        foreach ($routes as $route) {
            $started = microtime(true);
            // ล็อกเดียวกับ API ซิงค์ กันรอบตี 4 กับรอบที่เครื่องร้านส่งมาทำสายเดียวกันพร้อมกัน
            $result = Cache::lock('bplus-sync:'.$route, 600)->block(300, fn () => $materializer->run($route, (bool) $this->option('full')));
            $this->info(sprintf('สาย %s: เอกสาร ERP %d ใบ · ประมวลผลใหม่ %d ใบ · %.1f วินาที',
                $route, $result['documents'], $result['processed'], microtime(true) - $started));
            foreach ($result['problems'] as $ref => $problem) {
                $this->warn("{$ref}: {$problem}");
            }
        }

        return self::SUCCESS;
    }
}
