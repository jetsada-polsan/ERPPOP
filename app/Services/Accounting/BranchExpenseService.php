<?php

namespace App\Services\Accounting;

use App\Models\BranchExpense;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

class BranchExpenseService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly GlPostingService $posting,
    ) {}

    public function create(array $data): BranchExpense
    {
        return DB::transaction(function () use ($data) {
            $type = DocumentType::where('code', 'EXPENSE')->firstOrFail();
            $total = round((float) $data['base_amount'] + (float) $data['vat_amount'], 2);
            $withholding = round((float) ($data['withholding_amount'] ?? 0), 2);

            $document = Document::create([
                'document_type_id' => $type->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next('EXPENSE', $data['branch_id']),
                'doc_date' => $data['expense_date'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['tax_invoice_no'] ?? $data['payment_reference'] ?? null,
                'status' => 'active',
                'total_items' => 1,
                'total_amount' => $total,
                'remark' => $data['description'],
                'created_by' => auth()->id(),
            ]);

            $expense = BranchExpense::create($data + [
                'document_id' => $document->id,
                'total_amount' => $total,
                'withholding_amount' => $withholding,
                'created_by' => auth()->id(),
            ]);

            $this->posting->postExpense(
                $document,
                (int) $data['expense_account_id'],
                (float) $data['base_amount'],
                (float) $data['vat_amount'],
                $withholding,
                $data['payment_method'],
            );

            return $expense->fresh(['document', 'branch', 'expenseAccount']);
        });
    }
}
