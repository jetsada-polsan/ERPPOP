<?php

namespace App\Http\Controllers;

use App\Models\MemberPointRule;
use App\Models\MemberPointTransaction;
use App\Services\Sales\MemberPointService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberPointController extends Controller
{
    public function index(MemberPointService $points): View
    {
        return view('member-points.index', [
            'rules' => MemberPointRule::orderByDesc('id')->paginate(30),
            'transactions' => MemberPointTransaction::with(['member', 'document'])->latest('id')->limit(50)->get(),
            'activeEarnRule' => $points->activeEarnRule(),
            'currentMultiplier' => $points->currentMultiplier(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRule($request);
        MemberPointRule::create($data);

        return redirect()->route('member-points.index')->with('success', "เพิ่มกติกาแต้ม {$data['code']} แล้ว");
    }

    public function update(Request $request, MemberPointRule $memberPointRule): RedirectResponse
    {
        $data = $this->validateRule($request, $memberPointRule->id);
        $memberPointRule->update($data);

        return redirect()->route('member-points.index')->with('success', 'บันทึกกติกาแต้มแล้ว');
    }

    private function validateRule(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:member_point_rules,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'rule_type' => ['required', 'string', 'in:earn,multiplier'],
            'baht_per_point' => ['nullable', 'required_if:rule_type,earn', 'numeric', 'min:0.01'],
            'point_value_baht' => ['nullable', 'numeric', 'min:0'],
            'multiplier' => ['nullable', 'required_if:rule_type,multiplier', 'numeric', 'min:1'],
            'starts_date' => ['nullable', 'date'],
            'ends_date' => ['nullable', 'date', 'after_or_equal:starts_date'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
