<?php

/**
 * ข้อมูลทดสอบสำหรับ UAT — แยกขาดจากข้อมูลจริง
 *
 * ทุกอย่างใช้รหัสขึ้นต้นด้วย UAT- เพื่อให้กวาดออกได้หมดและมองออกทันทีว่าไม่ใช่ของจริง
 * มีด่านกันสองชั้น: environment ต้องไม่ใช่ production และชื่อฐานต้องไม่ใช่ jeterp
 */

if (! in_array(app()->environment(), ['staging', 'local', 'testing'], true)) {
    echo 'หยุด: รันได้เฉพาะ staging/local (ตอนนี้เป็น '.app()->environment().")\n";

    return;
}
if (DB::connection()->getDatabaseName() === 'jeterp') {
    echo "หยุด: นี่คือฐาน production\n";

    return;
}

DB::transaction(function () {
    foreach ([
        'CASH_SALE' => 'ใบขายสด', 'CREDIT_SALE' => 'ใบขายเชื่อ', 'BOOKING' => 'ใบจอง',
        'PURCHASE' => 'ใบซื้อเชื่อ', 'SALE_RETURN' => 'ใบรับคืนสินค้า',
        'STOCK_ADJUSTMENT' => 'ใบปรับปรุงสต็อก', 'STOCK_DAMAGE' => 'ใบตัดชำรุด',
        'PAYMENT_VOUCHER' => 'ใบสำคัญจ่าย', 'RECEIPT' => 'ใบเสร็จรับเงิน',
        'CASH_DEPOSIT' => 'ใบฝากเงินสด', 'CASH_WITHDRAWAL' => 'ใบถอนเงินสด',
    ] as $code => $name) {
        DB::table('document_types')->insertOrIgnore(['code' => $code, 'name_th' => $name]);
    }

    $branchId = DB::table('branches')->where('code', 'UAT')->value('id')
        ?: DB::table('branches')->insertGetId(['code' => 'UAT', 'name_th' => 'สาขาทดสอบ UAT', 'is_active' => true]);
    $warehouseId = DB::table('warehouses')->where('code', 'UAT-WH')->value('id')
        ?: DB::table('warehouses')->insertGetId(['branch_id' => $branchId, 'code' => 'UAT-WH', 'name' => 'คลังทดสอบ UAT']);
    $locationId = DB::table('warehouse_locations')->where('code', 'UAT-MAIN')->value('id')
        ?: DB::table('warehouse_locations')->insertGetId(['warehouse_id' => $warehouseId, 'code' => 'UAT-MAIN', 'name' => 'พื้นที่หลัก']);
    DB::table('branches')->where('id', $branchId)->update(['default_warehouse_location_id' => $locationId]);

    $unitId = DB::table('product_units')->where('code', 'UAT-EA')->value('id')
        ?: DB::table('product_units')->insertGetId(['code' => 'UAT-EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
    $categoryId = DB::table('product_categories')->where('code', 'UAT-CAT')->value('id')
        ?: DB::table('product_categories')->insertGetId(['code' => 'UAT-CAT', 'name_th' => 'หมวดทดสอบ UAT']);

    $products = 0;
    for ($i = 1; $i <= 50; $i++) {
        $sku = sprintf('UAT-SKU-%03d', $i);
        if (DB::table('products')->where('sku_code', $sku)->exists()) {
            continue;
        }
        $productId = DB::table('products')->insertGetId([
            'sku_code' => $sku, 'name_th' => 'สินค้าทดสอบ UAT '.$i,
            'product_category_id' => $categoryId, 'base_unit_id' => $unitId,
            'default_price' => 50 + $i, 'average_cost' => 30 + $i,
            'is_vat' => $i % 2 === 0, 'is_active' => true,
            'negative_stock_policy' => 'allow',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // ต้องมี lot ไม่ใช่แค่ยอดคงเหลือ และจำนวนต้องตรงกับ stock_balances พอดี
        // ส่วนที่ยอดคงเหลือเกินกว่า lot ระบบจะเติม lot "OPENING" ต้นทุนศูนย์ให้เอง
        // ลงวันที่ 1900-01-01 ซึ่งเก่าที่สุดจึงถูก FIFO ตัดก่อน แล้วต้นทุนขายจะกลายเป็นศูนย์ทั้งหมด
        DB::table('stock_lots')->insert([
            'product_id' => $productId, 'warehouse_location_id' => $locationId,
            'lot_number' => 'UAT-LOT-'.$i, 'received_date' => now()->toDateString(),
            'initial_qty' => 10000, 'remaining_qty' => 10000, 'unit_cost' => 30 + $i,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'product_id' => $productId, 'warehouse_location_id' => $locationId,
            'on_hand_qty' => 10000, 'reserved_qty' => 0,
        ]);
        $products++;
    }

    $customers = 0;
    for ($i = 1; $i <= 20; $i++) {
        $code = sprintf('UAT-CUS-%03d', $i);
        if (DB::table('customers')->where('code', $code)->exists()) {
            continue;
        }
        DB::table('customers')->insert([
            'code' => $code, 'name_th' => 'ลูกค้าทดสอบ UAT '.$i,
            'branch_id' => $branchId, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customers++;
    }

    for ($i = 1; $i <= 5; $i++) {
        $code = sprintf('UAT-SUP-%03d', $i);
        if (! DB::table('suppliers')->where('code', $code)->exists()) {
            DB::table('suppliers')->insert(['code' => $code, 'name_th' => 'ผู้จำหน่ายทดสอบ UAT '.$i, 'is_active' => true]);
        }
    }

    if (! DB::table('bank_accounts')->where('account_no', 'UAT-000-1')->exists()) {
        DB::table('bank_accounts')->insert([
            'branch_id' => $branchId, 'bank_name' => 'ธนาคารทดสอบ UAT',
            'account_no' => 'UAT-000-1', 'account_name' => 'บัญชีทดสอบ UAT',
        ]);
    }

    printf("branch_id=%d location_id=%d สินค้าใหม่=%d ลูกค้าใหม่=%d\n", $branchId, $locationId, $products, $customers);
});

printf("ในฐาน: สินค้า UAT %d · ลูกค้า UAT %d · สต๊อก %d แถว\n",
    DB::table('products')->where('sku_code', 'like', 'UAT-%')->count(),
    DB::table('customers')->where('code', 'like', 'UAT-%')->count(),
    DB::table('stock_balances')->count());
