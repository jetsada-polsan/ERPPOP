<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Supplier;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CustomerPaymentService $customerPayments,
        private readonly SupplierPaymentService $supplierPayments,
    ) {}

    public function storeCustomerPayment(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'method' => ['required', 'string', 'in:cash,transfer,cheque'],
            'cheque_no' => ['nullable', 'required_if:method,cheque', 'string', 'max:40'],
            'cheque_due_date' => ['nullable', 'required_if:method,cheque', 'date'],
            'cheque_bank' => ['nullable', 'string', 'max:100'],
            'open_item_id' => ['required', 'array', 'min:1'],
            'open_item_id.*' => ['integer', 'exists:customer_open_items,id'],
            'amount' => ['required', 'array', 'min:1'],
            'amount.*' => ['nullable', 'numeric', 'min:0'],
        ], [
            'cheque_no.required_if' => 'กรุณากรอกเลขที่เช็ค',
            'cheque_due_date.required_if' => 'กรุณาระบุวันที่บนเช็ค',
        ]);

        $allocations = collect($data['open_item_id'])
            ->map(fn ($id, $index) => [
                'customer_open_item_id' => $id,
                'amount' => $data['amount'][$index] ?? 0,
            ])
            ->values()
            ->all();

        try {
            $document = $this->customerPayments->create([
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'],
                'method' => $data['method'],
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_due_date' => $data['cheque_due_date'] ?? null,
                'cheque_bank' => $data['cheque_bank'] ?? null,
                'allocations' => $allocations,
            ]);
        } catch (RuntimeException $e) {
            return redirect()->route('customers.show', $customer)->with('error', $e->getMessage());
        }

        return redirect()->route('payments.show', $document)
            ->with('success', "บันทึกรับชำระหนี้ {$document->doc_number} แล้ว");
    }

    public function storeSupplierPayment(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'method' => ['required', 'string', 'in:cash,transfer,cheque'],
            'cheque_no' => ['nullable', 'required_if:method,cheque', 'string', 'max:40'],
            'cheque_due_date' => ['nullable', 'required_if:method,cheque', 'date'],
            'cheque_bank' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ], [
            'cheque_no.required_if' => 'กรุณากรอกเลขที่เช็ค',
            'cheque_due_date.required_if' => 'กรุณาระบุวันที่บนเช็ค',
        ]);

        try {
            $document = $this->supplierPayments->create([
                'supplier_id' => $supplier->id,
                'branch_id' => $data['branch_id'],
                'method' => $data['method'],
                'cheque_no' => $data['cheque_no'] ?? null,
                'cheque_due_date' => $data['cheque_due_date'] ?? null,
                'cheque_bank' => $data['cheque_bank'] ?? null,
                'amount' => $data['amount'],
            ]);
        } catch (RuntimeException $e) {
            return redirect()->route('suppliers.show', $supplier)->with('error', $e->getMessage());
        }

        return redirect()->route('payments.show', $document)
            ->with('success', "บันทึกจ่ายชำระหนี้ {$document->doc_number} แล้ว");
    }

    public function show(Document $payment): View
    {
        $payment->load([
            'branch',
            'customer',
            'supplier',
            'documentType',
            'paymentDocument.lines',
            'paymentDocument.allocations.openItem.document',
        ]);

        return view('payments.show', compact('payment'));
    }

    // ใบเสร็จรับเงิน (ลูกค้า) / ใบสำคัญจ่าย (ซัพพลายเออร์) แบบพิมพ์ A4 หัวบริษัทครบ
    public function print(Document $payment): View
    {
        $payment->load([
            'branch', 'customer', 'supplier', 'documentType',
            'paymentDocument.lines',
            'paymentDocument.allocations.openItem.document',
        ]);

        abort_unless(in_array($payment->documentType->code, ['RECEIPT', 'PAYMENT_VOUCHER'], true), 404);

        return view('payments.print', compact('payment'));
    }
}
