<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\GlJournal;
use App\Services\Accounting\BranchExpenseService;
use App\Services\Accounting\MonthlyAccountingExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class MonthlyAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_creates_balanced_gl_with_vat_and_withholding_tax(): void
    {
        $branch = $this->branch();
        $expense = app(BranchExpenseService::class)->create($this->expenseData($branch->id));

        $this->assertStringStartsWith('EVHQ', $expense->document->doc_number);
        $this->assertSame(107.0, (float) $expense->total_amount);
        $this->assertSame(3.0, (float) $expense->withholding_amount);

        $journals = $expense->document->fresh()->hasMany(GlJournal::class)->get();
        $this->assertCount(4, $journals);
        $this->assertSame(107.0, round((float) $journals->sum('debit'), 2));
        $this->assertSame(107.0, round((float) $journals->sum('credit'), 2));
    }

    public function test_closed_period_blocks_expense_posting(): void
    {
        $branch = $this->branch();
        AccountingPeriod::create([
            'name' => 'กรกฎาคม 2569', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
            'status' => 'closed', 'closed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(BranchExpenseService::class)->create($this->expenseData($branch->id));
    }

    public function test_monthly_export_contains_accountant_csv_files_and_manifest(): void
    {
        Storage::fake('local');
        $branch = $this->branch();
        app(BranchExpenseService::class)->create($this->expenseData($branch->id));

        $run = app(MonthlyAccountingExportService::class)->create('2026-07', $branch->id);
        $this->assertTrue(Storage::disk('local')->exists($run->file_name));
        $this->assertSame(64, strlen($run->file_hash));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($run->file_name)) === true);
        foreach (['00_README.txt', '01_SUMMARY.csv', '02_BANK_RECONCILIATION.csv', '03_SALES_VAT.csv', '04_PURCHASE_VAT.csv', '05_EXPENSES.csv', '06_WITHHOLDING_TAX.csv', '07_GENERAL_LEDGER.csv', 'manifest.json'] as $file) {
            $this->assertNotFalse($zip->locateName($file), "Missing export file {$file}");
        }
        $zip->close();
    }

    private function branch(): Branch
    {
        return Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
    }

    private function expenseData(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'expense_account_id' => ChartOfAccount::where('default_role', ChartOfAccount::ROLE_EXPENSE)->value('id'),
            'expense_date' => '2026-07-15',
            'supplier_name' => 'บริษัท ทดสอบ จำกัด',
            'supplier_tax_id' => '0105555555555',
            'tax_branch' => '00000',
            'tax_invoice_no' => 'TAX-001',
            'tax_invoice_date' => '2026-07-15',
            'description' => 'ค่าบริการทดสอบ',
            'base_amount' => 100,
            'vat_amount' => 7,
            'withholding_rate' => 3,
            'withholding_amount' => 3,
            'withholding_form' => 'PND53',
            'payment_method' => 'transfer',
            'payment_reference' => 'REF-001',
        ];
    }
}
