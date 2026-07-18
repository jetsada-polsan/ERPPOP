<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $suppliers = Supplier::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('name_th')
            ->paginate(30)
            ->withQueryString();

        // Latest running balance per supplier (supplier_ledger.balance_after of the most recent entry).
        $balances = SupplierLedger::whereIn('supplier_id', $suppliers->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('supplier_id')
            ->pluck('balance_after', 'supplier_id');

        return view('suppliers.index', compact('suppliers', 'q', 'balances'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSupplier($request);

        $supplier = Supplier::create($data);

        return redirect()->route('suppliers.show', $supplier)->with('success', "เพิ่มซัพพลายเออร์ {$supplier->code} แล้ว");
    }

    public function show(Supplier $supplier): View
    {
        $supplier->load([
            'addresses',
            'ledgerEntries' => fn ($q) => $q->orderByDesc('id')->limit(20),
            'ledgerEntries.document',
        ]);

        $currentBalance = (float) ($supplier->ledgerEntries()->orderByDesc('id')->value('balance_after') ?? 0);
        $branches = Branch::orderBy('code')->get();

        return view('suppliers.show', compact('supplier', 'currentBalance', 'branches'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validateSupplier($request, $supplier->id);

        $supplier->update($data);

        return redirect()->route('suppliers.show', $supplier)->with('success', 'บันทึกข้อมูลซัพพลายเออร์แล้ว');
    }

    public function addAddress(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'address_line' => ['required', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        $data['is_default'] = $request->boolean('is_default');

        $supplier->addresses()->create($data);

        return redirect()->route('suppliers.show', $supplier)->with('success', 'เพิ่มที่อยู่แล้ว');
    }

    private function validateSupplier(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:suppliers,code,'.($ignoreId ?? 'NULL').',id'],
            'name_th' => ['required', 'string', 'max:250'],
            'name_en' => ['nullable', 'string', 'max:250'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'tax_branch' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
