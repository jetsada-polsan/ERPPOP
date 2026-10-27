<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceTableFallbackMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_page_explains_base_price_fallback(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->get(route('price-tables.index'))->assertOk()->assertSee('POS จะใช้ราคาขายทั่วไปของสินค้า');
    }
}
