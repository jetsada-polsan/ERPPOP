<?php

/**
 * เติมข้อมูล UAT ให้ครบทั้ง 6 รายงานที่ยังเทียบยอดไม่ได้
 *
 *   ใบจอง   -> ใบจองแบบจัดส่ง ยืนยันเป็นใบขายแล้ว มีทั้งเกินกำหนด ส่งบางส่วน และส่งครบ
 *   ธนาคาร  -> statement ทั้งที่จับคู่ได้และจับคู่ไม่ได้ พร้อมค่าใช้จ่ายจ่ายผ่านธนาคาร
 *   รับชำระ -> เงินโอน/QR ที่เข้ามาแล้วแต่ยังไม่ถูกกระทบยอด = ยอดรอพิสูจน์
 *
 * ตั้งใจให้มีทั้งเคสที่ "ตรง" และ "ไม่ตรง" เพราะรายงานที่เห็นแต่เคสสวยพิสูจน์อะไรไม่ได้
 */

if (! in_array(app()->environment(), ['staging', 'local', 'testing'], true) || DB::connection()->getDatabaseName() === 'jeterp') {
    echo "หยุด: รันได้เฉพาะ staging เท่านั้น\n";

    return;
}

$branchId = DB::table('branches')->where('code', 'UAT')->value('id');
$bankAccountId = DB::table('bank_accounts')->where('account_no', 'UAT-000-1')->value('id');
$products = DB::table('products')->where('sku_code', 'like', 'UAT-%')->orderBy('id')->pluck('id')->all();
$customers = DB::table('customers')->where('code', 'like', 'UAT-%')->orderBy('id')->pluck('id')->all();
$userId = DB::table('users')->value('id');
auth()->loginUsingId($userId);

/* ---------------- 1. ใบจองแบบจัดส่ง ---------------- */
$bookingService = app(App\Services\Sales\BookingService::class);
$creditSaleService = app(App\Services\Sales\CreditSaleService::class);
$deliveryService = app(App\Services\Sales\BookingDeliveryService::class);

$plan = [
    ['due' => now()->subDays(9),  'after' => null,       'label' => 'เกินกำหนด 9 วัน'],
    ['due' => now()->subDays(3),  'after' => 'partial',  'label' => 'เกินกำหนด 3 วัน ส่งบางส่วน'],
    ['due' => now()->addDays(2),  'after' => null,       'label' => 'ยังไม่ถึงกำหนด'],
    ['due' => now()->subDays(1),  'after' => 'delivered', 'label' => 'ส่งครบแล้ว'],
];
$bookings = 0;
foreach ($plan as $index => $step) {
    $document = $bookingService->create([
        'branch_id' => $branchId,
        'customer_id' => $customers[$index % count($customers)],
        'fulfillment_type' => 'delivery',
        'delivery_due_at' => $step['due']->format('Y-m-d H:i:s'),
        'items' => [['product_id' => $products[$index], 'qty' => 5, 'unit_price' => 150]],
    ]);
    $booking = App\Models\SaleBooking::where('document_id', $document->id)->sole();
    $creditSaleService->convertBookingToCreditSale($booking);
    if ($step['after']) {
        $deliveryService->record($booking->fresh(), $step['after']);
    }
    $bookings++;
}

/* ---------------- 2. เงินโอน/QR เข้า POS ---------------- */
$terminalId = DB::table('pos_terminals')->where('branch_id', $branchId)->value('id')
    ?: DB::table('pos_terminals')->insertGetId(['branch_id' => $branchId, 'code' => 'UAT-POS', 'name' => 'POS ทดสอบ UAT']);

$transfers = [];
foreach ([[2400.00, 'transfer'], [1750.00, 'qr'], [980.00, 'qr'], [3120.00, 'transfer']] as $index => [$amount, $method]) {
    $receiptId = DB::table('pos_receipts')->insertGetId([
        'pos_terminal_id' => $terminalId,
        'receipt_no' => 'UATQR'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
        'receipt_date' => now()->subDays(2)->setTime(11, $index * 5),
        'gross_sales' => $amount, 'net_sales' => $amount,
        'status' => 'completed', 'is_legacy_import' => false,
    ]);
    $paymentId = DB::table('pos_payments')->insertGetId([
        'pos_receipt_id' => $receiptId, 'method' => $method, 'amount' => $amount,
        'payment_reference' => 'REF-'.($index + 1),
    ]);
    $transfers[] = ['amount' => $amount, 'payment_id' => $paymentId];
}

/* ---------------- 3. statement ธนาคาร ---------------- */
$statementDate = now()->subDays(2)->toDateString();
$statements = 0;
// สองบรรทัดแรกตรงกับเงินโอน POS -> auto-reconcile ต้องจับคู่ได้
foreach (array_slice($transfers, 0, 2) as $transfer) {
    DB::table('bank_statements')->insert([
        'bank_account_id' => $bankAccountId, 'statement_date' => $statementDate,
        'description' => 'เงินโอนเข้า', 'amount' => $transfer['amount'], 'balance' => 0, 'reconciled' => false,
    ]);
    $statements++;
}
// บรรทัดที่จับคู่ไม่ได้ -> ต้องค้างไว้ให้คนตรวจ ไม่ใช่ถูกปิดทิ้ง
foreach ([[5500.00, 'เงินโอนเข้าไม่ทราบที่มา'], [-1200.00, 'ค่าธรรมเนียมธนาคาร']] as [$amount, $description]) {
    DB::table('bank_statements')->insert([
        'bank_account_id' => $bankAccountId, 'statement_date' => $statementDate,
        'description' => $description, 'amount' => $amount, 'balance' => 0, 'reconciled' => false,
    ]);
    $statements++;
}

printf("ใบจองแบบจัดส่ง %d ใบ (เกินกำหนด/ส่งบางส่วน/ยังไม่ถึง/ส่งครบ)\n", $bookings);
printf("เงินโอน-QR เข้า POS %d รายการ รวม %s\n", count($transfers), number_format(array_sum(array_column($transfers, 'amount')), 2));
printf("statement ธนาคาร %d บรรทัด (จับคู่ได้ 2 · จับคู่ไม่ได้ 2)\n", $statements);
printf("ลูกหนี้เปิดจากใบขายเชื่อ %d ใบ รวม %s\n",
    DB::table('customer_open_items')->count(),
    number_format((float) DB::table('customer_open_items')->sum('balance_amount'), 2));
