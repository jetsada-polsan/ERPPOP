<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\PriceTagTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceTagManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_can_be_edited_disabled_and_deleted_from_the_list(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create());
        $tag = PriceTagTemplate::create(['code' => 'TEST01', 'name' => 'TEST-Claude', 'price_source' => 'default', 'is_active' => true]);
        $this->get(route('price-tags.index'))->assertOk()
            ->assertSee(route('price-tags.edit', $tag))->assertSee(route('price-tags.destroy', $tag));
        $this->get(route('price-tags.edit', $tag))->assertOk()->assertSee('TEST-Claude');
        $this->put(route('price-tags.update', $tag), [
            'code' => 'TEST01', 'name' => 'ป้ายแก้ไข', 'price_source' => 'no_price', 'is_active' => 0,
        ])->assertRedirect(route('price-tags.index'));
        $this->assertDatabaseHas('price_tag_templates', ['id' => $tag->id, 'name' => 'ป้ายแก้ไข', 'is_active' => false, 'price_source' => 'no_price']);
        $this->delete(route('price-tags.destroy', $tag))->assertRedirect(route('price-tags.index'));
        $this->assertModelMissing($tag);
    }

    public function test_unauthorized_users_cannot_delete_templates(): void
    {
        $tag = PriceTagTemplate::create(['code' => 'KEEP', 'name' => 'คงไว้', 'price_source' => 'default', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->delete(route('price-tags.destroy', $tag))->assertForbidden();
        $this->assertModelExists($tag);
    }

    public function test_duplicate_codes_and_missing_price_tables_are_rejected(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create());
        $tag = PriceTagTemplate::create(['code' => 'TAG1', 'name' => 'เดิม', 'price_source' => 'default']);
        PriceTagTemplate::create(['code' => 'TAG2', 'name' => 'อื่น', 'price_source' => 'default']);
        $this->put(route('price-tags.update', $tag), ['code' => 'TAG2', 'name' => 'ใหม่', 'price_source' => 'price_table'])
            ->assertSessionHasErrors(['code', 'price_table_id']);
        $this->assertSame('เดิม', $tag->fresh()->name);
    }
}
