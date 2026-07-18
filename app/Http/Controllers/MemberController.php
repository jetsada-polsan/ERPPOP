<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Member;
use App\Models\MemberType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(): View
    {
        $members = Member::with(['memberType', 'branch'])->orderBy('member_code')->paginate(50);
        $memberTypes = MemberType::orderBy('code')->get();
        $branches = Branch::orderBy('code')->get();

        return view('members.index', compact('members', 'memberTypes', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateMember($request);
        Member::create($data);

        return redirect()->route('members.index')->with('success', "เพิ่มสมาชิก {$data['member_code']} แล้ว");
    }

    public function update(Request $request, Member $member): RedirectResponse
    {
        $data = $this->validateMember($request, $member->id);
        $member->update($data);

        return redirect()->route('members.index')->with('success', 'บันทึกสมาชิกแล้ว');
    }

    private function validateMember(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'member_code' => ['required', 'string', 'max:30', 'unique:members,member_code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'member_type_id' => ['nullable', 'integer', 'exists:member_types,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'points' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['points'] = $data['points'] ?? 0;
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
