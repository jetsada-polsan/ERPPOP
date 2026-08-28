<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_reports_blocking_checks_without_modifying_business_data(): void
    {
        $this->artisan('erp:readiness')
            ->assertExitCode(1)
            ->expectsOutputToContain('Backup')
            ->expectsOutputToContain('ขาย-GL')
            ->expectsOutputToContain('ยังไม่พร้อม');
    }
}
