<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Services\Sales\CreditDebitNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class CreditDebitNoteController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->query('type', 'credit') === 'debit' ? 'debit' : 'credit';
        $typeCode = $type === 'credit' ? 'CREDIT_NOTE' : 'DEBIT_NOTE';
        $q = trim((string) $request->query('q', ''));

        $notes = Document::with(['customer', 'branch'])
            ->whereHas('documentType', fn ($query) => $query->where('code', $typeCode))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhere('reference', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('credit-debit-notes.index', [
            'notes' => $notes,
            'type' => $type,
            'q' => $q,
            'customers' => Customer::where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code', 'name_th']),
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
        ]);
    }

    // ใบเพิ่มหนี้/ลดหนี้แบบพิมพ์ A4 (เอกสารภาษีตามมาตรา 86/9-10 ส่งให้ลูกค้า)
    public function print(Document $note): View
    {
        $note->load(['customer.addresses', 'branch', 'documentType']);
        abort_unless(in_array($note->documentType->code, ['CREDIT_NOTE', 'DEBIT_NOTE'], true), 404);

        $isCredit = $note->documentType->code === 'CREDIT_NOTE';

        // ราคารวม VAT: ถอดฐานภาษี
        $vatRate = (float) (\Illuminate\Support\Facades\DB::table('vat_rates')
            ->where('effective_from', '<=', $note->doc_date->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $note->doc_date->toDateString()))
            ->orderByDesc('effective_from')->value('rate_percent') ?? 7.0);

        $total = (float) $note->total_amount;
        $base = round($total * 100 / (100 + $vatRate), 2);

        return view('credit-debit-notes.print', [
            'note' => $note,
            'isCredit' => $isCredit,
            'vatRate' => $vatRate,
            'baseAmount' => $base,
            'vatAmount' => round($total - $base, 2),
            'total' => $total,
            'totalText' => \App\Support\ThaiBaht::text($total),
        ]);
    }

    // ใบขายเชื่อค้างชำระของลูกค้า สำหรับเลือกอ้างอิงในใบลดหนี้
    public function openItems(Customer $customer): JsonResponse
    {
        $items = CustomerOpenItem::with('document')
            ->where('customer_id', $customer->id)
            ->where('balance_amount', '>', 0)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'doc_number' => $item->document->doc_number,
                'balance_amount' => (float) $item->balance_amount,
            ]);

        return response()->json($items);
    }

    public function store(Request $request, CreditDebitNoteService $service): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
            'open_item_id' => ['nullable', 'integer', 'exists:customer_open_items,id'],
        ], [
            'reason.required' => 'กรุณาระบุเหตุผล/รายละเอียด',
            'amount.required' => 'กรุณากรอกจำนวนเงิน',
        ]);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        $label = $data['type'] === 'credit' ? 'ใบลดหนี้' : 'ใบเพิ่มหนี้';

        return redirect()->route('credit-debit-notes.index', ['type' => $data['type']])
            ->with('success', "บันทึก{$label} {$document->doc_number} แล้ว");
    }
}
