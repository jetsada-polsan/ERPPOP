<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Member;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCategoryVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_product_category_is_hidden_from_web_pos(): void
    {
        $this->withoutVite();
        ProductCategory::create(['code' => 'CANCEL', 'name_th' => 'สินค้ายกเลิก']);
        ProductCategory::create(['code' => 'ACTIVE', 'name_th' => 'อาหารสด']);
        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->get(route('pos.index'));
        $response->assertOk()->assertViewHas('categories', fn ($categories) => $categories->contains('code', 'ACTIVE') && ! $categories->contains('code', 'CANCEL'));
        $this->assertDatabaseHas('product_categories', ['code' => 'CANCEL']);
    }
}
