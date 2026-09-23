<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MemberApiController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }
        try {
            $phone = app(MemberRegistrationService::class)->normalizePhone($q);
        } catch (RuntimeException) {
            $phone = null;
        }
        $members = Member::where('is_active', true)
            ->where(fn ($query) => $query
                ->where('member_code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
                ->when($phone, fn ($query) => $query->orWhere('phone_normalized', $phone)))
            ->orderBy('member_code')->limit(20)
            ->get(['id', 'member_code', 'name', 'phone', 'points']);

        return response()->json($members);
    }

    public function store(Request $request, MemberRegistrationService $registration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $device = $request->attributes->get('pos_device');
        try {
            $member = $registration->register($data['name'], $data['phone'] ?? null, $device?->branch_id);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'member' => $member->only(['id', 'member_code', 'name', 'phone', 'points'])], 201);
    }

    public function show(Member $member): JsonResponse
    {
        abort_unless($member->is_active, 404);

        return response()->json([
            'id' => $member->id,
            'member_code' => $member->member_code,
            'name' => $member->name,
            'phone' => $member->phone,
            'points' => (float) $member->points,
        ]);
    }
}
