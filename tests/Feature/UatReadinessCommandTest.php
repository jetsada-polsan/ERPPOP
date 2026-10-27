<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PriceTagTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UatReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_report_is_read_only_and_lists_incomplete_data(): void
    {
        $customer = Customer::create(['code' => 'NEEDS-REVIEW', 'name_th' => 'ลูกค้าตรวจสอบ', 'is_active' => true]);
        $before = $customer->fresh()->getAttributes();
        $this->artisan('erp:uat-readiness', ['--dry-run' => true])
            ->expectsOutputToContain('READ ONLY')->expectsOutputToContain('NEEDS-REVIEW')->assertSuccessful();
        $this->assertSame($before, $customer->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_removing_the_identified_test_tag_requires_an_exact_match_and_supports_dry_run(): void
    {
        $tag = PriceTagTemplate::create(['code' => 'TEST01', 'name' => 'TEST-Claude', 'price_source' => 'default']);
        $other = PriceTagTemplate::create(['code' => 'KEEP', 'name' => 'TEST-Claude', 'price_source' => 'default']);
        $this->artisan('erp:remove-test-price-tag', ['--dry-run' => true])->assertSuccessful();
        $this->assertModelExists($tag);
        $this->assertSame(0, AuditLog::count());
        $this->artisan('erp:remove-test-price-tag')->assertSuccessful();
        $this->assertModelMissing($tag);
        $this->assertModelExists($other);
        $this->assertDatabaseHas('audit_logs', ['action' => 'uat_test_price_tag_delete', 'record_id' => $tag->id]);
        $this->artisan('erp:remove-test-price-tag')->assertSuccessful();
        $this->assertSame(1, AuditLog::count());
    }

    public function test_real_template_reusing_test_code_is_not_deleted(): void
    {
        $tag = PriceTagTemplate::create(['code' => 'TEST01', 'name' => 'ป้ายใช้งานจริง', 'price_source' => 'default']);
        $this->artisan('erp:remove-test-price-tag')->assertFailed();
        $this->assertModelExists($tag);
    }
}
