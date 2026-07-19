<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id', 'branch_id', 'expense_account_id', 'expense_date', 'supplier_name', 'supplier_tax_id',
    'tax_branch', 'tax_invoice_no', 'tax_invoice_date', 'description', 'base_amount', 'vat_amount',
    'withholding_rate', 'withholding_amount', 'withholding_form', 'total_amount', 'payment_method',
    'bank_account_id', 'payment_reference', 'evidence_path', 'created_by',
])]
class BranchExpense extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'expense_date' => 'date', 'tax_invoice_date' => 'date', 'base_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4', 'withholding_rate' => 'decimal:4',
            'withholding_amount' => 'decimal:4', 'total_amount' => 'decimal:4',
        ];
    }
}
