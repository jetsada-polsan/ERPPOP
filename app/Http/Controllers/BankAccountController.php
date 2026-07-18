<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankAccountController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $accounts = BankAccount::with('branch')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('bank_name', 'ilike', "%{$q}%")
                ->orWhere('account_no', 'ilike', "%{$q}%")
                ->orWhere('account_name', 'ilike', "%{$q}%")
            ))
            ->orderBy('bank_name')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get();

        return view('bank-accounts.index', compact('accounts', 'branches', 'q'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'bank_name' => ['required', 'string', 'max:150'],
            'account_no' => ['required', 'string', 'max:50', 'unique:bank_accounts,account_no'],
            'account_name' => ['nullable', 'string', 'max:150'],
        ]);

        BankAccount::create($data);

        return redirect()->route('bank-accounts.index')->with('success', "เพิ่มบัญชีธนาคาร {$data['account_no']} แล้ว");
    }

    public function update(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'bank_name' => ['required', 'string', 'max:150'],
            'account_no' => ['required', 'string', 'max:50', 'unique:bank_accounts,account_no,'.$bankAccount->id],
            'account_name' => ['nullable', 'string', 'max:150'],
        ]);

        $bankAccount->update($data);

        return redirect()->route('bank-accounts.index')->with('success', 'บันทึกข้อมูลบัญชีธนาคารแล้ว');
    }
}
