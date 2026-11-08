<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\TradeAgreement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TradeAgreementController extends Controller
{
    public function customer(Request $request, Customer $customer, ?int $agreement = null): RedirectResponse
    {
        app(CustomerController::class)->assertCustomerVisible($request->user(), $customer);

        return $this->save($request, $customer, $agreement);
    }

    public function supplier(Request $request, Supplier $supplier, ?int $agreement = null): RedirectResponse
    {
        return $this->save($request, $supplier, $agreement);
    }

    private function save(Request $request, Customer|Supplier $party, ?int $agreementId): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'vat_mode' => ['required', Rule::in(array_keys(TradeAgreement::VAT_MODES))],
            'prices_include_vat' => ['required', 'boolean'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'credit_days' => ['nullable', 'integer', 'between:0,3650'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('starts_on'), 'after_or_equal:starts_on')],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ]);
        $data['is_default'] = $request->boolean('is_active') && $request->boolean('is_default');

        DB::transaction(function () use ($request, $party, $agreementId, $data) {
            $party = $party->newQuery()->lockForUpdate()->findOrFail($party->id);
            $agreement = $agreementId ? $party->tradeAgreements()->findOrFail($agreementId) : $party->tradeAgreements()->make();
            $previous = $agreement->exists ? $agreement->getAttributes() : null;

            if ($data['is_default']) {
                $party->tradeAgreements()->where('is_default', true)
                    ->when($agreement->exists, fn ($q) => $q->whereKeyNot($agreement->id))
                    ->update(['is_default' => false]);
            }

            $agreement->fill($data)->save();
            AuditLog::create([
                'user_id' => $request->user()->id,
                'branch_id' => $party instanceof Customer ? $party->branch_id : null,
                'action' => $previous ? 'update' : 'create',
                'table_name' => 'trade_agreements',
                'record_id' => $agreement->id,
                'old_values' => $previous,
                'new_values' => $agreement->getAttributes(),
            ]);
        });

        return redirect()->to(route($party instanceof Customer ? 'customers.show' : 'suppliers.show', $party).'#trade-agreements')
            ->with('success', 'บันทึกข้อตกลงซื้อขายแล้ว');
    }
}
