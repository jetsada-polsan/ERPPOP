<?php

namespace App\Http\Controllers;

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
            'branch', 'addresses', 'contacts',
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

        $customer->update($data);

        return redirect()->route('customers.show', $customer)->with('success', 'บันทึกข้อมูลลูกค้าแล้ว');
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
