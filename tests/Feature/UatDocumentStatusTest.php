<?php

namespace Tests\Feature;

use App\Support\DocumentStatus;
use Tests\TestCase;

class UatDocumentStatusTest extends TestCase
{
    public function test_document_badges_translate_status_using_shared_labels(): void
    {
        $this->assertSame('เสร็จสมบูรณ์', DocumentStatus::label('active'));
        $this->assertSame('ชำระครบแล้ว', DocumentStatus::label('paid'));
        $this->assertSame('รออนุมัติ', DocumentStatus::label('pending_approval'));
        $this->assertSame('-', DocumentStatus::label(null));
        $this->assertSame('ไม่ทราบสถานะ', DocumentStatus::label('unknown'));
        $this->blade("@include('partials.doc-status', ['status' => 'active'])")
            ->assertSee('เสร็จสมบูรณ์')->assertDontSee('>active<', false);
        $paymentView = file_get_contents(resource_path('views/payments/show.blade.php'));
        $this->assertStringContainsString('DocumentStatus::label($payment->paymentDocument?->status)', $paymentView);
    }
}
