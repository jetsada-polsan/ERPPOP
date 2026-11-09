<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentBookNoVatTest extends TestCase
{
    use RefreshDatabase;

    private function creditSaleType(): DocumentType
    {
        return DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ขายเชื่อ', 'stock_effect' => 'out']);
    }

    private function asUser()
    {
        return $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create());
    }

    public function test_a_new_book_can_be_marked_as_no_vat(): void
    {
        $type = $this->creditSaleType();

        $this->asUser()->post(route('document-books.store'), [
            'document_type_id' => $type->id, 'code' => 'DSN2', 'prefix' => 'DSNB', 'name' => 'เล่ม N2', 'no_vat' => '1',
        ])->assertRedirect(route('document-books.index'));

        $this->assertTrue((bool) DocumentBook::where('code', 'DSN2')->value('no_vat'));
    }

    public function test_an_unused_book_can_change_its_vat_setting(): void
    {
        $book = DocumentBook::create(['code' => 'DSX', 'name' => 'x', 'prefix' => 'DSX', 'document_type_id' => $this->creditSaleType()->id, 'is_default' => false, 'is_active' => true, 'no_vat' => false]);

        $this->asUser()->put(route('document-books.update', $book), ['name' => 'x', 'is_active' => '1', 'no_vat' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $book->fresh()->no_vat);
    }

    public function test_a_book_with_documents_cannot_change_its_vat_setting(): void
    {
        $book = DocumentBook::create(['code' => 'DSY', 'name' => 'y', 'prefix' => 'DSY', 'document_type_id' => $this->creditSaleType()->id, 'is_default' => false, 'is_active' => true, 'no_vat' => false]);
        $branch = \App\Models\Branch::create(['code' => 'BKV', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        Document::create([
            'document_type_id' => $book->document_type_id, 'document_book_id' => $book->id, 'branch_id' => $branch->id,
            'doc_number' => 'DSY-0001', 'doc_date' => now()->toDateString(), 'status' => 'active', 'total_items' => 0, 'total_amount' => 0,
        ]);

        $this->asUser()->put(route('document-books.update', $book), ['name' => 'y2', 'is_active' => '1', 'no_vat' => '1'])
            ->assertSessionHasErrors('no_vat');

        $this->assertFalse((bool) $book->fresh()->no_vat);
        $this->assertSame('y', $book->fresh()->name);

        // ไม่ส่งช่องภาษีมา (ฟอร์มซ่อนไว้) ต้องแก้ชื่อได้ตามปกติ และค่าภาษีเดิมคงอยู่
        $this->asUser()->put(route('document-books.update', $book), ['name' => 'y3', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('y3', $book->fresh()->name);
        $this->assertFalse((bool) $book->fresh()->no_vat);
    }
}
