<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ล้างข้อมูลธุรกรรมทั้งหมด เก็บแฟ้มหลักไว้ — ใช้ก่อนเริ่ม UAT ทั้งระบบ
 *
 * ลบแล้วย้อนไม่ได้นอกจากกู้จาก backup จึงมีด่านกันหลายชั้น:
 *   1. ต้องมี backup ที่อายุไม่เกิน 2 ชั่วโมงและ checksum ผ่าน
 *   2. ต้องพิมพ์ชื่อฐานข้อมูลให้ตรงกับฐานที่ต่ออยู่
 *   3. ตรวจก่อนว่ามีตารางนอก whitelist อ้างถึงตารางที่จะล้างหรือไม่ ถ้ามีให้หยุด
 *   4. แสดงจำนวนแถวที่จะลบก่อนถามยืนยันครั้งสุดท้าย
 *
 * **ไม่ใช้ CASCADE โดยเจตนา** — CASCADE จะลากตารางที่ไม่ได้อยู่ใน whitelist ไปลบด้วย
 * โดยไม่บอกใคร ซึ่งเป็นวิธีที่ข้อมูลหายแบบไม่มีใครรู้ตัว ถ้าติด foreign key
 * คำสั่งนี้จะหยุดและบอกชื่อตารางที่ติด ให้คนตัดสินใจเพิ่มเข้า whitelist เองอย่างตั้งใจ
 *
 * สิ่งที่ไม่ลบ: สินค้า ลูกค้า ผู้ขาย ผู้ใช้ สิทธิ์ ผังบัญชี ทะเบียนรายงาน
 * ตั้งค่าระบบ สาขา คลัง เครื่อง POS และ audit_logs
 */
class ErpResetTransactions extends Command
{
    protected $signature = 'erp:reset-transactions
        {--confirm-database= : ชื่อฐานข้อมูลที่ต้องการล้าง ต้องพิมพ์ให้ตรง}
        {--dry-run : แสดงสิ่งที่จะลบและสิ่งที่จะคงไว้ โดยไม่แก้ข้อมูลใด ๆ}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'ล้างข้อมูลธุรกรรมทั้งหมดเพื่อเริ่ม UAT ใหม่ (เก็บแฟ้มหลัก)';

    /**
     * ตารางธุรกรรมที่จะถูกล้าง — เรียงจากตารางแม่ไปตารางลูก
     * ตอนลบจะไล่ย้อนจากท้ายขึ้นต้น เพื่อให้ลบลูกก่อนแม่เสมอ
     */
    public const TRANSACTIONAL = [
        'documents', 'stock_documents', 'stock_document_items', 'gl_journals', 'document_sequences',
        'stock_movements', 'stock_lots', 'stock_lot_quality_checks', 'stock_counts', 'stock_count_items',
        'pos_receipts', 'pos_receipt_items', 'pos_receipt_discounts', 'pos_payments', 'pos_shifts',
        'pos_cash_movements', 'pos_held_bills', 'pos_receipt_returns', 'pos_logs', 'pos_preparation_jobs',
        'sale_bookings', 'customer_open_items', 'customer_ledger', 'billing_notes', 'billing_note_items',
        'purchase_orders', 'purchase_order_items', 'purchase_order_receipts', 'purchase_quotes',
        'purchase_quote_items', 'supplier_ledger', 'supplier_open_items', 'quotations', 'quotation_items',
        'payment_documents', 'payment_lines', 'payment_allocations', 'cheques',
        'cash_books', 'bank_statements', 'bank_reconciliations', 'branch_expenses',
        'production_orders', 'production_order_items', 'production_batches', 'production_batch_packages',
        'depreciation_records', 'tax_filing_runs', 'etax_documents', 'accounting_export_runs',
        'member_point_transactions', 'ecommerce_orders', 'ecommerce_order_items',
        'pos_coupons', 'pos_receipt_return_items',
        'stock_lot_lineages', 'recall_cases', 'recall_contacts',
        // staging ของท่อนำเข้า POS เก่าที่ถอดออกไปแล้ว
        'imported_payments', 'imported_receipt_items', 'imported_receipts',
        'import_errors', 'import_files', 'import_batches',
    ];

