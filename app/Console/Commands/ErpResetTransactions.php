<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ล้างข้อมูลธุรกรรมทั้งหมด เก็บแฟ้มหลักไว้ — ใช้ก่อนเริ่ม UAT ทั้งระบบ
 *
 * ลบแล้วย้อนไม่ได้นอกจากกู้จาก backup จึงมีด่านกันสามชั้น:
 *   1. ต้องมี backup ที่อายุไม่เกิน 2 ชั่วโมงและ checksum ผ่าน
 *   2. ต้องพิมพ์ชื่อฐานข้อมูลให้ตรงกับฐานที่ต่ออยู่
 *   3. แสดงจำนวนแถวที่จะลบก่อนถามยืนยันครั้งสุดท้าย
 *
 * สิ่งที่ไม่ลบ: สินค้า ลูกค้า ผู้ขาย ผู้ใช้ สิทธิ์ ผังบัญชี ทะเบียนรายงาน
 * ตั้งค่าระบบ สาขา คลัง เครื่อง POS และ audit_logs
 */
class ErpResetTransactions extends Command
{
    protected $signature = 'erp:reset-transactions
        {--confirm-database= : ชื่อฐานข้อมูลที่ต้องการล้าง ต้องพิมพ์ให้ตรง}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'ล้างข้อมูลธุรกรรมทั้งหมดเพื่อเริ่ม UAT ใหม่ (เก็บแฟ้มหลัก)';

    /** ตารางธุรกรรมที่จะถูกล้าง */
    private const TRANSACTIONAL = [
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
        'depreciation_records', 'tax_filing_runs', 'e_tax_documents', 'accounting_export_runs',
        'member_point_transactions',
        // staging ของท่อนำเข้า POS เก่าที่ถอดออกไปแล้ว
        'imported_payments', 'imported_receipt_items', 'imported_receipts',
        'import_errors', 'import_files', 'import_batches',
    ];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();

        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }

        $present = array_values(array_filter(self::TRANSACTIONAL, fn ($table) => Schema::hasTable($table)));
        $counts = [];
        $total = 0;
        foreach ($present as $table) {
            $rows = DB::table($table)->count();
            $total += $rows;
            if ($rows > 0) {
                $counts[] = [$table, number_format($rows)];
            }
        }

        if ($total === 0) {
            $this->info('ไม่มีข้อมูลธุรกรรมให้ล้าง');

            return self::SUCCESS;
        }

        $this->table(['ตาราง', 'แถวที่จะลบ'], $counts);
        $this->warn(sprintf('จะลบทั้งหมด %s แถว จากฐาน %s — ย้อนกลับได้ด้วย backup เท่านั้น', number_format($total), $database));
        $this->line('แฟ้มหลัก (สินค้า ลูกค้า ผู้ขาย ผู้ใช้ สิทธิ์ ผังบัญชี ทะเบียนรายงาน) จะไม่ถูกแตะ');

        if (! $this->option('force') && ! $this->confirm('ยืนยันล้างข้อมูลธุรกรรม?', false)) {
            $this->info('ยกเลิก ไม่มีอะไรถูกลบ');

            return self::SUCCESS;
        }

        DB::statement('TRUNCATE '.implode(', ', array_map(fn ($table) => '"'.$table.'"', $present)).' RESTART IDENTITY CASCADE');
        // ยอดคงเหลือต้องเป็นศูนย์ ไม่งั้นสต๊อกจะลอยอยู่โดยไม่มี lot และไม่มีการเคลื่อนไหวรองรับ
        DB::table('stock_balances')->update(['on_hand_qty' => 0, 'reserved_qty' => 0]);

        $this->info(sprintf('ล้างแล้ว %s แถว · stock_balances ตั้งเป็นศูนย์ %s แถว',
            number_format($total), number_format(DB::table('stock_balances')->count())));

        foreach (['products', 'customers', 'suppliers', 'users', 'chart_of_accounts', 'report_definitions'] as $table) {
            $this->line(sprintf('  คงไว้ %-20s %s', $table, number_format(DB::table($table)->count())));
        }

        return self::SUCCESS;
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
