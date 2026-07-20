<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\Accounting\AccountingCloseReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountingPeriodController extends Controller
{
    public function index(AccountingCloseReadinessService $readiness): View
    {
        $periods = AccountingPeriod::with(['branch', 'closedBy'])->orderByDesc('starts_on')->get();

        return view('accounting-periods.index', [
            'periods' => $periods,
            'readiness' => $periods->where('status', 'open')->mapWithKeys(fn ($period) => [$period->id => $readiness->checks($period)]),
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $overlap = AccountingPeriod::query()
            ->where(fn ($query) => empty($data['branch_id'])
                ? $query->whereNull('branch_id')
                : $query->where('branch_id', $data['branch_id']))
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'ช่วงวันที่ทับซ้อนกับงวดเดิมในขอบเขตเดียวกัน']);
        }

        AccountingPeriod::create($data + ['status' => 'open']);

        return redirect()->route('accounting-periods.index')->with('success', 'สร้างงวดบัญชีแล้ว');
    }

    public function close(Request $request, AccountingPeriod $accountingPeriod, AccountingCloseReadinessService $readiness): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($accountingPeriod, $data, $readiness) {
            $period = AccountingPeriod::whereKey($accountingPeriod->id)->lockForUpdate()->firstOrFail();
            if ($period->isClosed()) {
                return;
            }

            $readiness->assertReady($period);

            $oldValues = $period->only(['status', 'note', 'closed_by', 'closed_at']);
            $period->update([
                'status' => 'closed',
                'note' => $data['note'] ?? $period->note,
                'closed_by' => auth()->id(),
                'closed_at' => now(),
            ]);
            $this->audit('close', $period, $oldValues);
        });

        return back()->with('success', 'ปิดงวดแล้ว เอกสารและ GL ในช่วงนี้ถูกล็อก');
    }

    public function reopen(AccountingPeriod $accountingPeriod): RedirectResponse
    {
        DB::transaction(function () use ($accountingPeriod) {
            $period = AccountingPeriod::whereKey($accountingPeriod->id)->lockForUpdate()->firstOrFail();
            if (! $period->isClosed()) {
                return;
            }

            $oldValues = $period->only(['status', 'note', 'closed_by', 'closed_at']);
            $period->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
            $this->audit('reopen', $period, $oldValues);
        });

        return back()->with('success', 'เปิดงวดอีกครั้งแล้ว');
    }

    private function audit(string $action, AccountingPeriod $period, array $oldValues): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'branch_id' => $period->branch_id,
            'action' => $action,
            'table_name' => 'accounting_periods',
            'record_id' => $period->id,
            'old_values' => $oldValues,
            'new_values' => $period->fresh()->only(['status', 'note', 'closed_by', 'closed_at']),
        ]);
    }
}