    /** ตารางที่ต้องคงอยู่เสมอ ใช้แสดงในรายงานก่อน/หลัง */
    private const PRESERVED = [
        'products', 'customers', 'suppliers', 'users', 'roles', 'permissions',
        'chart_of_accounts', 'report_definitions', 'app_settings', 'audit_logs',
        'branches', 'pos_devices', 'employees', 'salesmen',
    ];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        // dry-run ไม่แตะข้อมูล จึงไม่ต้องบังคับ backup — แต่ของจริงต้องมี
        if (! $dryRun && ! $this->hasFreshBackup()) {
            return self::FAILURE;
        }

        $present = array_values(array_filter(self::TRANSACTIONAL, fn ($table) => Schema::hasTable($table)));

        if ($blocking = $this->foreignKeysFromOutsideWhitelist($present)) {
            $this->error('หยุด: มีตารางนอก whitelist อ้างถึงตารางที่จะล้าง');
            $this->table(['ตารางที่อ้างถึง (นอก whitelist)', 'อ้างไปที่', 'foreign key'], $blocking);
            $this->line('');
            $this->line('ไม่ใช้ CASCADE โดยเจตนา — ต้องตัดสินใจเองว่าจะเพิ่มตารางเหล่านี้เข้า whitelist');
            $this->line('หรือจัดการข้อมูลในนั้นก่อน แล้วค่อยรันใหม่');

            return self::FAILURE;
        }

        $counts = [];
        $total = 0;
        foreach ($present as $table) {
            $rows = DB::table($table)->count();
            $total += $rows;
            if ($rows > 0) {
                $counts[] = [$table, number_format($rows)];
            }
        }

        $stockBalanceRows = Schema::hasTable('stock_balances') ? DB::table('stock_balances')->count() : 0;
        $stockToReset = $stockBalanceRows > 0
            ? DB::table('stock_balances')->where(fn ($query) => $query->where('on_hand_qty', '<>', 0)->orWhere('reserved_qty', '<>', 0))->count()
            : 0;

        $this->line("ฐานข้อมูล: {$database}");
        if ($counts !== []) {
            $this->table(['ตาราง', 'แถวที่จะลบ'], $counts);
        }
        $this->line(sprintf('รวมแถวที่จะลบ: %s จาก %d ตาราง', number_format($total), count($present)));
        $this->line(sprintf('stock_balances: %s แถว (จะตั้งเป็นศูนย์ %s แถวที่ยังไม่เป็นศูนย์)',
            number_format($stockBalanceRows), number_format($stockToReset)));

        $this->line('');
        $this->line('คงไว้ไม่แตะ:');
        foreach (self::PRESERVED as $table) {
            if (Schema::hasTable($table)) {
                $this->line(sprintf('  %-22s %s', $table, number_format(DB::table($table)->count())));
            }
        }

