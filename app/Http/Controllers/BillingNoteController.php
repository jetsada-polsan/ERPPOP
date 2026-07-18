<?php

namespace App\Http\Controllers;

use App\Models\BillingNote;
use App\Models\BillingNoteItem;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BillingNoteController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $notes = BillingNote::with(['customer', 'branch'])->withCount('items')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('billing-notes.index', [
            'notes' => $notes,
            'status' => $status,
            'q' => $q,
            'customersWithDebt' => Customer::whereHas('openItems', fn ($query) => $query->where('balance_amount', '>', 0))
                ->withCount(['openItems as unpaid_count' => fn ($query) => $query->where('balance_amount', '>', 0)])
                ->orderBy('code')->limit(500)->get(['id', 'code', 'name_th']),
        ]);
    }

    // ดึงใบขายเชื่อค้างชำระของลูกค้า สำหรับติ๊กเลือกลงใบวางบิล
    public function openItems(Customer $customer): JsonResponse
    {
        $items = CustomerOpenItem::with('document.documentType')
            ->where('customer_id', $customer->id)
            ->where('balance_amount', '>', 0)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'doc_number' => $item->document->doc_number,
                'doc_date' => $item->document->doc_date->thaiDate(),
                'due_date' => $item->due_date?->thaiDate() ?? '-',
                'net_amount' => (float) $item->net_amount,
                'balance_amount' => (float) $item->balance_amount,
            ]);

        return response()->json($items);
    }

    public function store(Request $request, DocumentNumberGenerator $numbers): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'open_item_id' => ['required', 'array', 'min:1'],
            'open_item_id.*' => ['integer', 'exists:customer_open_items,id'],
        ], [
            'open_item_id.required' => 'กรุณาเลือกใบขายเชื่อที่ต้องการวางบิลอย่างน้อย 1 ใบ',
        ]);

        // ตรวจว่าทุกใบเป็นของลูกค้ารายนี้และยังค้างจริง
        $items = CustomerOpenItem::whereIn('id', $data['open_item_id'])
            ->where('customer_id', $data['customer_id'])
            ->where('balance_amount', '>', 0)
            ->get();

        if ($items->isEmpty()) {
            return back()->withErrors(['open_item_id' => 'ไม่พบใบขายเชื่อค้างชำระที่เลือก'])->withInput();
        }

        $branchId = $data['branch_id'] ?? $items->first()->document->branch_id ?? Branch::orderBy('id')->value('id');

        $note = DB::transaction(function () use ($data, $items, $branchId, $numbers) {
            $note = BillingNote::create([
                'doc_number' => $numbers->nextBillingNote($branchId),
                'customer_id' => $data['customer_id'],
                'branch_id' => $branchId,
                'doc_date' => now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'total_amount' => $items->sum('balance_amount'),
                'status' => 'open',
                'note' => $data['note'] ?? null,
            ]);

            foreach ($items as $item) {
                BillingNoteItem::create([
                    'billing_note_id' => $note->id,
                    'customer_open_item_id' => $item->id,
                    'balance_at_billing' => (float) $item->balance_amount,
                ]);
            }

            return $note;
        });

        return redirect()->route('billing-notes.show', $note)
            ->with('success', "สร้างใบวางบิล {$note->doc_number} แล้ว ({$items->count()} รายการ)");
    }

    public function show(BillingNote $billingNote): View
    {
        $billingNote->load([
            'customer.addresses', 'branch',
            'items.openItem.document.documentType',
        ]);

        return view('billing-notes.show', ['note' => $billingNote]);
    }

    // อัปเดตสถานะ: ทำเครื่องหมายเก็บเงินแล้ว / ยกเลิก
    public function updateStatus(Request $request, BillingNote $billingNote): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:collected,cancelled,open']]);
        $billingNote->update(['status' => $data['status']]);

        return back()->with('success', 'อัปเดตสถานะใบวางบิลแล้ว');
    }
}
