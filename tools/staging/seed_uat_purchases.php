<?php

/**
 * ซื้อสินค้า UAT เข้าคลังจริงผ่าน PurchaseService
 *
 * ต่างจากการยัดตัวเลขลง stock_balances ตรง ๆ ตรงที่ได้ stock_lots พร้อมต้นทุนจริง
 * ทำให้การขายมี COGS ให้คิด และการกระทบยอดต้นทุนมีความหมาย
 * ไม่งั้นเกณฑ์ "ต้นทุนขาย GL = ต้นทุนบนบรรทัด" จะผ่านเพราะทั้งสองข้างเป็นศูนย์
 *
 * นี่คือขาแรกของ UAT เส้นเต็ม: PO -> รับเข้า -> ต้นทุน -> ขาย -> ...
 */

if (! in_array(app()->environment(), ['staging', 'local', 'testing'], true) || DB::connection()->getDatabaseName() === 'jeterp') {
    echo "หยุด: รันได้เฉพาะ staging เท่านั้น\n";

    return;
}

$branchId = DB::table('branches')->where('code', 'UAT')->value('id');
$supplierId = DB::table('suppliers')->where('code', 'UAT-SUP-001')->value('id');
$products = DB::table('products')->where('sku_code', 'like', 'UAT-%')->orderBy('id')->get(['id', 'sku_code']);

$service = app(App\Services\Purchasing\PurchaseService::class);
$documents = 0;
$totalValue = 0.0;

// ซื้อทีละ 10 รายการต่อใบ ให้ได้หลายใบเหมือนงานจริง
foreach ($products->chunk(10) as $chunk) {
    $items = [];
    foreach ($chunk as $index => $product) {
        $items[] = [
            'product_id' => $product->id,
            'qty' => 500,
            'unit_price' => 30 + ($product->id % 20),   // ต้นทุนต่างกันจริงเพื่อให้ FIFO มีความหมาย
        ];
    }
    $document = $service->create([
        'branch_id' => $branchId,
        'supplier_id' => $supplierId,
        'is_credit' => true,
        'prices_include_vat' => false,
        'items' => $items,
    ]);
    $documents++;
    $totalValue += (float) $document->total_amount;
}

printf("ใบซื้อ %d ใบ มูลค่ารวม %s\n", $documents, number_format($totalValue, 2));
printf("stock_lots %d · stock_balances รวม %s ชิ้น\n",
    DB::table('stock_lots')->count(),
    number_format((float) DB::table('stock_balances')->sum('on_hand_qty'), 0));
printf("เจ้าหนี้ค้าง %d ใบ รวม %s\n",
    DB::table('supplier_open_items')->count(),
    number_format((float) DB::table('supplier_open_items')->sum('balance_amount'), 2));
