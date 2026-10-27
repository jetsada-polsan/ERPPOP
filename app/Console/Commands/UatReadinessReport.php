<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Document;
use App\Models\PosReceipt;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UatReadinessReport extends Command
{
    protected $signature = 'erp:uat-readiness {--dry-run : รายงานอย่างเดียว (คำสั่งนี้ไม่แก้ข้อมูลเสมอ)}';

    protected $description = 'Read-only checks for the UAT setup, master-data and suspect-document findings';

    public function handle(): int
    {
        $this->info('READ ONLY: ไม่แก้หรือลบข้อมูล และไม่เชื่อมต่อ MSSQL legacy');
        $this->table(['ผู้ใช้ที่ยังไม่มีสาขาประจำ', 'ชื่อ'], User::where('is_active', true)->whereNull('branch_id')
            ->orderBy('username')->get(['username', 'name'])->toArray());
        $this->line('บัญชีธนาคารบริษัท: '.BankAccount::count());
        $this->line('สินค้าเปิดใช้งาน: '.Product::where('is_active', true)->count());
        $this->line('สินค้าที่ราคาขายทั่วไปเป็น 0: '.Product::where('is_active', true)->where('default_price', '<=', 0)->count());
        $this->table(['ตารางราคา', 'ชื่อ', 'รายการราคาเฉพาะ'], PriceTable::withCount('productPrices')->get()
            ->map(fn ($table) => [$table->code, $table->name, $table->product_prices_count])->toArray());

        // Missing optional contact/tax details are review flags, not permission
        // to invent values or deactivate the customer.
        $this->line('ลูกค้าที่ต้องตรวจข้อมูล (แสดงสูงสุด 100 ราย; ไม่มีที่อยู่/ผู้ติดต่อ/เลขภาษีไม่ได้แปลว่าเป็นข้อมูลขยะ):');
        $customers = Customer::withCount(['addresses', 'contacts'])
            ->where(fn ($query) => $query->whereNull('name_th')->orWhere('name_th', '')
                ->orWhereNull('tax_id')->orWhere('tax_id', '')
                ->orWhereDoesntHave('addresses')->orWhereDoesntHave('contacts'));
        $this->line('จำนวนตามเกณฑ์รายงานนี้: '.(clone $customers)->count());
        $this->table(['รหัส', 'ชื่อ', 'ข้อมูลที่ควรตรวจ'], $customers->orderBy('code')->limit(100)->get()->map(fn ($customer) => [
            $customer->code, $customer->name_th,
            implode(', ', array_filter([
                trim((string) $customer->name_th) === '' ? 'ชื่อ' : null,
                trim((string) $customer->tax_id) === '' ? 'เลขภาษี' : null,
                $customer->addresses_count === 0 ? 'ที่อยู่' : null,
                $customer->contacts_count === 0 ? 'ผู้ติดต่อ' : null,
            ])),
        ])->toArray());
        $this->table(['ผู้จำหน่ายที่เจ้าของระบุ', 'ชื่อ', 'ใช้งาน'], Supplier::whereIn('code', ['9', 'A00003'])
            ->get(['code', 'name_th', 'is_active'])->toArray());

        $this->warn('เอกสารต่อไปนี้เป็นเพียงรายการรอตรวจ ไม่ยืนยันว่าเป็นข้อมูลทดสอบ และห้ามลบตามยอดเงินเพียงอย่างเดียว');
        $this->table(['ชนิด', 'เลขที่', 'วันที่', 'ยอด', 'สถานะ'], Document::with('documentType')
            ->where(fn ($query) => $query->whereLike('doc_number', '%TEST%')->orWhereLike('reference', '%TEST%')
                ->orWhereLike('remark', '%TEST%')->orWhereBetween('total_amount', [150, 200]))
            ->orderByDesc('id')->limit(100)->get()->map(fn ($doc) => [
                $doc->documentType?->code, $doc->doc_number, $doc->doc_date?->toDateString(), $doc->total_amount, $doc->status,
            ])->toArray());
        $this->table(['ใบเสนอราคา', 'วันที่', 'สถานะ'], Quotation::where(fn ($query) => $query
            ->whereLike('doc_number', '%TEST%')->orWhereLike('note', '%TEST%')->orWhereLike('customer_name', '%TEST%'))
            ->limit(100)->get()->map(fn ($doc) => [$doc->doc_number, $doc->doc_date?->toDateString(), $doc->status])->toArray());
        $this->table(['ใบสั่งซื้อที่ระบุ', 'วันที่เอกสาร', 'สถานะ'], PurchaseOrder::where('doc_number', 'PO00120261026001')
            ->get()->map(fn ($doc) => [$doc->doc_number, $doc->doc_date?->toDateString(), $doc->status])->toArray());
        $order = PurchaseOrder::with('receipts.document')->where('doc_number', 'PO00120261026001')->first();
        if ($order) {
            $this->table(['ใบซื้อที่รับจาก PO', 'วันที่ใบซื้อ', 'เวลารับจริง'], $order->receipts->map(fn ($receipt) => [
                $receipt->document?->doc_number, $receipt->document?->doc_date?->toDateString(), $receipt->received_at?->toDateTimeString(),
            ])->toArray());
        }
        $this->table(['ใบตรวจนับที่ระบุ', 'สถานะ'], StockCount::whereIn('doc_number', ['SC000720260926001', 'SCB00720260926001'])->get(['doc_number', 'status'])->toArray());
        $this->table(['ใบผลิตที่ระบุ', 'สถานะ'], ProductionOrder::whereLike('doc_no', 'PD261026%')->limit(100)->get(['doc_no', 'status'])->toArray());
        $this->line('POS ที่ทำเครื่องหมาย legacy import: '.PosReceipt::where('is_legacy_import', true)->count());
        $this->line('ยอดขาย 7 วัน (ทุกสาขา): '.DB::table('sales_postings')->whereBetween('sale_date', [now()->subDays(6)->toDateString(), now()->toDateString()])->sum('net_sales'));
        $this->line('ยอดขายวันนี้ (ทุกสาขา): '.DB::table('sales_postings')->where('sale_date', now()->toDateString())->sum('net_sales'));
        $this->line('เทียบหน้าแดชบอร์ด/ผู้บริหารด้วยวันและสาขาเดียวกัน; จำนวน legacy 0 ไม่ใช่คำสั่งให้นำเข้าประวัติ (ดู PROJECT_MEMORY)');

        return self::SUCCESS;
    }
}
