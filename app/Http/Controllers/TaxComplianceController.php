<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ETaxDocument;
use App\Models\TaxFilingRun;
use App\Services\Accounting\TaxComplianceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaxComplianceController extends Controller
{
    public function index(Request $request): View
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')) ? $request->query('period') : now()->format('Y-m');
        $branchId = $request->integer('branch_id') ?: null;

        return view('tax-compliance.index', [
            'period' => $period, 'branchId' => $branchId,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'filings' => TaxFilingRun::with(['branch', 'preparer', 'reviewer'])->where('period', $period)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->latest()->get(),
            'etaxDocuments' => ETaxDocument::with(['document.branch', 'document.customer'])->whereHas('document', fn ($q) => $q->whereBetween('doc_date', [$period.'-01', date('Y-m-t', strtotime($period.'-01'))])->when($branchId, fn ($b) => $b->where('branch_id', $branchId)))->latest()->limit(100)->get(),
        ]);
    }

    public function prepare(Request $request, TaxComplianceService $service): RedirectResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'branch_id' => ['nullable', 'exists:branches,id'], 'form_type' => ['required', 'in:PP30,PND3,PND53']]);
        $run = $service->prepareFiling($data['period'], isset($data['branch_id']) ? (int) $data['branch_id'] : null, $data['form_type']);
        $this->audit('tax_prepare', 'tax_filing_runs', $run->id, ['period' => $run->period, 'form' => $run->form_type, 'hash' => $run->file_hash]);

        return back()->with('success', "จัดทำชุด {$run->form_type} แล้ว กรุณาให้ผู้ตรวจคนที่สองทบทวนก่อนบันทึกการยื่น");
    }

    public function review(TaxFilingRun $run): RedirectResponse
    {
        if ($run->prepared_by === auth()->id()) {
            throw ValidationException::withMessages(['review' => 'ผู้จัดทำและผู้ตรวจต้องเป็นคนละคน']);
        }
        $run->update(['status' => 'reviewed', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        $this->audit('tax_review', 'tax_filing_runs', $run->id, ['status' => 'reviewed']);

        return back()->with('success', 'ตรวจทานชุดภาษีแล้ว');
    }

    public function submit(Request $request, TaxFilingRun $run): RedirectResponse
    {
        $data = $request->validate(['submission_reference' => ['required', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:2000']]);
        if ($run->status !== 'reviewed') {
            throw ValidationException::withMessages(['submission_reference' => 'ต้องผ่านการตรวจทานก่อนบันทึกผลการยื่น']);
        }
        $run->update($data + ['status' => 'submitted', 'submitted_at' => now()]);
        $this->audit('tax_submit', 'tax_filing_runs', $run->id, ['reference' => $data['submission_reference']]);

        return back()->with('success', 'บันทึกเลขอ้างอิงการยื่นภาษีแล้ว');
    }

    public function download(TaxFilingRun $run): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($run->file_name), 404);

        return response()->download(Storage::disk('local')->path($run->file_name), basename($run->file_name));
    }

    public function prepareEtax(Request $request, TaxComplianceService $service): RedirectResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'branch_id' => ['nullable', 'exists:branches,id']]);
        $count = $service->prepareEtax($data['period'], isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $this->audit('etax_prepare', 'etax_documents', null, ['period' => $data['period'], 'count' => $count]);

        return back()->with('success', "เตรียม E-Tax provider package ใหม่ {$count} เอกสาร");
    }

    public function updateEtax(Request $request, ETaxDocument $etaxDocument): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:sent,accepted,rejected'], 'provider_reference' => ['required', 'string', 'max:120'], 'provider_message' => ['nullable', 'string', 'max:2000']]);
        $etaxDocument->update($data + [
            'sent_at' => $etaxDocument->sent_at ?: now(),
            'accepted_at' => $data['status'] === 'accepted' ? now() : null,
        ]);
        $this->audit('etax_status', 'etax_documents', $etaxDocument->id, $data);

        return back()->with('success', 'อัปเดตสถานะจากผู้ให้บริการ E-Tax แล้ว');
    }

    private function audit(string $action, string $table, ?int $recordId, array $values): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'branch_id' => auth()->user()?->branch_id, 'action' => $action, 'table_name' => $table, 'record_id' => $recordId, 'new_values' => $values]);
    }
}
