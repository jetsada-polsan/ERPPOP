<?php

namespace Tests\Feature;

use App\Models\BillingNote;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingNoteCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_invoices_must_be_settled_and_every_related_note_is_updated(): void
    {
        [$note, $first, $second] = $this->billing();
        $other = $note->replicate();
        $other->doc_number = 'BN-2';
        $other->save();
        $other->items()->create(['customer_open_item_id' => $first->id, 'balance_at_billing' => 100]);

        $first->update(['balance_amount' => 0, 'status' => 'paid']);
        $this->assertSame('open', $note->fresh()->status);
        $this->assertSame('collected', $other->fresh()->status);
        $second->update(['balance_amount' => 0.01, 'status' => 'paid']);
        $this->assertSame('collected', $note->fresh()->status);
        $first->update(['balance_amount' => 100, 'status' => 'open']);
        $this->assertSame('open', $note->fresh()->status);
        $this->assertSame('open', $other->fresh()->status);
    }

    public function test_cancelled_notes_and_unrelated_notes_are_unchanged(): void
    {
        [$note, $first, $second] = $this->billing();
        $note->update(['status' => 'cancelled']);
        $first->update(['balance_amount' => 0, 'status' => 'paid']);
        $second->update(['balance_amount' => 0, 'status' => 'paid']);
        $this->assertSame('cancelled', $note->fresh()->status);
        $unrelated = $note->replicate();
        $unrelated->doc_number = 'BN-unrelated';
        $unrelated->status = 'open';
        $unrelated->save();
        $first->update(['balance_amount' => 1, 'status' => 'partial']);
        $this->assertSame('open', $unrelated->fresh()->status);
    }

    public function test_billing_status_rolls_back_with_the_payment_transaction(): void
    {
        [$note, $first, $second] = $this->billing();
        DB::beginTransaction();
        $first->update(['balance_amount' => 0, 'status' => 'paid']);
        $second->update(['balance_amount' => 0, 'status' => 'paid']);
        $this->assertSame('collected', $note->fresh()->status);
        DB::rollBack();
        $this->assertSame('open', $note->fresh()->status);
        $this->assertSame(100.0, (float) $first->fresh()->balance_amount);
    }

    private function billing(): array
    {
        $branch = Branch::create(['code' => 'BN', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $customer = Customer::create(['code' => 'BN-C', 'name_th' => 'ลูกค้า']);
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ขายเชื่อ']);
        $note = BillingNote::create([
            'doc_number' => 'BN-1', 'customer_id' => $customer->id,
            'doc_date' => now()->toDateString(), 'total_amount' => 200, 'status' => 'open',
        ]);
        $items = [];
        foreach ([1, 2] as $i) {
            $document = Document::create([
                'branch_id' => $branch->id, 'document_type_id' => $type->id, 'doc_number' => 'BN-CR-'.$i,
                'doc_date' => now()->toDateString(), 'customer_id' => $customer->id,
                'status' => 'active', 'total_amount' => 100,
            ]);
            $item = CustomerOpenItem::create([
                'customer_id' => $customer->id, 'document_id' => $document->id,
                'gross_amount' => 100, 'net_amount' => 100, 'paid_amount' => 0,
                'balance_amount' => 100, 'status' => 'open',
            ]);
            $note->items()->create(['customer_open_item_id' => $item->id, 'balance_at_billing' => 100]);
            $items[] = $item;
        }

        return [$note, ...$items];
    }
}