        if ($dryRun) {
            $this->line('');
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        if ($total === 0 && $stockToReset === 0) {
            $this->info('ไม่มีข้อมูลธุรกรรมให้ล้าง');

            return self::SUCCESS;
        }

        $this->warn(sprintf('จะลบ %s แถว จากฐาน %s — ย้อนกลับได้ด้วย backup เท่านั้น', number_format($total), $database));

        if (! $this->option('force') && ! $this->confirm('ยืนยันล้างข้อมูลธุรกรรม?', false)) {
            $this->info('ยกเลิก ไม่มีอะไรถูกลบ');

            return self::SUCCESS;
        }

        try {
            // ล้างตารางกับตั้งสต๊อกเป็นศูนย์ต้องอยู่ใน transaction เดียวกัน
            // ถ้าพังกลางทางแล้วสต๊อกไม่ถูกตั้งใหม่ จะเหลือยอดคงเหลือลอยโดยไม่มี lot รองรับ
            DB::transaction(function () use ($present) {
                $this->truncate($present);
                DB::table('stock_balances')->update(['on_hand_qty' => 0, 'reserved_qty' => 0]);
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกลบ (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('ล้างแล้ว %s แถว · stock_balances ตั้งเป็นศูนย์ %s แถว',
            number_format($total), number_format($stockBalanceRows)));

        return self::SUCCESS;
    }

    /**
     * ล้างตารางตาม whitelist โดยไม่ใช้ CASCADE
     *
     * PostgreSQL ล้างได้ในคำสั่งเดียวและอยู่ใน transaction ได้ ส่วน driver อื่น
     * (SQLite ในชุดเทสต์) ไม่มี TRUNCATE จึงใช้ DELETE ไล่จากตารางลูกขึ้นไปหาแม่
     *
     * @param  array<int, string>  $tables
     */
    protected function truncate(array $tables): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(fn ($table) => '"'.$table.'"', $tables));
            DB::statement('TRUNCATE '.$quoted.' RESTART IDENTITY');

            return;
        }

        foreach (array_reverse($tables) as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * ตารางนอก whitelist ที่มี foreign key ชี้มาที่ตารางที่จะล้าง
     *
     * ตรวจจากโครงสร้างก่อนลงมือ ไม่ใช่รอให้ฐานข้อมูลฟ้องแล้วค่อยเดาจากข้อความ error
     * เพราะ SQLite ฟ้องแบบไม่บอกชื่อตาราง และ PostgreSQL บอกทีละตัวเท่านั้น
     *
     * @param  array<int, string>  $whitelist
     * @return array<int, array<int, string>>
     */
    private function foreignKeysFromOutsideWhitelist(array $whitelist): array
    {
        $blocking = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;
            if (in_array($table, $whitelist, true)) {
                continue;
            }

            try {
                $foreignKeys = Schema::getForeignKeys($table);
            } catch (Throwable) {
                continue;   // บาง driver อ่าน foreign key ของ view ไม่ได้
            }

            foreach ($foreignKeys as $foreignKey) {
                $target = $foreignKey['foreign_table'] ?? null;
                if ($target !== null && in_array($target, $whitelist, true)) {
                    $blocking[] = [$table, $target, implode(',', $foreignKey['columns'] ?? [])];
                }
            }
        }

        return $blocking;
    }

    /** ไม่มี backup สดที่ตรวจ checksum แล้ว = ไม่ให้ลบ */
    private function hasFreshBackup(): bool
    {
        $latest = collect(glob(storage_path('app/backups/erp-db-*.sql.gz')) ?: [])
            ->sortByDesc(fn (string $file) => filemtime($file))
            ->first();

        if (! $latest) {
            $this->error('ไม่พบไฟล์สำรอง — รัน php artisan erp:backup ก่อน');

            return false;
        }

        $ageHours = (time() - filemtime($latest)) / 3600;
        if ($ageHours > 2) {
            $this->error(sprintf('ไฟล์สำรองล่าสุดเก่า %.1f ชั่วโมง — รัน php artisan erp:backup ใหม่ก่อน', $ageHours));

            return false;
        }

        $checksumFile = $latest.'.sha256';
        if (! is_file($checksumFile)) {
            $this->error('ไม่พบไฟล์ checksum ของ backup');

            return false;
        }
        if (! hash_equals(trim(explode(' ', (string) file_get_contents($checksumFile))[0]), hash_file('sha256', $latest))) {
            $this->error('checksum ของ backup ไม่ตรง — ไฟล์อาจเสียหาย ห้ามลบข้อมูล');

            return false;
        }

        $this->info(sprintf('backup ผ่าน: %s (%.1f ชม.ที่แล้ว, checksum ตรง)', basename($latest), $ageHours));

        return true;
    }
}
