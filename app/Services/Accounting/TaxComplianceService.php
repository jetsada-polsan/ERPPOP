<?php

namespace App\Services\Accounting;

use App\Models\AppSetting;
use App\Models\Document;
use App\Models\ETaxDocument;
use App\Models\TaxFilingRun;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TaxComplianceService
{
    public function prepareFiling(string $period, ?int $branchId, string $form): TaxFilingRun
    {
        [$from, $to] = $this->range($period);
        $rows = $this->rows($from, $to, $branchId, $form);
        $taxable = round($rows->sum(fn ($row) => (float) $row['taxable_amount']), 2);
        $tax = round($rows->sum(fn ($row) => (float) $row['tax_amount']), 2);
        $path = 'tax-filings/'.$period.'/POPSTAR-'.$form.'-'.$period.'-'.($branchId ?: 'ALL').'-'.now()->format('YmdHis').'.csv';
        Storage::disk('local')->put($path, $this->csv($rows));

        return TaxFilingRun::create([
            'period' => $period, 'branch_id' => $branchId, 'form_type' => $form, 'status' => 'prepared',
            'taxable_amount' => $taxable, 'tax_amount' => $tax, 'document_count' => $rows->count(),
            'file_name' => $path, 'file_hash' => hash_file('sha256', Storage::disk('local')->path($path)),
            'prepared_by' => auth()->id(), 'prepared_at' => now(),
        ]);
    }

    public function prepareEtax(string $period, ?int $branchId): int
    {
        [$from, $to] = $this->range($period);
        $documents = Document::with(['documentType', 'branch', 'customer'])
            ->whereBetween('doc_date', [$from, $to])
            ->where('status', '!=', 'cancelled')->where('total_amount', '>', 0)
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', ['CASH_SALE', 'CREDIT_SALE']))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get();
        $count = 0;

        foreach ($documents as $document) {
            if (ETaxDocument::where('document_id', $document->id)->exists()) {
                continue;
            }
            $uuid = (string) Str::uuid();
            $payload = [
                'schema' => 'POPSTAR-ETAX-PROVIDER-PACKAGE-1.0',
                'document_uuid' => $uuid,
                'seller' => [
                    'name' => AppSetting::company('name_th'), 'tax_id' => AppSetting::company('tax_id'),
                    'address' => AppSetting::company('address'), 'branch_code' => $document->branch?->code,
                ],
                'buyer' => [
                    'name' => $document->customer?->name_th ?? 'ลูกค้าทั่วไป',
                    'tax_id' => $document->customer?->tax_id,
                    'tax_branch' => $document->customer?->tax_branch,
                ],
                'invoice' => [
                    'number' => $document->doc_number, 'date' => $document->doc_date->toDateString(),
                    'subtotal' => (float) $document->subtotal_amount, 'vat' => (float) $document->vat_amount,
                    'total' => (float) $document->total_amount,
                ],
                'prepared_at' => now()->toIso8601String(),
                'notice' => 'Provider-neutral package. Validate and sign with an approved e-Tax service provider before Revenue Department submission.',
            ];
            $path = 'etax/'.$period.'/'.$document->doc_number.'-'.$uuid.'.json';
            Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ETaxDocument::create([
                'document_id' => $document->id, 'document_uuid' => $uuid, 'status' => 'prepared',
                'payload_path' => $path, 'payload_hash' => hash_file('sha256', Storage::disk('local')->path($path)),
                'prepared_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    private function rows(Carbon $from, Carbon $to, ?int $branchId, string $form): Collection
    {
        if ($form === 'PP30') {
            $sales = DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
                ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
                ->whereBetween('d.doc_date', [$from, $to])->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN', 'CREDIT_NOTE', 'DEBIT_NOTE'])
                ->where('d.status', '!=', 'cancelled')->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
                ->get(['d.doc_date', 'd.doc_number', 'dt.code as type_code', 'b.code as branch_code', 'c.name_th as party_name', 'c.tax_id', 'd.subtotal_amount', 'd.vat_amount'])
                ->map(function ($r) {
                    $sign = in_array($r->type_code, ['SALE_RETURN', 'CREDIT_NOTE'], true) ? -1 : 1;

                    return ['category' => 'OUTPUT', 'date' => $r->doc_date, 'document_no' => $r->doc_number, 'branch' => $r->branch_code, 'party' => $r->party_name, 'tax_id' => $r->tax_id, 'taxable_amount' => $sign * (float) $r->subtotal_amount, 'tax_amount' => $sign * (float) $r->vat_amount];
                });
            $purchases = DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
                ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
                ->whereBetween('d.doc_date', [$from, $to])->where('dt.code', 'PURCHASE')->where('d.claim_input_vat', true)
                ->where('d.status', '!=', 'cancelled')->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
                ->get(['d.doc_date', 'd.doc_number', 'b.code as branch_code', 's.name_th as party_name', 's.tax_id', 'd.subtotal_amount', 'd.vat_amount'])
                ->map(fn ($r) => ['category' => 'INPUT', 'date' => $r->doc_date, 'document_no' => $r->doc_number, 'branch' => $r->branch_code, 'party' => $r->party_name, 'tax_id' => $r->tax_id, 'taxable_amount' => -(float) $r->subtotal_amount, 'tax_amount' => -(float) $r->vat_amount]);
            $expenses = DB::table('branch_expenses as e')->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
                ->whereBetween('e.expense_date', [$from, $to])->where('e.vat_amount', '>', 0)
                ->when($branchId, fn ($q) => $q->where('e.branch_id', $branchId))
                ->get(['e.expense_date', 'e.tax_invoice_no', 'b.code as branch_code', 'e.supplier_name', 'e.supplier_tax_id', 'e.base_amount', 'e.vat_amount'])
                ->map(fn ($r) => ['category' => 'INPUT', 'date' => $r->expense_date, 'document_no' => $r->tax_invoice_no, 'branch' => $r->branch_code, 'party' => $r->supplier_name, 'tax_id' => $r->supplier_tax_id, 'taxable_amount' => -(float) $r->base_amount, 'tax_amount' => -(float) $r->vat_amount]);

            return $sales->concat($purchases)->concat($expenses)->values();
        }

        return DB::table('branch_expenses as e')->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->whereBetween('e.expense_date', [$from, $to])->where('e.withholding_form', $form)->where('e.withholding_amount', '>', 0)
            ->when($branchId, fn ($q) => $q->where('e.branch_id', $branchId))
            ->get(['e.expense_date', 'e.payment_reference', 'b.code as branch_code', 'e.supplier_name', 'e.supplier_tax_id', 'e.base_amount', 'e.withholding_amount'])
            ->map(fn ($r) => ['category' => $form, 'date' => $r->expense_date, 'document_no' => $r->payment_reference, 'branch' => $r->branch_code, 'party' => $r->supplier_name, 'tax_id' => $r->supplier_tax_id, 'taxable_amount' => (float) $r->base_amount, 'tax_amount' => (float) $r->withholding_amount]);
    }

    private function csv(Collection $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['category', 'date', 'document_no', 'branch', 'party', 'tax_id', 'taxable_amount', 'tax_amount']);
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row));
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$content;
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function range(string $period): array
    {
        try {
            $from = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        } catch (\Throwable) {
            throw new RuntimeException('เดือนภาษีไม่ถูกต้อง');
        }

        return [$from, $from->copy()->endOfMonth()];
    }
}
