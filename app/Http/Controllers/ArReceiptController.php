<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * ใบเสร็จรับเงิน (รับชำระลูกหนี้) แบบ BPlus: เลือกลูกค้า → ดึงใบขายเชื่อค้าง (DS/DSN) มาติ๊กเลือก
 * → เลือกเงินสด/โอน/เช็ค → บันทึก. ลงบัญชีผ่าน CustomerPaymentService เดิม (ไม่เปลี่ยนสูตร)
 */
class ArReceiptController extends Controller
{
    public function index(): View
    {
        $outstanding = CustomerOpenItem::query()
            ->whereIn('status', [CustomerOpenItem::STATUS_OPEN, CustomerOpenItem::STATUS_PARTIAL])
            ->where('balance_amount', '>', 0)
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw('count(*) as item_count, sum(balance_amount) as balance, min(due_date) as oldest_due')
            ->orderByDesc('balance')
            ->get();
        $customers = Customer::whereIn('id', $outstanding->pluck('customer_id'))->get()->keyBy('id');

        $receipts = Document::with(['customer', 'branch'])
            ->whereHas('documentType', fn ($q) => $q->where('code', 'RECEIPT'))
            ->orderByDesc('id')->limit(30)->get();

        return view('ar-receipts.index', compact('outstanding', 'customers', 'receipts'));
    }

    public function create(Request $request): View
    {
        $customersWithDebt = Customer::whereIn('id', CustomerOpenItem::whereIn('status', [CustomerOpenItem::STATUS_OPEN, CustomerOpenItem::STATUS_PARTIAL])
            ->where('balance_amount', '>', 0)->select('customer_id'))
            ->orderBy('code')->get(['id', 'code', 'name_th']);
        $customer = $request->integer('customer_id') ? Customer::find($request->integer('customer_id')) : null;
        $openItems = $customer
            ? CustomerOpenItem::with('document.documentBook')->where('customer_id', $customer->id)
                ->whereIn('status', [CustomerOpenItem::STATUS_OPEN, CustomerOpenItem::STATUS_PARTIAL])
                ->where('balance_amount', '>', 0)
                ->orderBy('due_date')->orderBy('id')->get()
            : collect();

        return view('ar-receipts.create', [
            'customersWithDebt' => $customersWithDebt,
            'customer' => $customer,
            'openItems' => $openItems,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
        ]);
    }

    public function store(Request $request, CustomerPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'method' => ['required', 'string', 'in:cash,transfer,cheque'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'transfer_ref' => ['nullable', 'string', 'max:60'],
            'transfer_at' => ['nullable', 'date'],
            'cheque_no' => ['nullable', 'required_if:method,cheque', 'string', 'max:40'],
            'cheque_due_date' => ['nullable', 'required_if:method,cheque', 'date'],
            'cheque_bank' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.customer_open_item_id' => ['required', 'integer', 'exists:customer_open_items,id'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'items.required' => 'กรุณาเลือกบิลที่จะรับชำระอย่างน้อย 1 ใบ',
            'cheque_no.required_if' => 'กรุณากรอกเลขที่เช็ค',
            'cheque_due_date.required_if' => 'กรุณาระบุวันที่บนเช็ค',
        ]);

        $customerId = (int) $data['customer_id'];
        $allocations = collect($data['items'])
            ->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0)
            ->map(fn ($row) => ['customer_open_item_id' => (int) $row['customer_open_item_id'], 'amount' => $row['amount']])
            ->values()->all();
        if ($allocations === []) {
            return back()->withInput()->with('error', 'กรุณาเลือกบิลที่จะรับชำระและใส่ยอดมากกว่า 0');
        }
        // กันส่งบิลของลูกค้าคนอื่นมาปน
        $foreign = CustomerOpenItem::whereIn('id', array_column($allocations, 'customer_open_item_id'))
            ->where('customer_id', '!=', $customerId)->exists();
        if ($foreign) {
            return back()->withInput()->with('error', 'มีบิลที่ไม่ใช่ของลูกค้ารายนี้ กรุณาเลือกใหม่');
        }

        try {
            $document = $payments->create([
                'customer_id' => $customerId,
                'branch_id' => (int) $data['branch_id'],
                'method' => $data['method'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'transfer_ref' => $data['transfer_ref'] ?? null,
                'transfer_at' => $data['transfer_at'] ?? null,
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_due_date' => $data['cheque_due_date'] ?? null,
                'cheque_bank' => $data['cheque_bank'] ?? null,
                'allocations' => $allocations,
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('payments.show', $document)->with('success', "ออกใบเสร็จรับเงิน {$document->doc_number} แล้ว");
    }
}
