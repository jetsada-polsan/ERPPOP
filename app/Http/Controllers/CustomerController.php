<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SalesArea;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? (string) $request->query('status')
            : 'all';

        $customerScope = Customer::query()->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
            ->where('code', 'ilike', "%{$q}%")
            ->orWhere('name_th', 'ilike', "%{$q}%")
        ));
        $counts = [
            'all' => (clone $customerScope)->count(),
            'active' => (clone $customerScope)->where('is_active', true)->count(),
            'inactive' => (clone $customerScope)->where('is_active', false)->count(),
            // Legacy ARFILE has some rows without AR_NAME. Those were historically
            // imported as name = code; surface them clearly for master-data cleanup.
            'missing_name' => (clone $customerScope)->whereRaw('trim(name_th) = trim(code)')->count(),
        ];

        $customers = (clone $customerScope)
            ->with(['branch', 'salesUser', 'salesArea'])
            ->withSum(['openItems as outstanding_balance' => fn ($query) => $query->whereIn('status', ['open', 'partial'])], 'balance_amount')
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name_th')
            ->paginate(30)
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'q' => $q,
            'status' => $status,
            'counts' => $counts,
            'branches' => Branch::orderBy('code')->get(),
            'salesUsers' => $this->salesUsers(),
            'salesAreas' => $this->salesAreas(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->applySalesAssignment($request, $this->validateCustomer($request));

        $customer = Customer::create($data);

        return redirect()->route('customers.show', $customer)->with('success', "เพิ่มลูกค้า {$customer->code} แล้ว");
    }

    public function show(Request $request, Customer $customer): View
    {
        $this->assertCustomerVisible($request->user(), $customer);
        $customer->load([
            'branch', 'salesUser', 'salesArea', 'addresses', 'contacts', 'creditLimitRequester',
            'openItems' => fn ($q) => $q->orderByDesc('id')->limit(20),
            'openItems.document',
            'crmActivities' => fn ($q) => $q->with('assignedTo')->orderByRaw('status = \'pending\' desc')->orderByRaw('due_at is null, due_at asc')->orderByDesc('id')->limit(20),
        ]);

        $outstandingBalance = (float) $customer->openItems()->whereIn('status', ['open', 'partial'])->sum('balance_amount');

        return view('customers.show', [
            'customer' => $customer,
            'branches' => Branch::orderBy('code')->get(),
            'salesUsers' => $this->salesUsers(),
            'salesAreas' => $this->salesAreas(),
            'outstandingBalance' => $outstandingBalance,
            'activityTypes' => [
                'call' => 'โทรศัพท์', 'visit' => 'เข้าพบ', 'line' => 'LINE',
                'email' => 'อีเมล', 'note' => 'หมายเหตุ',
            ],
            'activityUsers' => $this->salesUsers(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->assertCustomerVisible($request->user(), $customer);
        $data = $this->applySalesAssignment($request, $this->validateCustomer($request, $customer->id), $customer);

        // เปลี่ยนวงเงินเครดิตต้องรออนุมัติ - ไม่แก้ยอดจริงทันที ส่วนฟิลด์อื่นบันทึกได้เลย
        $requestedLimit = $data['credit_limit'] ?? null;
        unset($data['credit_limit']);
        $message = 'บันทึกข้อมูลลูกค้าแล้ว';

        if ($requestedLimit !== null && round((float) $requestedLimit, 2) !== round((float) $customer->credit_limit, 2)) {
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
        $this->assertCustomerVisible($request->user(), $customer);
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
        $this->assertCustomerVisible($request->user(), $customer);
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
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'sales_area_id' => ['nullable', 'integer', 'exists:sales_areas,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function applySalesAssignment(Request $request, array $data, ?Customer $customer = null): array
    {
        $user = $request->user();
        if (! $user->hasPermission('sales.assign')) {
            if ($customer) {
                unset($data['sales_user_id'], $data['sales_area_id']);
            } else {
                $data['sales_user_id'] = $user->id;
                $data['sales_area_id'] = $user->sales_area_id;
            }

            return $data;
        }

        $data['sales_user_id'] = $data['sales_user_id'] ?? null;
        $data['sales_area_id'] = $data['sales_area_id'] ?? null;
        if ($data['sales_user_id'] && ! $data['sales_area_id']) {
            $data['sales_area_id'] = User::find($data['sales_user_id'])?->sales_area_id;
        }

        return $data;
    }

    private function salesUsers()
    {
        return User::where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'sales.manage'))
            ->with('salesArea:id,code,name')
            ->orderBy('username')
            ->get(['id', 'username', 'name', 'sales_area_id']);
    }

    private function salesAreas()
    {
        return SalesArea::where('area_type', 'route')->where('is_active', true)->orderBy('code')->get();
    }

    private function assertCustomerVisible(User $user, Customer $customer): void
    {
        if ($user->branch_id && ! $user->hasPermission('reports.all_branches')
            && $customer->branch_id !== $user->branch_id
            && $customer->sales_user_id !== $user->id) {
            abort(404);
        }
    }
}
