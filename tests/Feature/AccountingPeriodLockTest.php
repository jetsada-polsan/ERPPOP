<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingPeriodLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_period_blocks_documents_but_allows_dates_outside_period(): void
    {
        [$branch, $type] = $this->documentSetup();
        $this->closedPeriod();

        try {
            $this->createDocument($branch, $type, '2026-07-15', 'LOCKED-001');
            $this->fail('A document was created inside a closed company period.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('doc_date', $exception->errors());
        }

        $document = $this->createDocument($branch, $type, '2026-08-01', 'OPEN-001');
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_branch_period_does_not_lock_another_branch(): void
    {
        [$lockedBranch, $type] = $this->documentSetup('LOCK');
        $otherBranch = Branch::create(['code' => 'OPEN', 'name_th' => 'สาขาที่เปิด', 'is_active' => true]);
        $this->closedPeriod($lockedBranch->id);

        $document = $this->createDocument($otherBranch, $type, '2026-07-15', 'OTHER-001');
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'branch_id' => $otherBranch->id]);

        $this->expectException(ValidationException::class);
        $this->createDocument($lockedBranch, $type, '2026-07-15', 'LOCKED-002');
    }

    public function test_closing_period_prevents_changes_and_deletion_of_existing_document(): void
    {
        [$branch, $type] = $this->documentSetup();
        $document = $this->createDocument($branch, $type, '2026-07-15', 'EXISTING-001');
        $this->closedPeriod();

        try {
            $document->update(['remark' => 'attempted change']);
            $this->fail('A document inside a closed period was updated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('doc_date', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $document->delete();
    }

    public function test_company_period_blocks_manual_gl_entry(): void
    {
        $account = ChartOfAccount::create([
            'code' => '9999',
            'name_th' => 'บัญชีทดสอบ',
            'account_type' => 'expense',
        ]);
        $this->closedPeriod();

        $this->expectException(ValidationException::class);
        GlJournal::create([
            'account_id' => $account->id,
            'debit' => 100,
            'credit' => 0,
            'entry_date' => '2026-07-31',
            'remark' => 'must be blocked',
        ]);
    }

    private function closedPeriod(?int $branchId = null): AccountingPeriod
    {
        return AccountingPeriod::create([
            'name' => 'กรกฎาคม 2569',
            'branch_id' => $branchId,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-31',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }

    private function documentSetup(string $branchCode = 'HQ'): array
    {
        $branch = Branch::create(['code' => $branchCode, 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $type = DocumentType::create([
            'code' => 'TEST-'.$branchCode,
            'name_th' => 'เอกสารทดสอบ',
            'affects_stock' => false,
            'affects_ar' => false,
            'affects_ap' => false,
            'is_active' => true,
        ]);

        return [$branch, $type];
    }

    private function createDocument(Branch $branch, DocumentType $type, string $date, string $number): Document
    {
        return Document::create([
            'document_type_id' => $type->id,
            'branch_id' => $branch->id,
            'doc_number' => $number,
            'doc_date' => $date,
            'status' => 'active',
            'total_items' => 0,
            'total_amount' => 0,
        ]);
    }
}
