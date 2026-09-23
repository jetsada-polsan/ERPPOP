<?php

namespace Tests\Feature;

use App\Services\MemberRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_thai_phone_is_normalized_and_duplicate_registration_is_rejected(): void
    {
        $service = app(MemberRegistrationService::class);
        $member = $service->register('ลูกค้าทดสอบ', '+66 81-234-5678');

        $this->assertSame('0812345678', $member->phone_normalized);
        $this->expectExceptionMessage('เบอร์โทรศัพท์นี้เป็นสมาชิกอยู่แล้ว');
        $service->register('ซ้ำ', '0812345678');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->expectExceptionMessage('เบอร์โทรศัพท์ไม่ถูกต้อง');
        app(MemberRegistrationService::class)->register('ทดสอบ', '1234');
    }
}
