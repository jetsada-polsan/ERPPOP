<?php

namespace App\Http\Controllers;

use App\Models\AccountingImportBatch;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\ETaxDocument;
use App\Models\GlJournal;
use App\Models\RecurringAccountingRule;
use App\Models\TaxFilingRun;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountingAutomationController extends Controller
{
    public function index(Request $request): View
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')) ? $request->query('period') : now()->format('Y-m');
        $branchId = $request->integer('branch_id') ?: null;
        $from = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $recurring = RecurringAccountingRule::with('branch')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('next_run_date')
            ->limit(80)
            ->get();
        $imports = AccountingImportBatch::with(['branch', 'uploader'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest()
            ->limit(80)
            ->get();

        return view('accounting-automation.index', [
            'period' => $period,
            'branchId' => $branchId,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'recurringRules' => $recurring,
            'imports' => $imports,
            'stats' => [
                'unreconciled_bank' => BankStatement::whereBetween('statement_date', [$from, $to])
                    ->when($branchId, fn ($query) => $query->whereHas('bankAccount', fn ($bank) => $bank->where('branch_id', $branchId)))
                    ->whereDoesntHave('reconciliation', fn ($query) => $query->where('status', 'matched'))
                    ->count(),
                'pending_tax' => TaxFilingRun::where('period', $period)
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ->whereIn('status', ['prepared', 'reviewed'])
                    ->count(),
                'pending_etax' => ETaxDocument::whereIn('status', ['prepared', 'sent', 'rejected'])
                    ->whereHas('document', fn ($query) => $query
                        ->whereBetween('doc_date', [$from, $to])
                        ->when($branchId, fn ($doc) => $doc->where('branch_id', $branchId)))
                    ->count(),
                'gl_lines' => GlJournal::whereBetween('entry_date', [$from, $to])
                    ->when($branchId, fn ($query) => $query->whereHas('document', fn ($doc) => $doc->where('branch_id', $branchId)))
                    ->count(),
                'recurring_due' => $recurring->where('is_active', true)->where('next_run_date', '<=', now()->toDateString())->count(),
                'import_queue' => $imports->whereIn('status', ['queued', 'extracted'])->count(),
            ],
        ]);
    }

    public function storeRecurring(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'rule_type' => ['required', 'in:sales_invoice,expense,purchase,billing_note'],
            'name' => ['required', 'string', 'max:160'],
            'party_name' => ['nullable', 'string', 'max:200'],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly'],
            'next_run_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = $data['note'] ?? null;
        unset($data['note']);

        $rule = RecurringAccountingRule::create([
            ...$data,
            'base_amount' => (float) ($data['base_amount'] ?? 0),
            'vat_amount' => (float) ($data['vat_amount'] ?? 0),
            'payload' => ['note' => $note],
            'created_by' => auth()->id(),
        ]);
        $this->audit('recurring_rule_create', 'recurring_accounting_rules', $rule->id, [
            'name' => $rule->name,
            'rule_type' => $rule->rule_type,
            'next_run_date' => $rule->next_run_date->toDateString(),
        ]);

        return back()->with('success', 'เพิ่มรายการเอกสารประจำแล้ว');
    }

    public function runRecurring(RecurringAccountingRule $rule): RedirectResponse
    {
        if (! $rule->is_active) {
            throw ValidationException::withMessages(['rule' => 'รายการนี้ถูกปิดใช้งานแล้ว']);
        }

        $oldNext = $rule->next_run_date->toDateString();
        $rule->advanceNextRun();
        $this->audit('recurring_rule_run', 'recurring_accounting_rules', $rule->id, [
            'scheduled_date' => $oldNext,
            'next_run_date' => $rule->next_run_date->toDateString(),
        ]);

        return back()->with('success', 'บันทึกว่าดำเนินรายการประจำแล้ว และเลื่อนไปรอบถัดไป');
    }

    public function uploadImport(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'source_type' => ['required', 'in:purchase_tax_invoice,expense_receipt,bank_statement,sales_document'],
            'document_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,csv,txt', 'max:15360'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $file = $request->file('document_file');
        $hash = hash_file('sha256', $file->getRealPath());
        if (AccountingImportBatch::where('file_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['document_file' => 'ไฟล์นี้เคยถูกนำเข้าแล้ว']);
        }

        $path = $file->store('accounting/imports/'.now()->format('Y-m'), 'local');
        $suggestions = $this->extractLightweightSuggestions($file->getClientOriginalName());
        $batch = AccountingImportBatch::create([
            'branch_id' => $data['branch_id'] ?? null,
            'source_type' => $data['source_type'],
            'status' => 'extracted',
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_hash' => $hash,
            'suggested_amount' => $suggestions['amount'],
            'suggested_date' => $suggestions['date'],
            'suggested_party' => $suggestions['party'],
            'extracted_json' => $suggestions + ['engine' => 'filename_heuristic', 'needs_ai_review' => true],
            'review_note' => $data['review_note'] ?? null,
            'uploaded_by' => auth()->id(),
        ]);
        $this->audit('accounting_import_upload', 'accounting_import_batches', $batch->id, [
            'source_type' => $batch->source_type,
            'file_hash' => $batch->file_hash,
            'status' => $batch->status,
        ]);

        return back()->with('success', 'นำเข้าเอกสารเข้าคิว OCR/AI แล้ว รอผู้ตรวจยืนยันก่อนลงบัญชีจริง');
    }

    public function reviewImport(Request $request, AccountingImportBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $batch->update($data + ['reviewed_at' => now()]);
        $this->audit('accounting_import_review', 'accounting_import_batches', $batch->id, $data);

        return back()->with('success', 'บันทึกผลตรวจเอกสารนำเข้าแล้ว');
    }

    private function extractLightweightSuggestions(string $name): array
    {
        $baseName = pathinfo($name, PATHINFO_FILENAME);
        preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})/', $baseName, $date);
        $amountSource = $date ? str_replace($date[0], ' ', $baseName) : $baseName;
        preg_match_all('/\d+(?:[,_]\d{3})*(?:\.\d{1,2})?/', $amountSource, $amounts);
        $amount = collect($amounts[0] ?? [])
            ->filter(fn (string $token) => str_contains($token, '.') || str_contains($token, ',') || str_contains($token, '_') || strlen($token) > 4)
            ->last();
        $party = trim((string) preg_replace('/[\d._-]+/', ' ', $baseName));

        return [
            'date' => $date ? "{$date[1]}-{$date[2]}-{$date[3]}" : null,
            'amount' => $amount ? (float) str_replace([',', '_'], ['', ''], $amount) : null,
            'party' => $party !== '' ? mb_substr($party, 0, 200) : null,
        ];
    }

    private function audit(string $action, string $table, ?int $recordId, array $values): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'branch_id' => auth()->user()?->branch_id,
            'action' => $action,
            'table_name' => $table,
            'record_id' => $recordId,
            'new_values' => $values,
        ]);
    }
}
