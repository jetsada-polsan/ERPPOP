<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPickerUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_campaign_forms_use_the_shared_search_picker(): void
    {
        $this->withoutVite()->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create());

        $this->get(route('promotions.index'))->assertOk()
            ->assertSee('promotionProductPage()', false)
            ->assertSee('openProductPicker(0)', false);

        $this->get(route('qty-promotions.index'))->assertOk()
            ->assertSee('qtyPromotionProductPage()', false)
            ->assertSee('openProductPicker(1)', false);
    }
}
