<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Canonical read-only sales ledger for reporting and accounting reconciliation.
 * POS keeps its device-specific receipt table and back-office keeps documents;
 * this view presents each completed sale once and excludes the linked POS cash
 * document from the back-office half to prevent double counting.
 *
 * NOTE: cast syntax differs per driver. PostgreSQL (production) uses `::type`;
 * SQLite (tests) has no such operator, and its CAST(... AS date) would mangle a
 * datetime string through numeric affinity — so the date is taken with date()
 * there instead. Both branches must stay semantically identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS sales_postings');
        DB::unprepared($this->definition());
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS sales_postings');
    }

    private function definition(): string
    {
        $postgres = DB::getDriverName() === 'pgsql';

        $posChannel = $postgres ? "'POS'::varchar(20)" : "'POS'";
        $docChannel = $postgres ? 'dt.code::varchar(20)' : 'dt.code';
        $saleDate = $postgres ? 'r.receipt_date::date' : 'date(r.receipt_date)';
        $zero = $postgres ? '0::numeric' : '0';

        return <<<SQL
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
            SQL;
    }
};
