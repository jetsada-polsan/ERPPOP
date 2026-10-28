<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Supplier;
use App\Models\SupplierOpenItem;
use App\Services\Purchasing\SupplierPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * จ่ายชำระเจ้าหนี้ (AP Payment): เลือกใบซื้อค้างจ่ายได้หลายใบในครั้งเดียว
 * จ่ายด้วยเงินสด/โอน/เช็ค และหัก ณ ที่จ่ายได้ - ลงบัญชีผ่าน SupplierPaymentService
 * (Dr เจ้าหนี้การค้า / Cr เงินสดหรือธนาคาร / Cr ภาษีหัก ณ ที่จ่ายค้างจ่าย).
 */
class ApPaymentController extends Controller
{
    public function index(): View
    {
        $outstanding = SupplierOpenItem::query()
            ->whereIn('status', ['open', 'partial'])
            ->where('balance_amount', '>', 0)
            ->groupBy('supplier_id')
            ->select('supplier_id')
            ->selectRaw('count(*) as item_count, sum(balance_amount) as balance, min(due_date) as oldest_due')
            ->orderByDesc('balance')
            ->get();
        $suppliers = Supplier::whereIn('id', $outstanding->pluck('supplier_id'))->get()->keyBy('id');

        $payments = Document::with(['supplier', 'branch'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'PAYMENT_VOUCHER'))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return view('ap-payments.index', compact('outstanding', 'suppliers', 'payments'));
    }

    public function create(Request $request): View
    {
        $suppliersWithDebt = Supplier::whereIn('id', SupplierOpenItem::whereIn('status', ['open', 'partial'])
            ->where('balance_amount', '>', 0)->select('supplier_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name_th', 'tax_id']);

        $supplier = $request->integer('supplier_id')
            ? Supplier::find($request->integer('supplier_id'))
            : null;

        $openItems = $supplier
            ? SupplierOpenItem::where('supplier_id', $supplier->id)
                ->whereIn('status', ['open', 'partial'])
                ->where('balance_amount', '>', 0)
                ->orderBy('due_date')->orderBy('document_date')->orderBy('id')
                ->get()
            : collect();

        return view('ap-payments.create', [
            'suppliersWithDebt' => $suppliersWithDebt,
            'supplier' => $supplier,
            'openItems' => $openItems,
            'preselectedId' => $request->integer('open_item_id') ?: null,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th', 'default_warehouse_location_id']),
        ]);
    }

    public function store(Request $request, SupplierPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'method' => ['required', 'string', 'in:cash,transfer,cheque'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'cheque_no' => ['nullable', 'required_if:method,cheque', 'string', 'max:40'],
            'cheque_due_date' => ['nullable', 'required_if:method,cheque', 'date'],
            'cheque_bank' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.supplier_open_item_id' => ['required', 'integer', 'exists:supplier_open_items,id'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'withholding_base' => ['nullable', 'numeric', 'min:0'],
            'withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:99.9999'],
            'withholding_form' => ['nullable', 'in:PND3,PND53'],
            'withholding_income_type' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'กรุณาเลือกใบซื้อที่จะจ่ายอย่างน้อย 1 ใบ',
            'cheque_no.required_if' => 'กรุณากรอกเลขที่เช็ค',
            'cheque_due_date.required_if' => 'กรุณาระบุวันที่บนเช็ค',
        ]);

        // เฉพาะแถวที่ติ๊กเลือกและใส่ยอดมากกว่า 0
        $allocations = collect($data['items'])
            ->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0)
            ->map(fn ($row) => ['supplier_open_item_id' => (int) $row['supplier_open_item_id'], 'amount' => (float) $row['amount']])
            ->values()
            ->all();

        if ($allocations === []) {
            return back()->withInput()->with('error', 'กรุณาเลือกใบซื้อที่จะจ่ายและใส่ยอดจ่ายมากกว่า 0');
        }

        try {
            $document = DB::transaction(fn () => $payments->create([
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => (int) $data['branch_id'],
                'method' => $data['method'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_due_date' => $data['cheque_due_date'] ?? null,
                'cheque_bank' => $data['cheque_bank'] ?? null,
                'allocations' => $allocations,
                'withholding_base' => $data['withholding_base'] ?? 0,
                'withholding_rate' => $data['withholding_rate'] ?? 0,
                'withholding_form' => $data['withholding_form'] ?? null,
                'withholding_income_type' => $data['withholding_income_type'] ?? null,
            ]));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('payments.show', $document)
            ->with('success', "บันทึกจ่ายชำระเจ้าหนี้ {$document->doc_number} แล้ว");
    }
}
