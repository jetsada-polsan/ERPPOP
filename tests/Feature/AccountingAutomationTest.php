<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\AccountingImportBatch;
use App\Models\Branch;
use App\Models\RecurringAccountingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountingAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_user_can_create_and_advance_a_recurring_accounting_rule(): void
    {
        $admin = User::factory()->create(['username' => 'finance-auto']);
        $branch = Branch::create(['code' => 'ACC-AUTO', 'name_th' => 'สาขาบัญชีอัตโนมัติ', 'is_active' => true]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('accounting-automation.recurring.store'), [
                'branch_id' => $branch->id,
                'rule_type' => 'expense',
                'name' => 'ค่าเช่ารายเดือน',
                'party_name' => 'เจ้าของอาคาร',
                'base_amount' => 10000,
                'vat_amount' => 700,
                'frequency' => 'monthly',
                'next_run_date' => '2026-09-01',
            ]);

        $response->assertRedirect();
        $rule = RecurringAccountingRule::firstOrFail();
        $this->assertSame('ค่าเช่ารายเดือน', $rule->name);
        $this->assertSame('2026-09-01', $rule->next_run_date->toDateString());

        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('accounting-automation.recurring.run', $rule))
            ->assertRedirect();

        $rule->refresh();
        $this->assertSame('2026-09-01', $rule->last_run_at->toDateString());
        $this->assertSame('2026-10-01', $rule->next_run_date->toDateString());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recurring_rule_run',
            'table_name' => 'recurring_accounting_rules',
            'record_id' => $rule->id,
        ]);
    }

    public function test_finance_user_can_upload_an_accounting_import_once_for_review(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['username' => 'finance-import']);
        $branch = Branch::create(['code' => 'OCR-AUTO', 'name_th' => 'สาขา OCR', 'is_active' => true]);
        $file = UploadedFile::fake()->create('Vendor_2026-09-02_1250.50.pdf', 20, 'application/pdf');

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('accounting-automation.imports.upload'), [
                'branch_id' => $branch->id,
                'source_type' => 'expense_receipt',
                'document_file' => $file,
                'review_note' => 'รอ OCR ตัวจริงอ่านรายละเอียด',
            ]);

        $response->assertRedirect();
        $batch = AccountingImportBatch::firstOrFail();
        Storage::disk('local')->assertExists($batch->file_path);
        $this->assertSame('extracted', $batch->status);
        $this->assertSame('2026-09-02', $batch->suggested_date->toDateString());
        $this->assertSame('Vendor', $batch->suggested_party);
        $this->assertSame('1250.5000', (string) $batch->suggested_amount);

        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('accounting-automation.imports.review', $batch), ['status' => 'approved'])
            ->assertRedirect();
        $this->assertSame('approved', $batch->fresh()->status);

        $duplicate = UploadedFile::fake()->createWithContent('copy.pdf', Storage::disk('local')->get($batch->file_path));
        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('accounting-automation.imports.upload'), [
                'source_type' => 'expense_receipt',
                'document_file' => $duplicate,
            ])
            ->assertSessionHasErrors('document_file');
    }
}
