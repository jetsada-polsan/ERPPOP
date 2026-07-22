<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->with('branch')
            ->withSum(['openItems as outstanding_balance' => fn ($query) => $query->whereIn('status', ['open', 'partial'])], 'balance_amount')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('name_th')
            ->paginate(30)
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'q' => $q,
            'branches' => Branch::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCustomer($request);

        $customer = Customer::create($data);

        return redirect()->route('customers.show', $customer)->with('success', "เพิ่มลูกค้า {$customer->code} แล้ว");
    }

    public function show(Customer $customer): View
    {
        $customer->load([
            'branch', 'addresses', 'contacts', 'creditLimitRequester',
            'openItems' => fn ($q) => $q->orderByDesc('id')->limit(20),
            'openItems.document',
        ]);

        $outstandingBalance = (float) $customer->openItems()->whereIn('status', ['open', 'partial'])->sum('balance_amount');

        return view('customers.show', [
            'customer' => $customer,
            'branches' => Branch::orderBy('code')->get(),
            'outstandingBalance' => $outstandingBalance,
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validateCustomer($request, $customer->id);

        // เปลี่ยนวงเงินเครดิตต้องรออนุมัติ - ไม่แก้ยอดจริงทันที ส่วนฟิลด์อื่นบันทึกได้เลย
        $requestedLimit = $data['credit_limit'] ?? null;
        unset($data['credit_limit']);
        $message = 'บันทึกข้อมูลลูกค้าแล้ว';

        if ($requestedLimit !== null && bccomp((string) $requestedLimit, (string) $customer->credit_limit, 2) !== 0) {
            $data['pending_credit_limit'] = $requestedLimit;
            $data['credit_limit_requested_by'] = $request->user()->id;
            $data['credit_limit_requested_at'] = now();
            $message = 'บันทึกข้อมูลลูกค้าแล้ว และส่งคำขอเปลี่ยนวงเงินเครดิตรออนุมัติแล้ว';
        }

        $customer->update($data);

        return redirect()->route('customers.show', $customer)->with('success', $message);
    }

    public function approveCreditLimit(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($customer->credit_limit_requested_by !== null, 422, 'ไม่มีคำขอเปลี่ยนวงเงินเครดิตค้างอยู่');
        abort_if($customer->credit_limit_requested_by === $request->user()->id, 403, 'ผู้ขอไม่สามารถอนุมัติรายการของตนเอง');

        $oldLimit = $customer->credit_limit;
        $customer->update([
            'credit_limit' => $customer->pending_credit_limit,
            'pending_credit_limit' => null,
            'credit_limit_requested_by' => null,
            'credit_limit_requested_at' => null,
        ]);
        AuditLog::create([
            'user_id' => $request->user()->id, 'branch_id' => $customer->branch_id,
            'action' => 'approve', 'table_name' => 'customers', 'record_id' => $customer->id,
            'old_values' => ['credit_limit' => $oldLimit], 'new_values' => ['credit_limit' => $customer->credit_limit],
        ]);

        return back()->with('success', 'อนุมัติวงเงินเครดิตใหม่แล้ว');
    }

    public function rejectCreditLimit(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($customer->credit_limit_requested_by !== null, 422, 'ไม่มีคำขอเปลี่ยนวงเงินเครดิตค้างอยู่');
        abort_if($customer->credit_limit_requested_by === $request->user()->id, 403, 'ผู้ขอไม่สามารถปฏิเสธรายการของตนเอง');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $rejectedLimit = $customer->pending_credit_limit;
        $customer->update([
            'pending_credit_limit' => null,
            'credit_limit_requested_by' => null,
            'credit_limit_requested_at' => null,
        ]);
        AuditLog::create([
            'user_id' => $request->user()->id, 'branch_id' => $customer->branch_id,
            'action' => 'reject', 'table_name' => 'customers', 'record_id' => $customer->id,
            'old_values' => ['pending_credit_limit' => $rejectedLimit], 'new_values' => ['reason' => $data['reason']],
        ]);

        return back()->with('success', 'ปฏิเสธคำขอเปลี่ยนวงเงินเครดิตแล้ว');
    }

    public function addAddress(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'address_line' => ['required', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        $data['is_default'] = $request->boolean('is_default');

        $customer->addresses()->create($data);

        return redirect()->route('customers.show', $customer)->with('success', 'เพิ่มที่อยู่แล้ว');
    }

    public function addContact(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        $customer->contacts()->create($data);

        return redirect()->route('customers.show', $customer)->with('success', 'เพิ่มผู้ติดต่อแล้ว');
    }

    private function validateCustomer(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:customers,code,'.($ignoreId ?? 'NULL').',id'],
            'name_th' => ['required', 'string', 'max:250'],
            'name_en' => ['nullable', 'string', 'max:250'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'tax_branch' => ['nullable', 'string', 'max:10'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
