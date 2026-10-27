<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\QrPaymentConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankAccountController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $accounts = BankAccount::with(['branch', 'chartOfAccount'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('bank_name', 'ilike', "%{$q}%")
                ->orWhere('account_no', 'ilike', "%{$q}%")
                ->orWhere('account_name', 'ilike', "%{$q}%")
            ))
            ->orderBy('bank_name')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get();
        // บัญชีในผัง GL ที่ผูกได้: หมวดสินทรัพย์ (เงินสด/เงินฝาก)
        $glAccounts = ChartOfAccount::where('account_type', 'asset')->orderBy('code')->get(['id', 'code', 'name_th']);

        // ภาพรวมรายสาขา: สาขาไหนตั้งบัญชีรับเงินแล้ว สาขาไหนยังว่าง (ใช้บัญชีกลางไปก่อน)
        $allBanks = BankAccount::with('chartOfAccount')->get();
        $qrConfigs = QrPaymentConfig::with('bankAccount')->where('is_active', true)->get();
        $centralBank = $allBanks->whereNull('branch_id')->whereNotNull('chart_of_account_id')->sortBy('id')->first();
        $fallbackGl = $centralBank?->chartOfAccount ?? ChartOfAccount::where('default_role', ChartOfAccount::ROLE_BANK)->first();
        $centralQr = $qrConfigs->first(fn ($config) => $config->bankAccount?->branch_id === null);
        $overview = Branch::where('is_active', true)->orderBy('code')->get()->map(fn (Branch $branch) => [
            'branch' => $branch,
            'bank' => $allBanks->where('branch_id', $branch->id)->sortBy('id')->first(),
            'qr' => $qrConfigs->first(fn ($config) => (int) $config->bankAccount?->branch_id === $branch->id),
        ]);

        return view('bank-accounts.index', compact('accounts', 'branches', 'q', 'glAccounts', 'overview', 'fallbackGl', 'centralQr'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'bank_name' => ['required', 'string', 'max:150'],
            'account_no' => ['required', 'string', 'max:50', 'unique:bank_accounts,account_no'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'chart_of_account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
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
            'chart_of_account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
        ]);

        $bankAccount->update($data);

        return redirect()->route('bank-accounts.index')->with('success', 'บันทึกข้อมูลบัญชีธนาคารแล้ว');
    }
}
