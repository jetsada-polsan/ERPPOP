<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\Salesman;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

/**
 * ใบเสร็จรับเงิน (รับชำระลูกหนี้) แบบ BPlus: เลือกลูกค้า → ดึงใบขายเชื่อค้าง (DS/DSN) มาติ๊กเลือก
 * → ใส่เงินสด/โอน/เช็ค ในใบเดียว → บันทึก. ลงบัญชีผ่าน CustomerPaymentService เดิม (ไม่เปลี่ยนสูตร)
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

        $receipts = Document::with(['customer', 'branch', 'salesman'])
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

        $bankAccounts = BankAccount::with('branch')
            ->whereNotNull('chart_of_account_id')
            ->where('is_active', true)
            ->orderBy('bank_name')
            ->get();

        $salesmen = Salesman::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'branch_id']);

        return view('ar-receipts.create', [
            'customersWithDebt' => $customersWithDebt,
            'customer' => $customer,
            'openItems' => $openItems,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'bankAccounts' => $bankAccounts,
            'salesmen' => $salesmen,
        ]);
    }

    public function openItems(Customer $customer): JsonResponse
    {
        $openItems = CustomerOpenItem::with('document')
            ->where('customer_id', $customer->id)
            ->whereIn('status', [CustomerOpenItem::STATUS_OPEN, CustomerOpenItem::STATUS_PARTIAL])
            ->where('balance_amount', '>', 0)
            ->orderBy('due_date')->orderBy('id')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'doc_number' => $item->document?->doc_number ?? '-',
                'doc_date' => $item->document?->doc_date?->thaiDate() ?? '-',
                'due_date' => $item->due_date ? Carbon::parse($item->due_date)->thaiDate() : '-',
                'balance' => round((float) $item->balance_amount, 2),
            ])
            ->values();

        return response()->json($openItems);
    }

    public function store(Request $request, CustomerPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'salesman_id' => ['nullable', 'integer', 'exists:salesmen,id'],
            'doc_date' => ['nullable', 'date'],
            // Support either mixed payments array OR single legacy method
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', 'string', 'in:cash,transfer,cheque'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0'],
            'payments.*.bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'payments.*.transfer_ref' => ['nullable', 'string', 'max:60'],
            'payments.*.transfer_at' => ['nullable', 'date'],
            'payments.*.cheque_no' => ['nullable', 'string', 'max:40'],
            'payments.*.cheque_due_date' => ['nullable', 'date'],
            'payments.*.cheque_bank' => ['nullable', 'string', 'max:100'],
            // BPlus form structured fields
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'transfer_amount' => ['nullable', 'numeric', 'min:0'],
            'transfer_bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'transfer_ref' => ['nullable', 'string', 'max:60'],
            'transfer_at' => ['nullable', 'date'],
            'cheques' => ['nullable', 'array'],
            'cheques.*.amount' => ['nullable', 'numeric', 'min:0'],
            'cheques.*.cheque_bank' => ['nullable', 'string', 'max:100'],
            'cheques.*.cheque_no' => ['nullable', 'string', 'max:40'],
            'cheques.*.cheque_due_date' => ['nullable', 'date'],
            // Legacy single method fallback
            'method' => ['nullable', 'string', 'in:cash,transfer,cheque'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'cheque_no' => ['nullable', 'string', 'max:40'],
            'cheque_due_date' => ['nullable', 'date'],
            'cheque_bank' => ['nullable', 'string', 'max:100'],
            // Allocations
            'items' => ['nullable', 'array'],
            'items.*.customer_open_item_id' => ['required_with:items', 'integer', 'exists:customer_open_items,id'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.customer_open_item_id' => ['required_with:allocations', 'integer', 'exists:customer_open_items,id'],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customerId = (int) $data['customer_id'];
        $rawAllocations = $data['allocations'] ?? $data['items'] ?? [];
        $allocations = collect($rawAllocations)
            ->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0)
            ->map(fn ($row) => ['customer_open_item_id' => (int) $row['customer_open_item_id'], 'amount' => (float) $row['amount']])
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

        // Build payments list
        $paymentsList = null;
        if (! empty($data['payments'])) {
            $paymentsList = collect($data['payments'])
                ->filter(fn ($p) => (float) ($p['amount'] ?? 0) > 0)
                ->values()->all();
        } elseif ($request->has('cash_amount') || $request->has('transfer_amount') || $request->has('cheques')) {
            $paymentsList = [];
            $cash = (float) $request->input('cash_amount', 0);
            if ($cash > 0) {
                $paymentsList[] = [
                    'method' => 'cash',
                    'amount' => $cash,
                ];
            }
            $transfer = (float) $request->input('transfer_amount', 0);
            if ($transfer > 0) {
                $paymentsList[] = [
                    'method' => 'transfer',
                    'amount' => $transfer,
                    'bank_account_id' => $request->input('transfer_bank_account_id') ? (int) $request->input('transfer_bank_account_id') : null,
                    'transfer_ref' => $request->input('transfer_ref') ?: null,
                    'transfer_at' => $request->input('transfer_at') ?: null,
                ];
            }
            foreach ((array) $request->input('cheques', []) as $ch) {
                $chAmount = (float) ($ch['amount'] ?? 0);
                if ($chAmount > 0) {
                    if (empty($ch['cheque_no']) || empty($ch['cheque_due_date'])) {
                        return back()->withInput()->with('error', 'กรุณากรอกเลขที่เช็คและวันที่บนเช็คให้ครบถ้วน');
                    }
                    $paymentsList[] = [
                        'method' => 'cheque',
                        'amount' => $chAmount,
                        'cheque_bank' => $ch['cheque_bank'] ?? null,
                        'cheque_no' => $ch['cheque_no'] ?? null,
                        'cheque_due_date' => $ch['cheque_due_date'] ?? null,
                    ];
                }
            }
        }

        if ($paymentsList !== null && empty($paymentsList)) {
            return back()->withInput()->with('error', 'กรุณาระบุยอดชำระเงินสด โอน หรือเช็ค อย่างน้อย 1 รายการ');
        }

        $payload = [
            'customer_id' => $customerId,
            'branch_id' => (int) $data['branch_id'],
            'salesman_id' => ! empty($data['salesman_id']) ? (int) $data['salesman_id'] : null,
            'doc_date' => $data['doc_date'] ?? now()->toDateString(),
            'allocations' => $allocations,
        ];

        if ($paymentsList !== null) {
            $payload['payments'] = $paymentsList;
        } else {
            if (empty($data['method'])) {
                return back()->withInput()->with('error', 'กรุณาเลือกวิธีรับชำระ');
            }
            if ($data['method'] === 'cheque' && (empty($data['cheque_no']) || empty($data['cheque_due_date']))) {
                return back()->withInput()->with('error', 'กรุณากรอกเลขที่เช็คและวันที่บนเช็ค');
            }
            $payload['method'] = $data['method'];
            $payload['bank_account_id'] = $data['bank_account_id'] ?? null;
            $payload['transfer_ref'] = $data['transfer_ref'] ?? null;
            $payload['transfer_at'] = $data['transfer_at'] ?? null;
            $payload['cheque_no'] = $data['cheque_no'] ?? null;
            $payload['cheque_due_date'] = $data['cheque_due_date'] ?? null;
            $payload['cheque_bank'] = $data['cheque_bank'] ?? null;
        }

        try {
            $document = $payments->create($payload);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('payments.show', $document)->with('success', "ออกใบเสร็จรับเงิน {$document->doc_number} แล้ว");
    }
}
