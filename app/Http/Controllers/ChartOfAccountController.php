<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Services\Accounting\ChartOfAccountImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ChartOfAccountController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $accounts = ChartOfAccount::orderBy('code')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
                ->orWhere('name_en', 'ilike', "%{$q}%")
            ))
            ->get()
            ->groupBy('account_type');

        $roleHolders = ChartOfAccount::whereNotNull('default_role')->get()->keyBy('default_role');

        return view('chart-of-accounts.index', [
            'accounts' => $accounts,
            'types' => ChartOfAccount::TYPES,
            'roles' => ChartOfAccount::ROLES,
            'roleHolders' => $roleHolders,
            'q' => $q,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAccount($request);

        DB::transaction(function () use ($data) {
            $this->clearExistingRoleHolder($data['default_role'] ?? null);
            ChartOfAccount::create($data);
        });

        return redirect()->route('chart-of-accounts.index')->with('success', "เพิ่มผังบัญชี {$data['code']} แล้ว");
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount): RedirectResponse
    {
        $data = $this->validateAccount($request, $chartOfAccount->id);

        DB::transaction(function () use ($data, $chartOfAccount) {
            $this->clearExistingRoleHolder($data['default_role'] ?? null, $chartOfAccount->id);
            $chartOfAccount->update($data);
        });

        return redirect()->route('chart-of-accounts.index')->with('success', 'บันทึกผังบัญชีแล้ว');
    }

    public function import(Request $request, ChartOfAccountImportService $importer): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'assign_default_roles' => ['nullable', 'boolean'],
        ]);

        try {
            $stats = $importer->import(
                $data['file']->getRealPath(),
                $request->boolean('assign_default_roles', true)
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('chart-of-accounts.index')->with(
            'success',
            "นำเข้าผังบัญชีแล้ว: เพิ่ม {$stats['created']} / อัปเดต {$stats['updated']} / ข้าม {$stats['skipped']} / ตั้ง role {$stats['roles']}"
        );
    }

    // Only one account may hold a given posting role at a time - assigning it
    // here automatically un-assigns whichever account held it before.
    private function clearExistingRoleHolder(?string $role, ?int $exceptId = null): void
    {
        if ($role === null) {
            return;
        }

        ChartOfAccount::where('default_role', $role)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['default_role' => null]);
    }

    private function validateAccount(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:chart_of_accounts,code,'.($ignoreId ?? 'NULL').',id'],
            'name_th' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'account_type' => ['required', 'string', 'in:'.implode(',', array_keys(ChartOfAccount::TYPES))],
            'default_role' => ['nullable', 'string', 'in:'.implode(',', array_keys(ChartOfAccount::ROLES))],
        ]);

        $data['default_role'] = $data['default_role'] ?: null;

        return $data;
    }
}
