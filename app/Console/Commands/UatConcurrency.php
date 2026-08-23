<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Document;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ยิงงานพร้อมกันจริงด้วยหลาย process เพื่อพิสูจน์ว่าระบบทนการใช้งานพร้อมกันได้
 *
 * ใช้ fork จริง ไม่ใช่ loop เพราะปัญหาที่ต้องการจับ (เลขเอกสารชนกัน, ล็อก, deadlock)
 * เกิดเฉพาะตอนมีหลาย transaction เปิดพร้อมกันจริงเท่านั้น loop เดียวจับไม่ได้เลย
 *
 * ห้ามรันกับฐานข้อมูลจริง — สั่งได้เฉพาะตอน APP_ENV ไม่ใช่ production
 * และต้องระบุ --confirm-test-database เพื่อกันการเผลอ
 *
 *   php artisan uat:concurrency --users=30 --per-user=5 --branch=1 --confirm-test-database
 */
class UatConcurrency extends Command
{
    protected $signature = 'uat:concurrency
        {--users=10 : จำนวน process ที่ยิงพร้อมกัน}
        {--per-user=5 : จำนวนเอกสารต่อ process}
        {--branch= : id ของสาขาที่ใช้ทดสอบ}
        {--type=CASH_SALE : ชนิดเอกสารที่จะขอเลขที่ (โหมด number)}
        {--mode=number : number = ขอเลขอย่างเดียว, sale = ขายจริงทั้งวงจร}
        {--confirm-test-database : ยืนยันว่าฐานนี้เป็นฐานทดสอบ}
        {--confirm-empty-production-database= : รันบน production ที่ล้างข้อมูลแล้ว ต้องพิมพ์ชื่อฐานให้ตรง}
        {--product-prefix=UAT- : ขึ้นต้น sku_code ของสินค้าที่ใช้ทดสอบ (โหมด sale)}';

    protected $description = 'Concurrency UAT: prove document numbers stay unique under parallel load';

