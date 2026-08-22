<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * กันบิล POS ที่นำเข้าจากระบบเก่าออกจากยอดขายของ ERP ใหม่ — โดยไม่ลบข้อมูล
 *
 * production มีบิล POS 16,557 ใบ ในนั้น 16,537 ใบมาจากท่อนำเข้าเดิมที่ถูกถอดไปแล้ว
 * (`d24c99d`) บิลกลุ่มนั้นไม่มีกะ ไม่มีเอกสารขายผูก และไม่เคยลง GL
 * ผลคือ `sales_postings` รายงานยอดต่างจาก GL อยู่ราว 4.58 ล้าน และรายงานกำไรใช้ไม่ได้
 *
 * ตัวแยกสะอาด: บิลจริงของ ERP ใหม่สร้างผ่าน PosController::recordPosReceipt ซึ่งใส่
 * `pos_shift_id` เสมอ ส่วนบิลนำเข้าเก่าไม่มี — ใช้เงื่อนไขนี้ backfill ครั้งเดียว
 * แล้วเก็บผลไว้ในคอลัมน์ถาวร ไม่ให้ view ไปพึ่ง `pos_shift_id IS NULL` ซึ่งเปราะ
 *
 * **ไม่ลบข้อมูล** ตามที่เจ้าของสั่ง — ถอยได้ด้วยการ rollback migration นี้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->boolean('is_legacy_import')->default(false);
        });

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->index(['is_legacy_import', 'status'], 'pos_receipts_legacy_status_idx');
        });

        // บิลที่ไม่มีกะ = ของนำเข้าเก่า (ตรวจบน production 2026-08-23: 16,537 จาก 16,557)
        DB::table('pos_receipts')->whereNull('pos_shift_id')->update(['is_legacy_import' => true]);

        $this->rebuildSalesPostings();
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropIndex('pos_receipts_legacy_status_idx');
        });
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropColumn('is_legacy_import');
        });

        $this->rebuildSalesPostings(includeLegacy: true);
    }

    /**
     * นิยาม view เดิมทุกอย่าง เพิ่มเงื่อนไขกันบิลนำเข้าเก่าออกทั้งสองครึ่ง
     * (ครึ่ง POS กันตรง ๆ ส่วนครึ่งเอกสารกันผ่าน NOT EXISTS ที่มีอยู่แล้ว)
     */
    private function rebuildSalesPostings(bool $includeLegacy = false): void
    {
        $postgres = DB::getDriverName() === 'pgsql';
        $posChannel = $postgres ? "'POS'::varchar(20)" : "'POS'";
        $docChannel = $postgres ? 'dt.code::varchar(20)' : 'dt.code';
        $saleDate = $postgres ? 'r.receipt_date::date' : 'date(r.receipt_date)';
        $zero = $postgres ? '0::numeric' : '0';
        $legacyFilter = $includeLegacy ? '' : 'AND r.is_legacy_import = '.($postgres ? 'false' : '0');

        DB::unprepared('DROP VIEW IF EXISTS sales_postings');
        DB::unprepared(<<<SQL
            CREATE VIEW sales_postings AS
            WITH document_costs AS (
                SELECT sd.document_id, SUM(COALESCE(sdi.cost_amount, 0)) AS cogs_amount
                FROM stock_documents sd
                JOIN stock_document_items sdi ON sdi.stock_document_id = sd.id
                GROUP BY sd.document_id
            )
            SELECT
                {$posChannel} AS channel,
                r.id AS source_id,
                r.document_id,
                t.branch_id,
                d.customer_id,
                r.cashier_salesman_id AS salesman_id,
                d.sales_area_id,
                r.receipt_no AS sale_number,
                {$saleDate} AS sale_date,
                r.status,
                r.gross_sales,
                r.discount_amount,
                r.vat_amount,
                r.net_sales,
                CASE WHEN r.document_id IS NULL THEN NULL ELSE COALESCE(costs.cogs_amount, 0) END AS cogs_amount,
                CASE WHEN r.document_id IS NULL THEN NULL ELSE r.net_sales - COALESCE(costs.cogs_amount, 0) END AS gross_profit
            FROM pos_receipts r
            JOIN pos_terminals t ON t.id = r.pos_terminal_id
            LEFT JOIN documents d ON d.id = r.document_id
            LEFT JOIN document_costs costs ON costs.document_id = r.document_id
            WHERE r.status = 'completed'
              {$legacyFilter}

            UNION ALL

            SELECT
                {$docChannel} AS channel,
                d.id AS source_id,
                d.id AS document_id,
                d.branch_id,
                d.customer_id,
                d.salesman_id,
                d.sales_area_id,
                d.doc_number AS sale_number,
                d.doc_date AS sale_date,
                d.status,
                d.total_amount AS gross_sales,
                {$zero} AS discount_amount,
                COALESCE(d.vat_amount, 0) AS vat_amount,
                d.total_amount AS net_sales,
                COALESCE(costs.cogs_amount, 0) AS cogs_amount,
                d.total_amount - COALESCE(costs.cogs_amount, 0) AS gross_profit
            FROM documents d
            JOIN document_types dt ON dt.id = d.document_type_id
            LEFT JOIN document_costs costs ON costs.document_id = d.id
            WHERE d.status = 'active'
              AND dt.code IN ('CASH_SALE', 'CREDIT_SALE')
              AND NOT EXISTS (
                  SELECT 1 FROM pos_receipts r
                  WHERE r.document_id = d.id AND r.status = 'completed'
              )
            SQL);
    }
};
