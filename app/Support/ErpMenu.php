<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;

/**
 * เมนูหลักของ ERP — แหล่งเดียวที่นิยามว่าระบบมีเมนูอะไรบ้าง
 *
 * ย้ายออกมาจาก layout.blade.php เพื่อให้หน้าอื่น (เช่น App Launcher)
 * ใช้รายการเดียวกันได้ ไม่ต้องคัดลอกไปเขียนซ้ำแล้วหลุดจากกันภายหลัง
 * การกรองสิทธิ์ใช้ RoutePermissions ชุดเดียวกับ middleware ErpAuthorize
 * จึงไม่มีเมนูไหนโผล่ให้คนที่กดเข้าไปแล้วเจอ 403
 */
class ErpMenu
{
    /** ไอคอนประจำหมวด ใช้ทั้งบนแถบบนและในหน้า App Launcher */
    public const SECTION_ICONS = [
        'ภาพรวม' => 'bi-house-door-fill',
        'งานประจำวัน' => 'bi-cash-coin',
        'POS / หน้าร้าน' => 'bi-shop-window',
        'คลัง / ผลิต / ซื้อ' => 'bi-box-seam-fill',
        'การเงิน / บัญชี' => 'bi-calculator-fill',
        'รายงาน' => 'bi-clipboard-data-fill',
        'ระบบ' => 'bi-gear-fill',
    ];