    /**
     * ยอมให้ยิงโหลดบน production ได้เฉพาะตอนที่ฐานไม่มีธุรกรรมจริงเหลืออยู่
     *
     * ด่านนี้มีไว้กันการยิงโหลดทับข้อมูลจริง ไม่ได้มีไว้กัน UAT บนฐานที่เพิ่งล้าง
     * จึงเปิดทางออกที่ตรวจสอบได้ แทนที่จะให้คนไป override APP_ENV เอา
     * ซึ่งจะปิดด่านทั้งหมดโดยไม่เหลือร่องรอย
     */
    private function productionRunAllowed(): bool
    {
        $database = DB::connection()->getDatabaseName();

        if ($this->option('confirm-empty-production-database') !== $database) {
            $this->error('ห้ามรันบน production — ถ้าฐานนี้ล้างข้อมูลแล้วและตั้งใจทำ UAT');
            $this->line("ให้ระบุ --confirm-empty-production-database={$database}");

            return false;
        }

        foreach (['documents', 'pos_receipts'] as $table) {
            if (($rows = DB::table($table)->count()) > 0) {
                $this->error("ห้ามรันบน production ที่ยังมีข้อมูลจริง — {$table} มี ".number_format($rows).' แถว');
                $this->line('ล้างด้วย erp:reset-transactions ก่อน แล้วค่อยยิง UAT');

                return false;
            }
        }

        $this->warn("ยิงโหลดบน production ({$database}) ที่ล้างข้อมูลแล้ว — เอกสารที่เกิดขึ้นเป็นของ UAT ทั้งหมด");

        return true;
    }

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->productionRunAllowed()) {
            return self::FAILURE;
        }
        if (! $this->option('confirm-test-database')) {
            $this->error('ต้องใส่ --confirm-test-database เพื่อยืนยันว่าฐานนี้เป็นฐานทดสอบ');

            return self::FAILURE;
        }
        if ($this->option('mode') === 'sale' && $this->testProductIds() === []) {
            $prefix = $this->option('product-prefix');
            $this->error("โหมด sale ต้องมีสินค้าที่ sku_code ขึ้นต้นด้วย {$prefix} แต่ไม่พบเลยในฐานนี้");
            $this->line('สร้างสินค้าสำหรับ UAT ก่อน หรือระบุ --product-prefix ให้ตรงกับที่มีอยู่');
            $this->line('อย่าชี้ไปที่สินค้าจริง เพราะการขายจะขยับต้นทุนถัวเฉลี่ยของสินค้านั้นถาวร');

            return self::FAILURE;
        }
        if (! function_exists('pcntl_fork')) {
            $this->error('ต้องมี pcntl ถึงจะยิงพร้อมกันจริงได้');

            return self::FAILURE;
        }

        $users = max(1, (int) $this->option('users'));
        $perUser = max(1, (int) $this->option('per-user'));
        $type = (string) $this->option('type');
        $branchId = (int) ($this->option('branch') ?: Branch::value('id'));
        if (! $branchId) {
            $this->error('ไม่พบสาขาสำหรับทดสอบ');

            return self::FAILURE;
        }

        $this->info("ยิง {$users} process × {$perUser} เอกสาร ({$type}) ที่สาขา {$branchId}");
        $startedAt = microtime(true);
        $resultDir = storage_path('app/uat-concurrency');
        if (! is_dir($resultDir)) {
            mkdir($resultDir, 0775, true);
        }
        array_map('unlink', glob($resultDir.'/*.json') ?: []);

        $children = [];
        for ($worker = 0; $worker < $users; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->error('fork ไม่สำเร็จ');

                return self::FAILURE;
            }
            if ($pid === 0) {
                $this->option('mode') === 'sale'
                    ? $this->runSaleWorker($worker, $perUser, $branchId, $resultDir)
                    : $this->runWorker($worker, $perUser, $type, $branchId, $resultDir);
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return $this->report($resultDir, microtime(true) - $startedAt, $users * $perUser);
    }

    /** ลูกแต่ละตัวเปิด connection ของตัวเอง แล้วสร้างเอกสารจริงตามจำนวนที่สั่ง */
    private function runWorker(int $worker, int $perUser, string $type, int $branchId, string $resultDir): void
    {
        DB::purge();
        $numbers = [];
        $errors = [];
        $latencies = [];

        for ($index = 0; $index < $perUser; $index++) {
            $begin = microtime(true);
            try {
                DB::transaction(function () use ($type, $branchId, &$numbers) {
                    $number = app(DocumentNumberGenerator::class)->next($type, $branchId);
                    Document::create([
                        'document_type_id' => DB::table('document_types')->where('code', $type)->value('id'),
                        'branch_id' => $branchId,
                        'doc_number' => $number,
                        'doc_date' => now()->toDateString(),
                        'status' => 'active',
                        'total_items' => 0,
                        'total_amount' => 0,
                    ]);
                    $numbers[] = $number;
                });
            } catch (Throwable $exception) {
                $errors[] = [
                    'message' => mb_substr($exception->getMessage(), 0, 200),
                    'deadlock' => str_contains(strtolower($exception->getMessage()), 'deadlock'),
                ];
            }
            $latencies[] = round((microtime(true) - $begin) * 1000, 2);
        }

        file_put_contents(
            $resultDir.'/worker-'.$worker.'.json',
            json_encode(compact('numbers', 'errors', 'latencies'), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * สินค้าที่ใช้ยิงทดสอบ — จำกัดไว้ที่สินค้าของ UAT เท่านั้น
     *
     * การขายจะไปขยับ average_cost กับสต๊อกของสินค้าที่ถูกเลือก จึงต้องไม่เผลอ
     * ไปหยิบสินค้าจริงมายิง ไม่งั้นต้นทุนสินค้าจริงจะเพี้ยนโดยไม่มีใครสังเกต
     *
     * @return array<int, int>
     */
    private function testProductIds(): array
    {
        return DB::table('products')
            ->where('sku_code', 'like', $this->option('product-prefix').'%')
            ->pluck('id')
            ->all();
    }

    /**
     * ขายจริงทั้งวงจร: ตัดสต๊อก คิดต้นทุน ลง GL และเข้า sales_postings
     * ใช้พิสูจน์ว่ายิงพร้อมกันแล้วสต๊อก ต้นทุน และบัญชีไม่เพี้ยน ไม่ใช่แค่เลขไม่ซ้ำ
     */
    private function runSaleWorker(int $worker, int $perUser, int $branchId, string $resultDir): void
    {
        DB::purge();
        $numbers = [];
        $errors = [];
        $latencies = [];
        $productIds = $this->testProductIds();

        for ($index = 0; $index < $perUser; $index++) {
            $begin = microtime(true);
            try {
                $document = app(\App\Services\Sales\CashSaleService::class)->create([
                    'branch_id' => $branchId,
                    'items' => [[
                        'product_id' => $productIds[($worker + $index) % count($productIds)],
                        'qty' => 1,
                        'unit_price' => 100,
                    ]],
                    'allow_negative_stock' => true,
                ]);
                $numbers[] = $document->doc_number;
            } catch (Throwable $exception) {
                $errors[] = [
                    'message' => mb_substr($exception->getMessage(), 0, 200),
                    'deadlock' => str_contains(strtolower($exception->getMessage()), 'deadlock'),
                ];
            }
            $latencies[] = round((microtime(true) - $begin) * 1000, 2);
        }

        file_put_contents(
            $resultDir.'/worker-'.$worker.'.json',
            json_encode(compact('numbers', 'errors', 'latencies'), JSON_UNESCAPED_UNICODE),
        );
    }

    private function report(string $resultDir, float $elapsed, int $attempted): int
    {
        $numbers = [];
        $errors = [];
        $latencies = [];
        foreach (glob($resultDir.'/worker-*.json') ?: [] as $file) {
            $payload = json_decode((string) file_get_contents($file), true) ?: [];
            $numbers = array_merge($numbers, $payload['numbers'] ?? []);
            $errors = array_merge($errors, $payload['errors'] ?? []);
            $latencies = array_merge($latencies, $payload['latencies'] ?? []);
        }

        sort($latencies);
        $p95 = $latencies === [] ? 0 : $latencies[(int) floor(count($latencies) * 0.95) - 1] ?? end($latencies);
        $duplicates = count($numbers) - count(array_unique($numbers));
        $deadlocks = count(array_filter($errors, fn (array $error) => $error['deadlock']));

        $this->table(['ตัวชี้วัด', 'ค่า'], [
            ['เอกสารที่พยายามสร้าง', $attempted],
            ['สร้างสำเร็จ', count($numbers)],
            ['ผิดพลาด', count($errors)],
            ['deadlock', $deadlocks],
            ['เลขเอกสารซ้ำ', $duplicates],
            ['เวลารวม (วินาที)', round($elapsed, 2)],
            ['throughput (เอกสาร/วินาที)', $elapsed > 0 ? round(count($numbers) / $elapsed, 1) : 0],
            ['p95 latency (ms)', $p95],
        ]);

        if ($errors !== []) {
            $this->warn('ตัวอย่างข้อผิดพลาด:');
            foreach (array_slice($errors, 0, 3) as $error) {
                $this->line('  '.$error['message']);
            }
        }

        $passed = $duplicates === 0 && $errors === [];
        $this->line('');
        $this->line($passed ? '<info>ผ่าน: ไม่มีเลขซ้ำและไม่มีข้อผิดพลาด</info>' : '<error>ไม่ผ่าน</error>');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
