<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * เจ้าของ 13 พ.ย. 2569: ล้างกะและยอดขาย POS เดิมทั้งหมดเพื่อให้พนักงานเทสใหม่ "ทุกอย่าง ยกเว้นสต๊อก"
 *
 * ลบ: กะ บิล รายการ ชำระเงิน คืนสินค้า พักบิล บิลออฟไลน์ เงินเข้าออกลิ้นชัก สมุดเงินสดของกะ
 *     เอกสารขาย POS พร้อมบัญชี GL ลูกหนี้ และแต้มสมาชิกของบิลเหล่านั้น
 * ไม่แตะ: stock_movements / stock_lots / stock_balances — ยอดคงเหลือเท่าเดิมทุกตัว
 *     (movement ของบิลที่ลบจะเหลือ document_id ว่าง เพราะ FK เป็น nullOnDelete)
 * ต่างจาก erp:void-pos-test-sales ที่ "คืนสต๊อก" และ erp:reset-transactions ที่ล้างสต๊อกด้วย
 */
class PosResetSales extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'pos:reset-sales
        {--confirm-database= : ชื่อฐานข้อมูล ต้องพิมพ์ให้ตรงตอนลบจริง}
        {--apply : ลบจริง ถ้าไม่ใส่จะแสดงจำนวนอย่างเดียว}';

    protected $description = 'Delete every POS shift and sale (with their accounting and points) but keep stock as is';

    /** ลบลูกก่อนแม่ */
    private const POS_TABLES = [
        'pos_receipt_return_items', 'pos_receipt_returns', 'pos_payments', 'pos_coupons', 'pos_receipt_discounts',
        'pos_receipt_items', 'pos_offline_sales', 'pos_held_bills', 'pos_cash_movements', 'pos_preparation_jobs',
        'pos_receipts', 'pos_api_idempotency',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $database = DB::connection()->getDatabaseName();
        if ($apply && $this->option('confirm-database') !== $database) {
            $this->error("ลบจริงต้องใส่ --confirm-database={$database}");

            return self::FAILURE;
        }
        if ($apply && ! $this->hasFreshBackup()) {
            return self::FAILURE;
        }

        $documentIds = $this->posDocumentIds();
        $stockBefore = $this->stockFingerprint();
        $plan = $this->plan($documentIds);

        $this->line("ฐานข้อมูล: {$database}");
        $this->table(['ตาราง', 'แถวที่จะลบ'], collect($plan)->map(fn ($n, $t) => [$t, number_format($n)])->values()->all());
        $this->line(sprintf('สต๊อก (ไม่แตะ): %s movement · ยอดคงเหลือรวม %s', number_format($stockBefore['movements']), $stockBefore['on_hand']));

        if (! $apply) {
            $this->info('ดูอย่างเดียว ยังไม่ลบ — ลบจริงใส่ --apply --confirm-database='.$database);

            return self::SUCCESS;
        }

        DB::transaction(function () use ($documentIds) {
            $this->reverseMemberPoints($documentIds);
            foreach (self::POS_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
            if (Schema::hasTable('cash_books')) {
                DB::table('cash_books')->where(fn ($q) => $q->whereNotNull('pos_shift_id')
                    ->orWhere('source_type', 'like', 'pos\_%'))->delete();
            }
            DB::table('pos_shifts')->delete();

            foreach (array_chunk($documentIds, 500) as $chunk) {
                foreach (['gl_journals', 'customer_open_items', 'customer_ledger'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->whereIn('document_id', $chunk)->delete();
                    }
                }
                // stock_documents/items ของบิลหายตาม cascade · stock_movements เหลือ document_id ว่าง
                DB::table('documents')->whereIn('id', $chunk)->delete();
            }
        });

        $stockAfter = $this->stockFingerprint();
        if ($stockAfter !== $stockBefore) {
            $this->error('สต๊อกเปลี่ยน! ตรวจทันที: '.json_encode([$stockBefore, $stockAfter]));

            return self::FAILURE;
        }
        $this->info('ล้างกะและยอดขาย POS แล้ว สต๊อกเท่าเดิม');

        return self::SUCCESS;
    }

    /** @return list<int> เอกสารขาย/ยกเลิก/คืน ที่เกิดจาก POS */
    private function posDocumentIds(): array
    {
        $ids = DB::table('pos_receipts')->pluck('document_id');
        if (Schema::hasColumn('pos_receipts', 'void_document_id')) {
            $ids = $ids->merge(DB::table('pos_receipts')->pluck('void_document_id'));
        }
        if (Schema::hasTable('pos_receipt_returns')) {
            $ids = $ids->merge(DB::table('pos_receipt_returns')->pluck('document_id'));
        }

        return $ids->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @param list<int> $documentIds */
    private function plan(array $documentIds): array
    {
        $plan = [];
        foreach ([...self::POS_TABLES, 'pos_shifts'] as $table) {
            if (Schema::hasTable($table)) {
                $plan[$table] = DB::table($table)->count();
            }
        }
        if (Schema::hasTable('cash_books')) {
            $plan['cash_books (ของกะ POS)'] = DB::table('cash_books')->where(fn ($q) => $q->whereNotNull('pos_shift_id')
                ->orWhere('source_type', 'like', 'pos\_%'))->count();
        }
        $plan['documents (เอกสาร POS)'] = count($documentIds);
        foreach (['gl_journals', 'customer_open_items', 'customer_ledger', 'member_point_transactions'] as $table) {
            if (Schema::hasTable($table)) {
                $plan[$table] = collect(array_chunk($documentIds, 500))
                    ->sum(fn ($chunk) => DB::table($table)->whereIn('document_id', $chunk)->count());
            }
        }

        return array_filter($plan);
    }

    /** คืนแต้มที่บิล POS ให้/ใช้ไป แล้วลบรายการแต้มนั้น ให้ยอดแต้มสมาชิกตรงกับสมุดแต้มที่เหลือ */
    private function reverseMemberPoints(array $documentIds): void
    {
        if (! Schema::hasTable('member_point_transactions')) {
            return;
        }
        foreach (array_chunk($documentIds, 500) as $chunk) {
            $rows = DB::table('member_point_transactions')->whereIn('document_id', $chunk)
                ->selectRaw("member_id, sum(case when direction = 'redeem' then -points else points end) as delta")
                ->groupBy('member_id')->get();
            foreach ($rows as $row) {
                DB::table('members')->where('id', $row->member_id)->decrement('points', $row->delta);
            }
            DB::table('member_point_transactions')->whereIn('document_id', $chunk)->delete();
        }
    }

    private function stockFingerprint(): array
    {
        return [
            'movements' => DB::table('stock_movements')->count(),
            'on_hand' => Schema::hasTable('stock_balances')
                ? number_format((float) DB::table('stock_balances')->sum('on_hand_qty'), 4, '.', '')
                : '0',
        ];
    }
}