    /** @return array<int, array<string, mixed>> รายการดิบ ยังไม่กรองสิทธิ์ */
    public static function all(): array
    {
        return [
            [
                'label' => 'ภาพรวม',
                'displayLabel' => 'หน้าหลัก',
                'items' => [
                    ['label' => 'ภาพรวมกิจการ', 'route' => 'dashboard', 'pattern' => 'dashboard', 'icon' => 'bi-bar-chart-line-fill', 'tone' => 'blue'],
                    ['label' => 'รวมเมนูการทำงาน', 'route' => 'features.index', 'pattern' => 'features.*', 'icon' => 'bi-grid-1x2-fill', 'tone' => 'red'],
                    ['label' => 'คู่มือ JET ERP', 'route' => 'core-modules.index', 'pattern' => 'core-modules.*', 'icon' => 'bi-journal-text', 'tone' => 'amber'],
                    ['label' => 'ศูนย์ควบคุมบริหาร', 'route' => 'management-controls.index', 'pattern' => 'management-controls.*', 'icon' => 'bi-speedometer2', 'tone' => 'teal'],
                ],
            ],
            [
                'label' => 'งานประจำวัน',
                'displayLabel' => 'ขาย/เอกสาร',
                'items' => [
                    ['label' => 'เอกสารย้อนหลัง', 'route' => 'documents.browser', 'pattern' => 'documents.browser', 'icon' => 'bi-archive-fill', 'tone' => 'indigo'],
                    ['label' => 'ใบเสนอราคา', 'route' => 'quotations.index', 'pattern' => 'quotations.*', 'icon' => 'bi-file-earmark-text', 'tone' => 'slate'],
                    ['label' => 'ใบจอง / ขายเชื่อ', 'route' => 'bookings.index', 'pattern' => 'bookings.*', 'extraPattern' => 'sales.*', 'icon' => 'bi-receipt-cutoff', 'tone' => 'orange'],
                    ['label' => 'ใบรวมจอง', 'route' => 'bookings.summary', 'pattern' => 'bookings.summary', 'icon' => 'bi-clipboard2-check', 'tone' => 'teal'],
                    ['label' => 'เทียบใบจองกับใบขาย', 'route' => 'bookings.comparison', 'pattern' => 'bookings.comparison', 'icon' => 'bi-arrow-left-right', 'tone' => 'indigo'],
                    ['label' => 'ใบขายสด', 'route' => 'cash-sales.index', 'pattern' => 'cash-sales.*', 'icon' => 'bi-cash-stack', 'tone' => 'teal'],
                    ['label' => 'ใบรับคืนสินค้า', 'route' => 'sale-returns.index', 'pattern' => 'sale-returns.*', 'icon' => 'bi-arrow-return-left', 'tone' => 'amber'],
                    ['label' => 'ใบวางบิล', 'route' => 'billing-notes.index', 'pattern' => 'billing-notes.*', 'icon' => 'bi-receipt', 'tone' => 'pink'],
                    ['label' => 'ใบเพิ่ม/ลดหนี้', 'route' => 'credit-debit-notes.index', 'pattern' => 'credit-debit-notes.*', 'icon' => 'bi-plus-slash-minus', 'tone' => 'orange'],
                    ['label' => 'ลูกค้าสัมพันธ์ (CRM)', 'route' => 'crm.index', 'pattern' => 'crm.*', 'icon' => 'bi-person-lines-fill', 'tone' => 'indigo'],
                    ['label' => 'Pipeline งานขาย', 'route' => 'crm.pipeline', 'pattern' => 'crm.pipeline', 'icon' => 'bi-kanban-fill', 'tone' => 'purple'],
                ],
            ],
            [
                'label' => 'POS / หน้าร้าน',
                'displayLabel' => 'POS / หน้าร้าน',
                'items' => [
                    ['label' => 'ศูนย์ควบคุม POS', 'route' => 'pos.control', 'pattern' => 'pos.control', 'icon' => 'bi-cash-coin', 'tone' => 'red'],
                    ['label' => 'จัดการเครื่อง POS', 'route' => 'pos-admin.index', 'pattern' => 'pos-admin.*', 'icon' => 'bi-pc-display', 'tone' => 'blue'],
                    ['label' => 'QR รับเงิน / จอแสดงราคา', 'route' => 'bplus.qr-payments', 'pattern' => 'bplus.qr-payments', 'extraPattern' => 'bplus.show-price', 'icon' => 'bi-qr-code', 'tone' => 'slate'],
                ],
            ],
            [
                'label' => 'คลัง / ผลิต / ซื้อ',
                'displayLabel' => 'สินค้า/คลัง',
                'items' => [
                    // 1. รับเข้า
                    ['label' => 'รับสินค้าเข้าคลัง (ซื้อเข้า)', 'route' => 'purchases.index', 'pattern' => 'purchases.*', 'icon' => 'bi-basket-fill', 'tone' => 'amber'],
                    ['label' => 'ใบขอซื้อ / สั่งซื้อ (PO)', 'route' => 'purchase-orders.index', 'pattern' => 'purchase-orders.*', 'icon' => 'bi-cart-plus-fill', 'tone' => 'orange'],
                    ['label' => 'สแกนใบรับสินค้า OCR', 'route' => 'ocr.documents.index', 'pattern' => 'ocr.documents.*', 'icon' => 'bi-file-earmark-richtext-fill', 'tone' => 'indigo'],

                    // 2. โอนย้าย
                    ['label' => 'โอนย้ายสินค้าข้ามสาขา', 'route' => 'stock-transfers.index', 'pattern' => 'stock-transfers.index', 'extraPattern' => 'stock-adjustments.*', 'icon' => 'bi-box-seam-fill', 'tone' => 'teal'],
                    ['label' => 'ขอโอนสินค้าข้ามสาขา', 'route' => 'stock-transfers.request', 'pattern' => 'stock-transfers.request*', 'icon' => 'bi-box-arrow-in-down', 'tone' => 'cyan'],
                    ['label' => 'แนะนำเติมสต็อกสาขา', 'route' => 'stock-transfers.replenishment.index', 'pattern' => 'stock-transfers.replenishment.*', 'icon' => 'bi-signpost-split-fill', 'tone' => 'teal'],

                    // 3. เบิกใช้
                    ['label' => 'เบิกใช้ / ตัดชำรุด / คืนเบิก', 'route' => 'stock-issues.index', 'pattern' => 'stock-issues.*', 'extraPattern' => 'stock-transforms.*', 'icon' => 'bi-box-arrow-up', 'tone' => 'orange'],

                    // 4. แปรรูป
                    ['label' => 'แปรรูปสินค้า / สูตรผลิต', 'route' => 'production.index', 'pattern' => 'production.*', 'icon' => 'bi-gear-wide-connected', 'tone' => 'slate'],
                    ['label' => 'รายงานผลผลิต & Yield', 'route' => 'production.efficiency', 'pattern' => 'production.efficiency', 'icon' => 'bi-graph-up-arrow', 'tone' => 'slate'],

                    // 5. ตรวจนับ
                    ['label' => 'ตรวจนับสต๊อกสินค้า', 'route' => 'stock-counts.index', 'pattern' => 'stock-counts.*', 'icon' => 'bi-clipboard-check-fill', 'tone' => 'cyan'],

                    // 6. ปรับยอด / เครื่องมือช่วยงาน
                    ['label' => 'คลังมือถือ (สแกนบาร์โค้ด)', 'route' => 'wh.index', 'pattern' => 'wh.*', 'icon' => 'bi-phone-fill', 'tone' => 'cyan'],
                    ['label' => 'แผนจัดซื้อและเติมสต็อก', 'route' => 'bplus.purchase-planning', 'pattern' => 'bplus.purchase-planning', 'icon' => 'bi-clipboard2-check-fill', 'tone' => 'teal'],
                ],
            ],
            [
                'label' => 'การเงิน / บัญชี',
                'displayLabel' => 'การเงิน/บัญชี',
                'items' => [
                    ['label' => 'บัญชีครบวงจร', 'route' => 'accounting-automation.index', 'pattern' => 'accounting-automation.*', 'icon' => 'bi-cpu-fill', 'tone' => 'indigo'],
                    ['label' => 'ผังบัญชี / บันทึกบัญชี', 'route' => 'chart-of-accounts.index', 'pattern' => 'chart-of-accounts.*', 'extraPattern' => 'gl-journals.*', 'icon' => 'bi-calculator-fill', 'tone' => 'red'],
                    ['label' => 'งวดบัญชี / ปิดงวด', 'route' => 'accounting-periods.index', 'pattern' => 'accounting-periods.*', 'icon' => 'bi-calendar-check-fill', 'tone' => 'amber'],
                    ['label' => 'ปิดบัญชีรายเดือน', 'route' => 'monthly-accounting.index', 'pattern' => 'monthly-accounting.*', 'icon' => 'bi-file-earmark-zip-fill', 'tone' => 'teal'],
                    ['label' => 'ภาษีไทย / E-Tax', 'route' => 'tax-compliance.index', 'pattern' => 'tax-compliance.*', 'icon' => 'bi-receipt', 'tone' => 'orange'],
                    ['label' => 'งบการเงิน', 'route' => 'financial-statements.index', 'pattern' => 'financial-statements.*', 'icon' => 'bi-graph-up', 'tone' => 'blue'],
                    ['label' => 'ทะเบียนทรัพย์สิน', 'route' => 'fixed-assets.index', 'pattern' => 'fixed-assets.*', 'icon' => 'bi-buildings', 'tone' => 'brown'],
                    ['label' => 'เงินสด / ภาษี', 'route' => 'bplus.finance', 'pattern' => 'bplus.finance', 'extraPattern' => 'bplus.tax', 'icon' => 'bi-journal-richtext', 'tone' => 'amber'],
                    ['label' => 'ใบเสร็จรับเงิน (รับชำระลูกหนี้)', 'route' => 'ar-receipts.index', 'pattern' => 'ar-receipts.*', 'icon' => 'bi-receipt-cutoff', 'tone' => 'green'],
                    ['label' => 'จ่ายชำระเจ้าหนี้', 'route' => 'ap-payments.index', 'pattern' => 'ap-payments.*', 'icon' => 'bi-wallet2', 'tone' => 'pink'],
                    ['label' => 'บัญชีธนาคาร', 'route' => 'bank-accounts.index', 'pattern' => 'bank-accounts.*', 'icon' => 'bi-bank2', 'tone' => 'blue'],
                    ['label' => 'ฝาก/ถอนเงินสด', 'route' => 'cash-transfers.index', 'pattern' => 'cash-transfers.*', 'icon' => 'bi-cash-stack', 'tone' => 'teal'],
                    ['label' => 'ทะเบียนเช็ค', 'route' => 'cheques.index', 'pattern' => 'cheques.*', 'icon' => 'bi-journal-check', 'tone' => 'teal'],
                    ['label' => 'อนุมัติเอกสาร', 'route' => 'bplus.approvals', 'pattern' => 'bplus.approvals', 'icon' => 'bi-shield-check', 'tone' => 'indigo'],
                ],
            ],
            [
                'label' => 'รายงาน',
                'displayLabel' => 'รายงาน',
                'items' => [
                    ['label' => 'ภาพรวมผู้บริหาร', 'route' => 'executive.index', 'pattern' => 'executive.*', 'icon' => 'bi-speedometer2', 'tone' => 'teal', 'params' => []],
                    ['label' => 'ศูนย์รวมรายงาน', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-clipboard-data-fill', 'tone' => 'blue', 'params' => []],
                    ['label' => 'รายงานขาย', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-receipt-cutoff', 'tone' => 'teal', 'params' => ['category' => 'sales'], 'queryCategory' => 'sales'],
                    ['label' => 'รายงานซื้อ', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-basket-fill', 'tone' => 'amber', 'params' => ['category' => 'purchasing'], 'queryCategory' => 'purchasing'],
                    ['label' => 'รายงานคลัง', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-box-seam-fill', 'tone' => 'cyan', 'params' => ['category' => 'inventory'], 'queryCategory' => 'inventory'],
                    ['label' => 'รายงานบัญชี', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-calculator-fill', 'tone' => 'red', 'params' => ['category' => 'payment'], 'queryCategory' => 'payment'],
                    ['label' => 'รายงานภาษี', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-receipt', 'tone' => 'orange', 'params' => ['category' => 'tax'], 'queryCategory' => 'tax'],
                ],
            ],
            [
                'label' => 'ระบบ',
                'displayLabel' => 'ตั้งค่า',
                'items' => [
                    // กลุ่มย่อย: ข้อมูลหลัก
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ค่าคงที่ระบบ', 'route' => 'basic-master.index', 'params' => ['type' => 'product-categories'], 'pattern' => 'basic-master.*', 'icon' => 'bi-sliders', 'tone' => 'blue'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'สินค้า / บริการ', 'route' => 'products.index', 'pattern' => 'products.*', 'icon' => 'bi-box-seam-fill', 'tone' => 'brown'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'นัดเปลี่ยนราคา', 'route' => 'products.scheduled-prices.index', 'pattern' => 'products.scheduled-prices.*', 'icon' => 'bi-clock-history', 'tone' => 'amber'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'หน่วยนับสินค้า', 'route' => 'product-units.index', 'pattern' => 'product-units.*', 'icon' => 'bi-rulers', 'tone' => 'teal'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'คลังสินค้า', 'route' => 'warehouse-locations.index', 'pattern' => 'warehouse-locations.*', 'icon' => 'bi-archive-fill', 'tone' => 'teal'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ลูกค้า (รวมลูกหนี้)', 'route' => 'customers.index', 'pattern' => 'customers.*', 'icon' => 'bi-people-fill', 'tone' => 'indigo'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ผู้จำหน่าย / เจ้าหนี้', 'route' => 'suppliers.index', 'pattern' => 'suppliers.*', 'icon' => 'bi-buildings-fill', 'tone' => 'pink'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'สมาชิก', 'route' => 'members.index', 'pattern' => 'members.*', 'icon' => 'bi-person-vcard-fill', 'tone' => 'indigo'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'พนักงาน', 'route' => 'employees.index', 'pattern' => 'employees.*', 'icon' => 'bi-person-badge-fill', 'tone' => 'blue'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ตารางราคา', 'route' => 'price-tables.index', 'pattern' => 'price-tables.*', 'icon' => 'bi-tags-fill', 'tone' => 'red'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ราคาเครื่องชั่ง', 'route' => 'scale-prices.index', 'pattern' => 'scale-prices.*', 'icon' => 'bi-speedometer2', 'tone' => 'teal'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ส่วนลดราคาสินค้า (แบบเดิม)', 'route' => 'promotions.index', 'pattern' => 'promotions.*', 'icon' => 'bi-gift-fill', 'tone' => 'amber'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ราคานาทีทอง', 'route' => 'flash-sales.index', 'pattern' => 'flash-sales.*', 'icon' => 'bi-lightning-charge-fill', 'tone' => 'red'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ป้ายราคา', 'route' => 'price-tags.index', 'pattern' => 'price-tags.*', 'icon' => 'bi-tag-fill', 'tone' => 'cyan'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'บัตรส่วนลด', 'route' => 'discount-cards.index', 'pattern' => 'discount-cards.*', 'icon' => 'bi-credit-card-2-front-fill', 'tone' => 'pink'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'แต้มสมาชิก', 'route' => 'member-points.index', 'pattern' => 'member-points.*', 'icon' => 'bi-stars', 'tone' => 'amber'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'โปรโมชัน / แคมเปญ', 'route' => 'qty-promotions.index', 'pattern' => 'qty-promotions.*', 'icon' => 'bi-gift-fill', 'tone' => 'orange'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ยานพาหนะ / ขนส่ง', 'route' => 'fleet.index', 'pattern' => 'fleet.*', 'icon' => 'bi-truck', 'tone' => 'orange'],
                    ['group' => 'ข้อมูลหลัก', 'label' => 'ศูนย์ตั้งต้นระบบ', 'route' => 'master-data-setup.index', 'pattern' => 'master-data-setup.*', 'icon' => 'bi-file-earmark-spreadsheet-fill', 'tone' => 'blue'],

                    // กลุ่มย่อย: เอกสาร
                    ['group' => 'เอกสาร', 'label' => 'สมุดเอกสาร', 'route' => 'document-books.index', 'pattern' => 'document-books.*', 'icon' => 'bi-journals', 'tone' => 'indigo'],
                    ['group' => 'เอกสาร', 'label' => 'ตั้งค่า Workflow เอกสาร', 'route' => 'settings.workflows', 'pattern' => 'settings.workflows*', 'icon' => 'bi-diagram-3-fill', 'tone' => 'red'],
                    ['group' => 'เอกสาร', 'label' => 'ศูนย์ควบคุมโมดูล', 'route' => 'settings.module-controls', 'pattern' => 'settings.module-controls*', 'icon' => 'bi-sliders2', 'tone' => 'blue'],

                    // กลุ่มย่อย: ผู้ใช้และสิทธิ์
                    ['group' => 'ผู้ใช้และสิทธิ์', 'label' => 'ผู้ใช้และสิทธิ์', 'route' => 'users.index', 'pattern' => 'users.*', 'icon' => 'bi-people-fill', 'tone' => 'indigo'],
                    ['group' => 'ผู้ใช้และสิทธิ์', 'label' => 'ผังองค์กร', 'route' => 'organizational-units.index', 'pattern' => 'organizational-units.*', 'icon' => 'bi-diagram-3-fill', 'tone' => 'indigo'],

                    // กลุ่มย่อย: เชื่อมต่อภายนอก
                    ['group' => 'เชื่อมต่อภายนอก', 'label' => 'LINE แจ้งเตือน', 'route' => 'line-integrations.index', 'pattern' => 'line-integrations.*', 'icon' => 'bi-broadcast', 'tone' => 'teal'],
                    ['group' => 'เชื่อมต่อภายนอก', 'label' => 'E-Commerce', 'route' => 'ecommerce-channels.index', 'pattern' => 'ecommerce-channels.*', 'icon' => 'bi-shop-window', 'tone' => 'cyan'],

                    // กลุ่มย่อย: ระบบ
                    ['group' => 'ระบบ', 'label' => 'ตั้งค่าระบบ', 'route' => 'settings.index', 'pattern' => 'settings.index', 'extraPattern' => 'settings.pos-*', 'icon' => 'bi-gear-fill', 'tone' => 'slate'],
                    ['group' => 'ระบบ', 'label' => 'นโยบายขายติดลบ', 'route' => 'settings.stock-policies', 'pattern' => 'settings.stock-policies', 'icon' => 'bi-sliders2-vertical', 'tone' => 'amber'],
                    ['group' => 'ระบบ', 'label' => 'Backup / Security', 'route' => 'operations.index', 'pattern' => 'operations.*', 'icon' => 'bi-shield-lock-fill', 'tone' => 'red'],
                    ['group' => 'ระบบ', 'label' => 'โครงสร้างฐานข้อมูล', 'route' => 'database-structure.index', 'pattern' => 'database-structure.*', 'icon' => 'bi-database-fill-gear', 'tone' => 'cyan'],
                ],
            ],
        ];
    }

    /**
     * เรียงตามลำดับที่ตั้งไว้ในหน้าตั้งค่า แล้วกรองเมนูที่ผู้ใช้ไม่มีสิทธิ์ออก
     * หมวดที่ถูกยุบไปแล้วใน menu_section_order จะถูกข้ามโดยไม่เกิด error
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(?User $user): array
    {
        $sections = self::all();
        $validLabels = array_column($sections, 'label');

        $savedOrder = json_decode((string) AppSetting::get('menu_section_order', '[]'), true);
        if (is_array($savedOrder) && $savedOrder !== []) {
            $savedOrder = array_values(array_filter(
                $savedOrder,
                fn ($label) => is_string($label) && in_array($label, $validLabels, true)
            ));

            if ($savedOrder !== []) {
                if (! in_array('POS / หน้าร้าน', $savedOrder, true)) {
                    $afterSales = array_search('งานประจำวัน', $savedOrder, true);
                    if ($afterSales !== false) {
                        array_splice($savedOrder, $afterSales + 1, 0, ['POS / หน้าร้าน']);
                    } else {
                        $savedOrder[] = 'POS / หน้าร้าน';
                    }
                }

                $positions = array_flip($savedOrder);
                $originalPositions = array_flip($validLabels);
                usort($sections, fn ($a, $b) => [
                    $positions[$a['label']] ?? 999,
                    $originalPositions[$a['label']] ?? 999,
                ] <=> [
                    $positions[$b['label']] ?? 999,
                    $originalPositions[$b['label']] ?? 999,
                ]);
            }
        }

        if (! $user) {
            return $sections;
        }

        return array_values(array_filter(array_map(function ($section) use ($user) {
            $section['items'] = array_values(array_filter($section['items'], function ($item) use ($user) {
                $permission = RoutePermissions::resolve($item['route']);

                return $permission === null || $user->hasPermission($permission);
            }));

            return $section;
        }, $sections), fn ($section) => $section['items'] !== []));
    }
}
