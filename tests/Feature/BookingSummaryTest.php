<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_page_opens_without_bookings(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create(['is_active' => true, 'must_change_password' => false]))
            ->get(route('bookings.summary'))->assertOk()->assertSee('ไม่มีใบจองค้าง');
    }
}
