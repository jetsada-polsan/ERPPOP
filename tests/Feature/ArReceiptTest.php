<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PaymentLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ใบเสร็จรับเงินแบบ BPlus: เลือกลูกค้า → ติ๊กบิลค้าง → เงินสด/โอน → ตัดลูกหนี้ */
class ArReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_pulls_several_credit_bills_and_records_a_transfer_reference(): void
    {
        $this->seed();
        DocumentType::firstOrCreate(['code' => 'RECEIPT'], ['name_th' => 'ใบเสร็จรับเงิน']);
        [$customer, $first, $second] = $this->twoCreditBills();
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $web = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user);

        $web->get(route('ar-receipts.index'))->assertOk()->assertSee($customer->name_th);
        $web->get(route('ar-receipts.create', ['customer_id' => $customer->id]))->assertOk()
            ->assertSee($first->document->doc_number)->assertSee($second->document->doc_number);

        $response = $web->post(route('ar-receipts.store'), [
            'customer_id' => $customer->id,
            'branch_id' => $first->document->branch_id,
            'method' => 'transfer',
            'transfer_ref' => 'KBANK-778899',
            'transfer_at' => '2026-11-10 09:15',
            'items' => [
                ['customer_open_item_id' => $first->id, 'amount' => (float) $first->balance_amount],
                ['customer_open_item_id' => $second->id, 'amount' => 50],
            ],
        ]);
        $receipt = Document::whereHas('documentType', fn ($q) => $q->where('code', 'RECEIPT'))->sole();
        $response->assertRedirect(route('payments.show', $receipt));

        $this->assertSame(CustomerOpenItem::STATUS_PAID, $first->fresh()->status);
        $this->assertSame(round((float) $second->balance_amount - 50, 2), round((float) $second->fresh()->balance_amount, 2));
        $line = PaymentLine::sole();
        $this->assertSame('transfer', $line->method);
        $this->assertSame('KBANK-778899', $line->transfer_ref);
        $web->get(route('payments.print', $receipt))->assertOk()->assertSee('KBANK-778899');
    }

    public function test_bills_of_another_customer_are_refused(): void
    {
        $this->seed();
        DocumentType::firstOrCreate(['code' => 'RECEIPT'], ['name_th' => 'ใบเสร็จรับเงิน']);
        [$customer, $first] = $this->twoCreditBills();
        $other = Customer::create(['code' => 'OTHER-AR', 'name_th' => 'ลูกค้าอื่น', 'is_active' => true]);

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->from(route('ar-receipts.create'))
            ->post(route('ar-receipts.store'), [
                'customer_id' => $other->id, 'branch_id' => $first->document->branch_id, 'method' => 'cash',
                'items' => [['customer_open_item_id' => $first->id, 'amount' => 10]],
            ])->assertSessionHas('error');
        $this->assertSame(0, PaymentLine::count());
    }

    /** @return array{Customer, CustomerOpenItem, CustomerOpenItem} */
    private function twoCreditBills(): array
    {
        $branch = \App\Models\Branch::firstOrCreate(['code' => 'ARB'], ['name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $customer = Customer::create(['code' => 'AR-T1', 'name_th' => 'ร้านทดสอบรับชำระ', 'branch_id' => $branch->id, 'is_active' => true]);
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ใบขายเชื่อ', 'stock_effect' => 'out']);
        $items = [];
        foreach ([['DSN-T-001', 300], ['DSN-T-002', 120]] as [$no, $amount]) {
            $doc = Document::create([
                'document_type_id' => $type->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'doc_number' => $no, 'doc_date' => now()->toDateString(), 'status' => 'active',
                'total_items' => 1, 'total_amount' => $amount,
            ]);
            $items[] = CustomerOpenItem::create([
                'customer_id' => $customer->id, 'document_id' => $doc->id,
                'gross_amount' => $amount, 'net_amount' => $amount, 'balance_amount' => $amount,
                'due_date' => now()->addDays(30)->toDateString(), 'status' => CustomerOpenItem::STATUS_OPEN,
            ]);
        }

        return [$customer, $items[0]->load('document'), $items[1]->load('document')];
    }
}
