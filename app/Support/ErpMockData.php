<?php

namespace App\Support;

/**
 * ข้อมูลจำลองสำหรับหน้า mockup ระบบหลังบ้าน
 *
 * แยกออกมาไฟล์เดียวเพื่อให้เห็นชัดว่าอะไรคือของปลอม และเวลาต่อ API จริง
 * จะได้รู้ว่าต้องแทนที่อะไรบ้าง ไม่ใช่ไปไล่หาตัวเลขที่ฝังอยู่ในหน้าจอ
 */
class ErpMockData
{
    /** @return array<string, mixed> */
    public static function kpiSummary(): array
    {
        return [
            ['label' => 'ยอดขายวันนี้', 'value' => '฿128,540.00', 'sub' => 'เมื่อวาน ฿118,570.00', 'delta' => '+8.42%', 'tone' => 'up', 'icon' => 'bi-shop'],
            ['label' => 'กำไรขั้นต้น', 'value' => '24.8%', 'sub' => 'เฉลี่ย 7 วัน', 'delta' => '+2.1%', 'tone' => 'up', 'icon' => 'bi-graph-up-arrow'],
            ['label' => 'บิลรอ Sync', 'value' => '7', 'sub' => 'บิล', 'delta' => null, 'tone' => 'warn', 'icon' => 'bi-arrow-repeat'],
            ['label' => 'สินค้าใกล้หมด', 'value' => '32', 'sub' => 'รายการ', 'delta' => null, 'tone' => 'warn', 'icon' => 'bi-box-seam'],
            ['label' => 'ใบสั่งซื้อรอรับ', 'value' => '12', 'sub' => 'รายการ', 'delta' => null, 'tone' => 'info', 'icon' => 'bi-clipboard-check'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function branches(): array
    {
        return [
            ['code' => 'B001', 'name' => 'สาขา 001 รามอินทรา', 'sales' => 62540],
            ['code' => 'B002', 'name' => 'สาขา 002 ลาดพร้าว', 'sales' => 38900],
            ['code' => 'B003', 'name' => 'สาขา 003 บางแค', 'sales' => 17850],
            ['code' => 'B004', 'name' => 'สาขา 004 พระราม 2', 'sales' => 9250],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function topProducts(): array
    {
        return [
            ['name' => 'หมูสามชั้นสไลด์', 'qty' => '85.40 kg', 'amount' => '฿16,146'],
            ['name' => 'ปลาแซลมอนชั่งน้ำหนัก', 'qty' => '42.20 kg', 'amount' => '฿16,025'],
            ['name' => 'เนื้อริบอายแพ็ค', 'qty' => '18.70 kg', 'amount' => '฿8,583'],
            ['name' => 'ซอสหมักเกาหลี', 'qty' => '96.00 ขวด', 'amount' => '฿7,584'],
            ['name' => 'เบคอนรมควัน', 'qty' => '15.30 kg', 'amount' => '฿6,885'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function products(): array
    {
        return [
            ['barcode' => '8850000012345', 'code' => 'PORK-BELLY', 'name' => 'หมูสามชั้นสไลด์', 'category' => 'เนื้อสัตว์', 'unit' => 'kg', 'price' => '189.00', 'cost' => '142.00', 'stock' => '85.40', 'status' => 'active'],
            ['barcode' => '801037', 'code' => 'SALMON-WEIGHT', 'name' => 'ปลาแซลมอนชั่งน้ำหนัก', 'category' => 'อาหารทะเล', 'unit' => 'kg', 'price' => '399.00', 'cost' => '312.00', 'stock' => '42.20', 'status' => 'active'],
            ['barcode' => '105147', 'code' => 'RIBEYE-PACK', 'name' => 'เนื้อริบอายแพ็ค', 'category' => 'เนื้อสัตว์', 'unit' => 'kg', 'price' => '459.00', 'cost' => '368.00', 'stock' => '3.10', 'status' => 'low'],
            ['barcode' => '8850000098711', 'code' => 'SAUCE-KR', 'name' => 'ซอสหมักเกาหลี', 'category' => 'เครื่องปรุง', 'unit' => 'ขวด', 'price' => '79.00', 'cost' => '52.00', 'stock' => '210.00', 'status' => 'active'],
            ['barcode' => '2990000004521', 'code' => 'BACON-SMOKE', 'name' => 'เบคอนรมควัน', 'category' => 'เนื้อสัตว์', 'unit' => 'kg', 'price' => '450.00', 'cost' => '355.00', 'stock' => '0.00', 'status' => 'out'],
            ['barcode' => 'ICE-01', 'code' => 'ICE-TUBE', 'name' => 'น้ำแข็งหลอด', 'category' => 'อื่น ๆ', 'unit' => 'ถุง', 'price' => '25.00', 'cost' => '14.00', 'stock' => '88.00', 'status' => 'active'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function posOrders(): array
    {
        return [
            ['receipt' => '001-POS01-20260824-0007', 'branch' => 'สาขา 001', 'terminal' => 'POS01', 'cashier' => 'สมชาย', 'time' => '11:45', 'method' => 'เงินสด', 'total' => '฿529.00', 'sync' => 'synced'],
            ['receipt' => '001-POS01-20260824-0006', 'branch' => 'สาขา 001', 'terminal' => 'POS01', 'cashier' => 'สมชาย', 'time' => '11:32', 'method' => 'เงินสด', 'total' => '฿189.00', 'sync' => 'pending'],
            ['receipt' => '002-POS02-20260824-0004', 'branch' => 'สาขา 002', 'terminal' => 'POS02', 'cashier' => 'มาลี', 'time' => '11:28', 'method' => 'เงินโอน', 'total' => '฿982.00', 'sync' => 'synced'],
            ['receipt' => '003-POS01-20260824-0003', 'branch' => 'สาขา 003', 'terminal' => 'POS01', 'cashier' => 'แอน', 'time' => '11:15', 'method' => 'เงินสด', 'total' => '฿75.00', 'sync' => 'failed'],
            ['receipt' => '001-POS01-20260824-0005', 'branch' => 'สาขา 001', 'terminal' => 'POS01', 'cashier' => 'สมชาย', 'time' => '11:10', 'method' => 'บัตรเครดิต', 'total' => '฿1,250.00', 'sync' => 'synced'],
            ['receipt' => '004-POS01-20260824-0002', 'branch' => 'สาขา 004', 'terminal' => 'POS01', 'cashier' => 'ต้อม', 'time' => '10:52', 'method' => 'เงินสด', 'total' => '฿318.00', 'sync' => 'voided'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function inventoryCards(): array
    {
        return [
            ['title' => 'คลังสาขา 001', 'sub' => 'รามอินทรา', 'onhand' => '4,182', 'low' => 12, 'pending' => 3, 'action' => 'ดูสต๊อก', 'tone' => 'primary'],
            ['title' => 'คลังสาขา 002', 'sub' => 'ลาดพร้าว', 'onhand' => '3,904', 'low' => 8, 'pending' => 1, 'action' => 'ดูสต๊อก', 'tone' => 'primary'],
            ['title' => 'ระหว่างขนส่ง', 'sub' => 'โอนย้ายระหว่างสาขา', 'onhand' => '412', 'low' => 0, 'pending' => 5, 'action' => 'ติดตามการโอน', 'tone' => 'info'],
            ['title' => 'รอรับสินค้า', 'sub' => 'จากใบสั่งซื้อ', 'onhand' => '1,120', 'low' => 0, 'pending' => 12, 'action' => 'ตรวจรับ', 'tone' => 'info'],
            ['title' => 'สต๊อกติดลบ', 'sub' => 'ต้องแก้ก่อนปิดงวด', 'onhand' => '-38', 'low' => 6, 'pending' => 6, 'action' => 'แก้ไขสต๊อก', 'tone' => 'danger'],
            ['title' => 'รอบนับสต๊อก', 'sub' => 'ประจำเดือนนี้', 'onhand' => '2 รอบ', 'low' => 0, 'pending' => 2, 'action' => 'เปิดรอบนับ', 'tone' => 'warn'],
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public static function purchaseOrders(): array
    {
        return [
            'RFQ' => [
                ['no' => 'RFQ-20260824-0009', 'supplier' => 'ไทยฟูดส์ กรุ๊ป', 'amount' => '฿82,400.00', 'due' => '28 ส.ค.'],
            ],
            'PO Confirmed' => [
                ['no' => 'PO-20260824-0003', 'supplier' => 'สยามแมคโคร', 'amount' => '฿119,000.00', 'due' => '26 ส.ค.'],
                ['no' => 'PO-20260823-0011', 'supplier' => 'ซีพี เฟรชมาร์ท', 'amount' => '฿64,800.00', 'due' => '27 ส.ค.'],
            ],
            'Waiting Receipt' => [
                ['no' => 'PO-20260824-0001', 'supplier' => 'ABC Food', 'amount' => '฿45,200.00', 'due' => '25 ส.ค.'],
                ['no' => 'PO-20260822-0018', 'supplier' => 'เบทาโกร', 'amount' => '฿38,150.00', 'due' => '25 ส.ค.'],
            ],
            'Partially Received' => [
                ['no' => 'PO-20260821-0007', 'supplier' => 'แหลมทองสหการ', 'amount' => '฿27,600.00', 'due' => '24 ส.ค.'],
            ],
            'Done' => [
                ['no' => 'PO-20260820-0002', 'supplier' => 'ไทยยูเนี่ยน', 'amount' => '฿91,300.00', 'due' => 'รับครบแล้ว'],
            ],
            'Cancelled' => [
                ['no' => 'PO-20260819-0014', 'supplier' => 'ผู้ขายรายย่อย', 'amount' => '฿5,900.00', 'due' => 'ยกเลิกโดยผู้จัดการ'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function stockWarnings(): array
    {
        return [
            ['tone' => 'warn', 'title' => 'สต๊อกต่ำกว่าเกณฑ์ 32 รายการ', 'sub' => 'คลิกเพื่อดูรายการ', 'time' => '5 นาทีที่แล้ว'],
            ['tone' => 'info', 'title' => 'บิลรอซิงค์ 7 บิล', 'sub' => 'คลิกเพื่อตรวจสอบ', 'time' => '10 นาทีที่แล้ว'],
            ['tone' => 'danger', 'title' => 'เนื้อริบอายแพ็ค สต๊อกติดลบ', 'sub' => 'เหลือ -1.20 kg', 'time' => '15 นาทีที่แล้ว'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function activities(): array
    {
        return [
            ['who' => 'JJ', 'what' => 'แก้ไขราคาขาย หมูสามชั้นสไลด์', 'detail' => 'จาก 185.00 เป็น 189.00 บาท', 'time' => '1 ชั่วโมงที่แล้ว'],
            ['who' => 'ระบบ', 'what' => 'ซิงค์สินค้าไปยัง POS01', 'detail' => '6 รายการ', 'time' => '2 ชั่วโมงที่แล้ว'],
            ['who' => 'มาลี', 'what' => 'ตรวจรับสินค้า PO-20260824-0012', 'detail' => '80 รายการ ฿45,200.00', 'time' => '3 ชั่วโมงที่แล้ว'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function apps(): array
    {
        return [
            ['key' => 'dashboard', 'th' => 'แผงควบคุม', 'en' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'erp-mockup.dashboard'],
            ['key' => 'pos', 'th' => 'บิลขายหน้าร้าน', 'en' => 'POS Sales', 'icon' => 'bi-shop', 'route' => 'erp-mockup.pos-orders'],
            ['key' => 'inventory', 'th' => 'คลังสินค้า', 'en' => 'Inventory', 'icon' => 'bi-boxes', 'route' => 'erp-mockup.inventory'],
            ['key' => 'purchase', 'th' => 'จัดซื้อ', 'en' => 'Purchase', 'icon' => 'bi-cart3', 'route' => 'erp-mockup.purchase'],
            ['key' => 'products', 'th' => 'สินค้า', 'en' => 'Products', 'icon' => 'bi-box-seam', 'route' => 'erp-mockup.products'],
            ['key' => 'sales', 'th' => 'คำสั่งขาย', 'en' => 'Sales Order', 'icon' => 'bi-receipt', 'route' => null],
            ['key' => 'delivery', 'th' => 'จัดส่ง / TMS', 'en' => 'Delivery', 'icon' => 'bi-truck', 'route' => null],
            ['key' => 'stock-count', 'th' => 'นับสต๊อก', 'en' => 'Stock Count', 'icon' => 'bi-clipboard-data', 'route' => null],
            ['key' => 'accounting', 'th' => 'สะพานบัญชี', 'en' => 'Accounting Bridge', 'icon' => 'bi-journal-text', 'route' => null],
            ['key' => 'customers', 'th' => 'ลูกค้า', 'en' => 'Customers', 'icon' => 'bi-people', 'route' => null],
            ['key' => 'suppliers', 'th' => 'ผู้ขาย', 'en' => 'Suppliers', 'icon' => 'bi-truck-front', 'route' => null],
            ['key' => 'employees', 'th' => 'พนักงาน', 'en' => 'Employees', 'icon' => 'bi-person-badge', 'route' => null],
            ['key' => 'settings', 'th' => 'ตั้งค่า', 'en' => 'Settings', 'icon' => 'bi-gear', 'route' => null],
        ];
    }
}
