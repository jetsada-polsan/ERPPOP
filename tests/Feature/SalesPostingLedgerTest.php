<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesPostingLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_postings_reports_each_sale_once_across_pos_and_backoffice(): void
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POS-01', 'name' => 'แคชเชียร์ 1']);
        $cashType = DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ขายสด']);
        $creditType = DocumentType::create(['code' => 'CREDIT_SALE', 'name_th' => 'ขายเชื่อ']);

        $linkedCashSale = Document::create([
            'document_type_id' => $cashType->id,
            'branch_id' => $branch->id,
            'doc_number' => 'CS-0001',
            'doc_date' => '2026-07-30',
            'status' => 'active',
            'total_items' => 1,
            'total_amount' => 100,
        ]);
        PosReceipt::create([
            'pos_terminal_id' => $terminal->id,
            'document_id' => $linkedCashSale->id,
            'receipt_no' => 'POS-0001',
            'receipt_date' => '2026-07-30 10:00:00',
            'gross_sales' => 100,
            'net_sales' => 100,
            'status' => 'completed',
        ]);

        PosReceipt::create([
            'pos_terminal_id' => $terminal->id,
            'receipt_no' => 'POS-0002',
            'receipt_date' => '2026-07-30 11:00:00',
            'gross_sales' => 50,
            'net_sales' => 50,
            'status' => 'completed',
        ]);

        Document::create([
            'document_type_id' => $creditType->id,
            'branch_id' => $branch->id,
            'doc_number' => 'CR-0001',
            'doc_date' => '2026-07-30',
            'status' => 'active',
            'total_items' => 1,
            'total_amount' => 200,
        ]);

        $rows = DB::table('sales_postings')->orderBy('sale_number')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(350.0, (float) $rows->sum('net_sales'));
        $this->assertSame(['CREDIT_SALE', 'POS', 'POS'], $rows->pluck('channel')->all());
        $this->assertNull($rows->firstWhere('sale_number', 'POS-0002')->cogs_amount);
    }
}
