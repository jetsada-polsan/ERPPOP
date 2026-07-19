<?php

namespace App\Http\Controllers;

use App\Support\LegacyDocumentModules;
use Illuminate\View\View;

class FeatureController extends Controller
{
    public function index(): View
    {
        return view('features.index', [
            'modules' => $this->modules(),
        ]);
    }

    private function modules(): array
    {
        $modules = [
            [
                'key' => 'pos-backoffice',
                'title' => 'POS หลังบ้าน',
                'icon' => 'bi-pc-display',
                'accent' => '#0f766e',
                'summary' => 'เตรียมข้อมูล POS, รวมยอดขาย, ตรวจแฟ้ม, ลบข้อมูล และ sync กับสำนักงานใหญ่',
                'items' => [
                    ['name' => 'รวบรวมยอดขายรายวัน / รายเดือน', 'status' => 'ready', 'route' => ['pos-import.page', []]],
                    ['name' => 'ตรวจสอบยอดขายรายวัน W / ใบกำกับภาษีอย่างย่อ X', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'pos', 'report' => 'pos_receipts']]],
                    ['name' => 'เตรียมข้อมูลสำหรับเครื่อง POS P', 'status' => 'ready', 'route' => ['bplus.pos-preparation', []], 'note' => 'ส่งสินค้า ราคา แคมเปญ และสมาชิกไปเครื่องขาย'],
                    ['name' => 'ลบข้อมูล POS / POS Online / Member Online', 'status' => 'ready', 'route' => ['bplus.pos-preparation', []], 'note' => 'ล้างข้อมูลตามช่วงวันที่และเครื่อง POS'],
                    ['name' => 'สถานะรับส่งข้อมูล / แฟ้มยอดขาย', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'audit', 'report' => 'import_batches']]],
                ],
            ],
            [
                'key' => 'pos-sales',
                'title' => 'ขายหน้าร้าน POS',
                'icon' => 'bi-cart-check',
                'accent' => '#2563eb',
                'summary' => 'ขายสินค้า รับคืน พักบิล ลิ้นชัก รับเงินหลายประเภท และใบกำกับภาษี',
                'items' => [
                    ['name' => 'ขายสินค้า / คืนสินค้า / ยกเลิกบิล', 'status' => 'ready', 'route' => ['bplus.pos-workbench', []], 'note' => 'เช็กบิล POS ล่าสุดเพื่อออกใบกำกับภาษี/ตรวจยอด'],
                    ['name' => 'พักบิลระหว่างขาย', 'status' => 'ready', 'route' => ['pos.index', []], 'note' => 'ทำที่หน้า POS โดยตรง ปุ่มพักบิล/เรียกบิลคืน'],
                    ['name' => 'รับชำระ เงินสด เช็ค QR EDC และช่องทางอื่น', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'pos', 'report' => 'pos_payments']]],
                    ['name' => 'ใบกำกับภาษีอย่างย่อ / เต็มรูป', 'status' => 'ready', 'route' => ['bplus.tax', []]],
                    ['name' => 'นำส่งเงินขาย / คุมเงินสดในลิ้นชัก', 'status' => 'ready', 'route' => ['bplus.finance', []]],
                ],
            ],
            [
                'key' => 'price-promotion',
                'title' => 'ราคาและโปรโมชั่น',
                'icon' => 'bi-tags',
                'accent' => '#dc2626',
                'summary' => 'ราคาขาย ตารางราคา นาทีทอง คูปอง ส่วนลด ของแถม และแคมเปญสะสม',
                'items' => [
                    ['name' => 'ราคาขายทั่วไป / ตารางราคาขาย', 'status' => 'ready', 'route' => ['promotions.index', []], 'note' => 'POS คำนวณโปรโมชั่นแล้ว ขั้นถัดไปคือให้ Server ตรวจยืนยันราคาและส่วนลดทุกบิล'],
                    ['name' => 'ราคานาทีทอง / ป้ายราคา', 'status' => 'ready', 'route' => ['promotions.index', []]],
                    ['name' => 'ส่วนลดต่อรายการ / ท้ายบิล', 'status' => 'ready', 'route' => ['promotions.index', []]],
                    ['name' => 'แคมเปญซื้อครบ / ซื้อคละ / ลดและแถม', 'status' => 'ready', 'route' => ['promotions.index', []]],
                    ['name' => 'รายงานเปลี่ยนแปลงราคาขาย', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'audit', 'report' => 'pending_work']]],
                ],
            ],
            [
                'key' => 'member',
                'title' => 'สมาชิก',
                'icon' => 'bi-person-vcard',
                'accent' => '#be123c',
                'summary' => 'แฟ้มสมาชิก ประเภทสมาชิก ราคาสมาชิก แต้ม และ Member Online',
                'items' => [
                    ['name' => 'แฟ้มสมาชิก / ประเภทสมาชิก', 'status' => 'ready', 'route' => ['members.index', []]],
                    ['name' => 'ราคาสมาชิก / ส่วนลดสมาชิก', 'status' => 'ready', 'route' => ['members.index', []], 'note' => 'เก็บสมาชิกและแต้มได้แล้ว ส่วนสูตรราคาสมาชิกยังรอผูกโปรโมชั่น'],
                    ['name' => 'สะสมแต้ม / แต้มทวีคูณ / แลกแต้ม', 'status' => 'ready', 'route' => ['members.index', []]],
                    ['name' => 'Member Online', 'status' => 'ready', 'route' => ['members.index', []], 'note' => 'มีฐานสมาชิกสำหรับ sync แล้ว'],
                ],
            ],
            [
                'key' => 'inventory',
                'title' => 'สินค้าและคลัง',
                'icon' => 'bi-box-seam',
                'accent' => '#0891b2',
                'summary' => 'สินค้า คลัง แหล่งเก็บ ตรวจนับ โอนย้าย เบิก คืน ตัดชำรุด และ WMS',
                'items' => [
                    ['name' => 'สินค้า หน่วยนับ บาร์โค้ด เครื่องชั่ง', 'status' => 'ready', 'route' => ['products.index', []], 'note' => 'รองรับหน่วย บาร์โค้ด PLU สินค้าชั่ง และตารางราคาแล้ว'],
                    ['name' => 'สินค้าคงเหลือ', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'inventory', 'report' => 'stock_balance']]],
                    ['name' => 'สมุดคลังสินค้า / เคลื่อนไหวสินค้า', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'inventory', 'report' => 'stock_movements']]],
                    ['name' => 'ตรวจนับ / โอนย้ายสต็อก', 'status' => 'ready', 'route' => ['stock-transfers.index', []]],
                    ['name' => 'แจ้งเตือนสต็อกต่ำ / เติมเต็ม / WMS', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'inventory', 'report' => 'stock_alerts']]],
                ],
            ],
            [
                'key' => 'purchase',
                'title' => 'จัดซื้อและเจ้าหนี้',
                'icon' => 'bi-basket',
                'accent' => '#b45309',
                'summary' => 'ขอซื้อ สอบราคา สั่งซื้อ ซื้อสด ซื้อเชื่อ ส่งคืน และ AP',
                'items' => [
                    ['name' => 'ขอซื้อ / สอบราคา / สั่งซื้อ', 'status' => 'ready', 'route' => ['bplus.purchase-planning', []]],
                    ['name' => 'ซื้อสด / ซื้อเชื่อ', 'status' => 'ready', 'route' => ['purchases.index', []]],
                    ['name' => 'Prepare PO ตามยอดขาย', 'status' => 'ready', 'route' => ['bplus.purchase-planning', []]],
                    ['name' => 'เจ้าหนี้ AP / ชำระเงิน', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'payment', 'report' => 'payment_documents']]],
                    ['name' => 'อนุมัติวงเงินเครดิตซื้อ', 'status' => 'ready', 'route' => ['bplus.approvals', []]],
                ],
            ],
            [
                'key' => 'sales-ar',
                'title' => 'ขายและลูกหนี้',
                'icon' => 'bi-receipt-cutoff',
                'accent' => '#1d4ed8',
                'summary' => 'เสนอราคา จองสินค้า ขายสด ขายเชื่อ รับคืน วางบิล และ AR',
                'items' => [
                    ['name' => 'ใบจองสินค้า / แปลงเป็นขายเชื่อ', 'status' => 'ready', 'route' => ['bookings.index', []]],
                    ['name' => 'ขายสด', 'status' => 'ready', 'route' => ['cash-sales.index', []]],
                    ['name' => 'รับคืนสินค้า', 'status' => 'ready', 'route' => ['sale-returns.index', []]],
                    ['name' => 'ลูกหนี้ AR / ใบเพิ่มหนี้ / ใบลดหนี้', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'ar', 'report' => 'ar_aging']]],
                    ['name' => 'ใบเสร็จรับเงิน / รับชำระหนี้', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'payment', 'report' => 'payment_documents']]],
                    ['name' => 'อนุมัติวงเงินเครดิตขาย', 'status' => 'ready', 'route' => ['bplus.approvals', []]],
                ],
            ],
            [
                'key' => 'production',
                'title' => 'ผลิตและแปรรูป',
                'icon' => 'bi-gear-wide-connected',
                'accent' => '#475569',
                'summary' => 'สูตรแปรรูป ขอผลิต รับจากการผลิต แปรรูป และตัดวัตถุดิบ',
                'items' => [
                    ['name' => 'สูตรแปรรูป / สูตรช่วยการบันทึก', 'status' => 'ready', 'route' => ['production.index', []]],
                    ['name' => 'ขอผลิต / รับสินค้าจากการผลิต', 'status' => 'ready', 'route' => ['production.index', []]],
                    ['name' => 'แปรรูปสินค้า / ตัดวัตถุดิบ', 'status' => 'ready', 'route' => ['production.index', []], 'note' => 'เบิกวัตถุดิบด้วยเอกสาร DR และรับผลผลิตด้วย IP; ยังต้องเพิ่มการเทียบสูตรกับใช้จริงอัตโนมัติ'],
                ],
            ],
            [
                'key' => 'accounting',
                'title' => 'บัญชีและการเงิน',
                'icon' => 'bi-calculator',
                'accent' => '#0f172a',
                'summary' => 'ผังบัญชี งวดบัญชี เงินสด ธนาคาร เช็ค GL VAT และ WHT',
                'items' => [
                    ['name' => 'ผังบัญชี', 'status' => 'ready', 'route' => ['chart-of-accounts.index', []]],
                    ['name' => 'เงินสด ธนาคาร เช็ครับ เช็คจ่าย', 'status' => 'ready', 'route' => ['bplus.finance', []]],
                    ['name' => 'สมุดรายวันทั่วไป GL', 'status' => 'ready', 'route' => ['gl-journals.index', []]],
                    ['name' => 'ภาษีขาย VAT / ภาษีหัก ณ ที่จ่าย', 'status' => 'ready', 'route' => ['bplus.tax', []]],
                    ['name' => 'งบการเงิน / กระแสเงินสด', 'status' => 'ready', 'route' => ['bplus.finance', []]],
                ],
            ],
            [
                'key' => 'reports',
                'title' => 'รายงานและ BI',
                'icon' => 'bi-clipboard-data',
                'accent' => '#0369a1',
                'summary' => 'รายงานยอดขาย สินค้า ชำระเงิน ภาษี แคชเชียร์ Z/X และ dashboard',
                'items' => [
                    ['name' => 'รายงานหลักแบบเลือกหมวด', 'status' => 'ready', 'route' => ['reports.index', []]],
                    ['name' => 'สรุปยอดขายรายวัน / ตามสินค้า / ตามสาขา', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'sales', 'report' => 'sales_by_branch']]],
                    ['name' => 'รายงานตามเครื่อง POS / แคชเชียร์ / ชำระเงิน', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'pos', 'report' => 'pos_by_terminal']]],
                    ['name' => 'รายงาน Z / X / ภาษีขาย', 'status' => 'ready', 'route' => ['bplus.tax', []]],
                    ['name' => 'Export CSV / Print / Paperless', 'status' => 'ready', 'route' => ['reports.index', []]],
                ],
            ],
            [
                'key' => 'online',
                'title' => 'Online, API และแจ้งเตือน',
                'icon' => 'bi-broadcast',
                'accent' => '#16a34a',
                'summary' => 'POS Online, Member Online, LINE Messaging API, QR Code, E-Commerce และ API',
                'items' => [
                    ['name' => 'LINE / แจ้งยอดขาย / แจ้ง QR', 'status' => 'ready', 'route' => ['line-integrations.index', []], 'note' => 'เก็บ config สำหรับ Messaging API แทน LINE Notify เดิม'],
                    ['name' => 'Dynamic QR / Static QR / EDC', 'status' => 'ready', 'route' => ['bplus.qr-payments', []]],
                    ['name' => 'Show Price / Check Price / Queue Buster', 'status' => 'ready', 'route' => ['bplus.show-price', []]],
                    ['name' => 'E-Commerce / Franchise Retail', 'status' => 'ready', 'route' => ['ecommerce-channels.index', []], 'note' => 'ลงทะเบียนช่องทาง Lazada, Shopee, LINE MyShop, TikTok Shop ได้แล้ว'],
                    ['name' => 'API / Import-Export', 'status' => 'ready', 'route' => ['reports.index', ['category' => 'audit', 'report' => 'import_batches']]],
                ],
            ],
        ];

        return LegacyDocumentModules::attachToModules($modules);
    }
}
