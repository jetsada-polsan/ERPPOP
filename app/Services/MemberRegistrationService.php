<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MemberRegistrationService
{
    public function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '66')) {
            $digits = '0'.substr($digits, 2);
        }
        if (! preg_match('/^0[689]\d{8}$/', $digits)) {
            throw new RuntimeException('เบอร์โทรศัพท์ไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง');
        }

        return $digits;
    }

    public function register(string $name, ?string $phone, ?int $branchId = null): Member
    {
        $normalized = $this->normalizePhone($phone);

        return DB::transaction(function () use ($name, $phone, $normalized, $branchId): Member {
            if ($normalized && Member::where('phone_normalized', $normalized)->where('is_active', true)->exists()) {
                throw new RuntimeException('เบอร์โทรศัพท์นี้เป็นสมาชิกอยู่แล้ว');
            }

            do {
                $code = 'M'.now()->format('ymd').strtoupper(Str::random(5));
            } while (Member::where('member_code', $code)->exists());

            return Member::create([
                'member_code' => $code,
                'name' => trim($name),
                'phone' => $phone ? trim($phone) : null,
                'phone_normalized' => $normalized,
                'branch_id' => $branchId,
                'points' => 0,
                'is_active' => true,
            ]);
        });
    }
}
