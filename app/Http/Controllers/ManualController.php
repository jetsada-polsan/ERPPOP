<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ManualController extends Controller
{
    public function index(): View
    {
        return view('core-modules.index', [
            'pillars' => $this->pillars(),
            'workflows' => $this->workflows(),
            'controlManuals' => $this->controlManuals(),
            'gaps' => $this->gaps(),
            'routines' => $this->routines(),
            'thaiErpStandards' => $this->thaiErpStandards(),
            'thaiErpSources' => $this->thaiErpSources(),
        ]);
    }

    private function thaiErpStandards(): array
    {
        return [
            ['group' => 'ขายและ POS', 'capability' => 'ขายปลีก/ขายส่ง หลายราคา หลายหน่วย และบาร์โค้ด', 'benchmark' => 'มาตรฐานพื้นฐานของ ERP/POS ไทยสำหรับร้านหลายรูปแบบ', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'pos.index', 'next' => 'ทดสอบราคาจริงและหน่วยบรรจุของสินค้าทุกกลุ่ม'],
            ['group' => 'ขายและ POS', 'capability' => 'สมาชิก แต้ม โปรโมชั่น คูปอง และสิทธิ์ส่วนลด', 'benchmark' => 'ควบคุมแคมเปญและประวัติลูกค้าจากหน้าร้าน', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'members.index', 'next' => 'เพิ่ม segmentation และวัดกำไรสุทธิรายแคมเปญ'],
            ['group' => 'ขายและ POS', 'capability' => 'เครื่องชั่ง PLU/บาร์โค้ดน้ำหนักและราคาฝังในฉลาก', 'benchmark' => 'รองรับร้านของสดและฮาร์ดแวร์หน้าร้าน', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'scale-prices.index', 'next' => 'ทำรายการรุ่นเครื่องชั่งและรูปแบบบาร์โค้ดที่ผ่านการทดสอบ'],
            ['group' => 'ขายและ POS', 'capability' => 'ขายออฟไลน์และซิงก์กลับเมื่ออินเทอร์เน็ตมา', 'benchmark' => 'POS สาขาต้องขายต่อได้เมื่อเครือข่ายขัดข้อง', 'status' => 'มีชุดหลัก', 'tone' => 'partial', 'route' => 'pos-import.page', 'next' => 'ทดสอบ conflict, idempotency และ recovery บน POS Windows จริง'],
            ['group' => 'ขายและ POS', 'capability' => 'ร้านอาหาร โต๊ะ ครัว แยก/รวมบิล และตัวเลือกวิธีปรุง', 'benchmark' => 'ความสามารถเฉพาะ Restaurant POS ที่พบในตลาดไทย', 'status' => 'ยังไม่ทำ', 'tone' => 'planned', 'route' => null, 'next' => 'ทำเฉพาะเมื่อ PopStar เปิดธุรกิจร้านอาหารเต็มรูปแบบ'],

            ['group' => 'สินค้าและคลัง', 'capability' => 'หลายคลัง หลายตำแหน่ง หลายสาขา และสินค้าระหว่างทาง', 'benchmark' => 'เห็นตำแหน่งและสถานะสินค้าแยกแต่ละสาขา', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'warehouse-locations.index', 'next' => 'กำหนด location จริงและผู้รับผิดชอบทุกคลัง'],
            ['group' => 'สินค้าและคลัง', 'capability' => 'Lot/Serial/วันหมดอายุ FEFO กักกัน และเรียกคืน', 'benchmark' => 'จำเป็นกับอาหาร ยา และสินค้าที่ต้องสอบย้อนกลับ', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'products.index', 'next' => 'บังคับกรอก Lot/วันหมดอายุในกลุ่มสินค้าควบคุม'],
            ['group' => 'สินค้าและคลัง', 'capability' => 'ตรวจนับด้วยมือถือ ปรับยอดแบบ Maker-Checker และ Audit', 'benchmark' => 'ลดการแก้สต๊อกโดยไม่มีหลักฐาน', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'stock-counts.index', 'next' => 'กำหนดรอบนับ ABC และ tolerance รายกลุ่ม'],
            ['group' => 'สินค้าและคลัง', 'capability' => 'FIFO/FEFO ต้นทุนล่าสุด ต้นทุนเฉลี่ย และปิดต้นทุนรายงวด', 'benchmark' => 'ต้นทุนขายและมูลค่าสินค้าต้องย้อนตรวจถึง Lot ได้', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'reports.index', 'next' => 'กระทบยอด GL Inventory กับ Stock Valuation ทุกเดือน'],
            ['group' => 'สินค้าและคลัง', 'capability' => 'เติมเต็มสินค้าอัตโนมัติ Min/Max/Reorder และเสนอซื้อ', 'benchmark' => 'ลดของขาดและของค้างด้วยยอดขาย Stock พร้อมขาย ของค้างรับ Lead time Safety stock และ MOQ', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'bplus.purchase-planning', 'next' => 'ติดตามความแม่นยำคำแนะนำเทียบยอดขายจริงและเพิ่มปัจจัยฤดูกาล'],

            ['group' => 'จัดซื้อและเจ้าหนี้', 'capability' => 'PR/PO อนุมัติ รับของบางส่วน และติดตามของค้างส่ง', 'benchmark' => 'วงจรจัดซื้อต้องแยกผู้ขอ ผู้อนุมัติ และผู้รับของ', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'purchase-orders.index', 'next' => 'เพิ่ม Supplier quotation comparison แบบหลายเจ้า'],
            ['group' => 'จัดซื้อและเจ้าหนี้', 'capability' => 'Three-way match ระหว่าง PO ใบรับของ และใบกำกับผู้ขาย', 'benchmark' => 'ป้องกันจ่ายเกินจำนวน ราคา หรือของที่ยังไม่รับ', 'status' => 'มีชุดหลัก', 'tone' => 'partial', 'route' => 'purchases.index', 'next' => 'ทำหน้าข้อยกเว้นพร้อม tolerance และผู้อนุมัติ'],
            ['group' => 'จัดซื้อและเจ้าหนี้', 'capability' => 'AP Aging กำหนดชำระ เช็ค/โอน และภาษีหัก ณ ที่จ่าย', 'benchmark' => 'วางแผนเงินจ่ายและออกหลักฐานภาษีครบ', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'suppliers.index', 'next' => 'เพิ่ม payment proposal ตามวันครบกำหนดและกระแสเงินสด'],

            ['group' => 'ผลิตและแปรรูป', 'capability' => 'BOM/สูตรผลิต ตัดวัตถุดิบ รับผลผลิต Yield และของเสีย', 'benchmark' => 'คำนวณต้นทุนจริงจากวัตถุดิบและน้ำหนักผลผลิต', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'production.index', 'next' => 'เพิ่ม version สูตรและวันเริ่มมีผล'],
            ['group' => 'ผลิตและแปรรูป', 'capability' => 'ชุดสินค้า/แกะ-ประกอบ/แบ่งบรรจุ พร้อมป้ายเครื่องชั่ง', 'benchmark' => 'รองรับชุดหมูกระทะและของสดที่แปรรูปหลายรายการ', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'stock-transforms.index', 'next' => 'บันทึกแรงงานและค่าโสหุ้ยต่อ Batch'],
            ['group' => 'ผลิตและแปรรูป', 'capability' => 'วางแผนกำลังผลิต MRP และ Capacity', 'benchmark' => 'โรงงานขนาดกลางต้องคำนวณวัตถุดิบและกำลังเครื่อง/คนล่วงหน้า', 'status' => 'ยังไม่ทำ', 'tone' => 'planned', 'route' => null, 'next' => 'เริ่มจาก demand plan และ material requirement ตามสูตร'],

            ['group' => 'บัญชีและภาษีไทย', 'capability' => 'GL อัตโนมัติ งบทดลอง P&L งบดุล กระแสเงินสด และล็อกงวด', 'benchmark' => 'รายการต้นทางต้องไหลถึงงบและย้อนกลับหาเอกสารได้', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'financial-statements.index', 'next' => 'เพิ่มมิติรายสาขา Cost Center และงบเปรียบเทียบ'],
            ['group' => 'บัญชีและภาษีไทย', 'capability' => 'VAT ซื้อ/ขาย ภ.พ.30 และ ภ.ง.ด.3/53 พร้อม Working paper', 'benchmark' => 'ส่งสำนักงานบัญชีและตรวจผู้จัดทำ/ผู้ตรวจได้', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'tax-compliance.index', 'next' => 'เพิ่ม format กลาง ภ.ง.ด.1 และตรวจตามเวอร์ชันกรมสรรพากร'],
            ['group' => 'บัญชีและภาษีไทย', 'capability' => 'e-Tax Invoice/e-Receipt XML ลายมือชื่อ/ประทับเวลา และผลตอบรับ', 'benchmark' => 'ข้อมูลอิเล็กทรอนิกส์ต้องผ่าน e-Standard และติดตามสถานะนำส่ง', 'status' => 'มี Package', 'tone' => 'partial', 'route' => 'tax-compliance.index', 'next' => 'เลือก Service Provider/CA แล้วทดสอบ XML จริงใน sandbox'],
            ['group' => 'บัญชีและภาษีไทย', 'capability' => 'DBD e-Filing/XBRL และ mapping ผังบัญชีไป Taxonomy', 'benchmark' => 'ลดการกรอกงบซ้ำและเตรียมข้อมูลตามรูปแบบ DBD', 'status' => 'ยังไม่ทำ', 'tone' => 'planned', 'route' => null, 'next' => 'ทำ Taxonomy mapping และ export Excel/XBRL ที่สำนักงานบัญชีตรวจได้'],
            ['group' => 'บัญชีและภาษีไทย', 'capability' => 'OCR เอกสารซื้อ/ค่าใช้จ่ายและตรวจข้อมูลซ้ำ', 'benchmark' => 'ลดเวลาคีย์ใบกำกับและแนบภาพกับรายการบัญชี', 'status' => 'ยังไม่ทำ', 'tone' => 'planned', 'route' => null, 'next' => 'เริ่ม OCR เลขภาษี เลขที่ วันที่ ยอดก่อน VAT และ VAT'],

            ['group' => 'คนและเงินเดือน', 'capability' => 'แฟ้มพนักงาน เวลา OT ขาดลา เงินเดือน ประกันสังคม ภาษี และสลิป', 'benchmark' => 'Payroll ต้องแยกผู้จัดทำ/อนุมัติและลงบัญชีได้', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'management-controls.index', 'next' => 'เพิ่ม leave workflow และไฟล์จ่ายธนาคารตามธนาคารที่ใช้จริง'],
            ['group' => 'คนและเงินเดือน', 'capability' => 'Recruitment, Onboarding, ประเมินผล และ Training', 'benchmark' => 'ความสามารถ HRM สำหรับองค์กรที่ขยายจำนวนพนักงาน', 'status' => 'ยังไม่ทำ', 'tone' => 'planned', 'route' => null, 'next' => 'เริ่ม onboarding checklist และเอกสารพนักงานหมดอายุ'],

            ['group' => 'บริหารและเชื่อมต่อ', 'capability' => 'Dashboard/BI กำไร สาขา สินค้า Cash flow และ Drill-down', 'benchmark' => 'ผู้บริหารต้องเปิดจากตัวเลขสรุปถึงเอกสารต้นทาง', 'status' => 'มีชุดหลัก', 'tone' => 'partial', 'route' => 'dashboard', 'next' => 'เพิ่ม KPI เป้าหมาย เทียบช่วง และ EBITDA/financial ratios'],
            ['group' => 'บริหารและเชื่อมต่อ', 'capability' => 'API, Webhook และ E-Commerce order/stock sync', 'benchmark' => 'เชื่อม Marketplace และระบบภายนอกโดยไม่คีย์ซ้ำ', 'status' => 'มีทะเบียน', 'tone' => 'partial', 'route' => 'ecommerce-channels.index', 'next' => 'ทำ connector จริงทีละช่องทางพร้อม retry และ reconciliation'],
            ['group' => 'บริหารและเชื่อมต่อ', 'capability' => 'MFA, RBAC, Branch scope, Audit log, Backup และ Restore drill', 'benchmark' => 'ควบคุมสิทธิ์และความต่อเนื่องทางธุรกิจ', 'status' => 'พร้อมใช้', 'tone' => 'ready', 'route' => 'operations.index', 'next' => 'เปิด MFA ผู้ใช้สำคัญและตั้ง offsite backup production'],
        ];
    }

    private function thaiErpSources(): array
    {
        return [
            ['name' => 'SeniorSoft ProMaxx', 'scope' => 'POS, คลัง, หลายหน่วย, ชุดสินค้า, Lot/Serial และหลายสาขา', 'url' => 'https://www.seniorsoft.co.th/th/product-seniorsoft/software-package/item/230-seniorsoft-promaxx.html'],
            ['name' => 'Business Plus ERP/POS', 'scope' => 'บัญชีบริหาร, ค้าปลีก/ค้าส่ง, Payroll, BI และความสามารถเฉพาะร้านอาหาร', 'url' => 'https://www.businessplus.co.th/Files/2023/MK/MK01-18.pdf'],
            ['name' => 'PEAK Accounting', 'scope' => 'เอกสารขาย, บัญชี, สต๊อก, งบการเงิน, API และ Payroll', 'url' => 'https://www.peakaccount.com/feature-accounting-program'],
            ['name' => 'FlowAccount Payroll', 'scope' => 'เงินเดือน ประกันสังคม ภาษี สลิป และการลงบัญชีอัตโนมัติ', 'url' => 'https://flowaccount.com/payroll'],
            ['name' => 'กรมสรรพากร e-Tax', 'scope' => 'e-Standard, XML, ใบรับรองอิเล็กทรอนิกส์ และช่องทางนำส่ง', 'url' => 'https://etax.rd.go.th/'],
            ['name' => 'กรมสรรพากร Format WHT', 'scope' => 'รูปแบบกลาง ภ.ง.ด.1/2/3/53 สำหรับนำส่งข้อมูล', 'url' => 'https://www.rd.go.th/54910.html'],
            ['name' => 'DBD e-Filing', 'scope' => 'Taxonomy, XBRL in Excel และรูปแบบงบการเงินอิเล็กทรอนิกส์', 'url' => 'https://efiling.dbd.go.th/efiling-documents/01_ManualFN.pdf'],
        ];
    }

    private function controlManuals(): array
    {
        return [
            [
                'key' => 'accounting', 'title' => '1. บัญชีอัตโนมัติและการปิดงวด', 'route' => 'accounting-periods.index',
                'owner' => 'บัญชีเป็นผู้จัดทำ หัวหน้าบัญชีเป็นผู้ปิดงวด',
                'purpose' => 'ให้เอกสารขาย ซื้อ รับจ่าย คืนสินค้า ค่าใช้จ่าย VAT และต้นทุนลง GL แบบเดบิตเท่ากับเครดิต และห้ามแก้ย้อนหลังหลังปิดงวด',
                'setup' => ['ตั้ง default role ในผังบัญชีให้ครบ Cash, Bank, AR, AP, Inventory, VAT Input/Output, Revenue, COGS, Expense และ WHT', 'สร้างงวดบัญชีรายเดือนโดยเลือกทั้งบริษัทหรือเฉพาะสาขา', 'ตรวจว่าวันที่เอกสารและสาขาถูกต้องก่อนเริ่มบันทึกจริง'],
                'steps' => ['บันทึกเอกสารต้นทางตามปกติ ระบบสร้าง GL ใน transaction เดียวกัน', 'เปิดสมุดรายวัน ตรวจเลขเอกสาร บัญชี เดบิต เครดิต และต้นทุนขาย', 'สิ้นเดือนเปิดงวดบัญชี ดู Pre-close checklist 5 รายการ', 'แก้เอกสารไม่ลง GL, Statement ค้าง, ภาษีขาดข้อมูล และ Backup จนทุกข้อเป็นสีเขียว', 'หัวหน้าบัญชีระบุหมายเหตุแล้วกดปิดงวด เอกสารและ GL ในช่วงนั้นจะถูกล็อก'],
                'controls' => ['ห้ามโพสต์ GL บางบรรทัดเมื่อบัญชี default role ไม่ครบ', 'ผู้สังเกตการณ์ Document และ GL ปฏิเสธ create/update/delete ในงวดปิด', 'การเปิดงวดใหม่และปิดงวดถูกเก็บ Audit Log'],
                'outputs' => ['สมุดรายวันทั่วไป', 'งบทดลอง กำไรขาดทุน และงบดุล', 'หลักฐานผู้ปิดงวด วันเวลา และ checklist'],
            ],
            [
                'key' => 'tax', 'title' => '2. ภาษีไทยและ E-Tax', 'route' => 'tax-compliance.index',
                'owner' => 'บัญชีภาษีจัดทำ ผู้ตรวจคนที่สองทบทวน ผู้มีอำนาจยื่นจริง',
                'purpose' => 'รวมข้อมูล ภ.พ.30 ภ.ง.ด.3 ภ.ง.ด.53 และเตรียม E-Tax package โดยมีหลักฐานการตรวจและเลขอ้างอิงจากระบบภายนอก',
                'setup' => ['กรอกชื่อบริษัท เลขผู้เสียภาษี ที่อยู่ และสาขาภาษีในตั้งค่าระบบ', 'กำหนดสินค้าใดมี VAT และตรวจใบกำกับซื้อทุกใบ', 'เลือกผู้ให้บริการ E-Tax ที่ได้รับอนุมัติและเตรียม certificate/บัญชีผู้ใช้นอก ERP'],
                'steps' => ['เลือกเดือนและสาขาแล้วจัดทำ PP30/PND3/PND53', 'ดาวน์โหลด CSV ตรวจยอดกับ GL และเอกสารภาษี', 'ให้ผู้ใช้อีกคนกดตรวจผ่าน ระบบไม่ยอมให้ผู้จัดทำตรวจงานตัวเอง', 'ยื่นผ่านระบบกรมสรรพากรหรือสำนักงานบัญชี แล้วนำเลขอ้างอิงกลับมาบันทึก', 'สำหรับ E-Tax ให้สร้าง provider package ส่งผู้ให้บริการ แล้วบันทึกสถานะ sent/accepted/rejected รายใบ'],
                'controls' => ['ไฟล์ทุกชุดมี SHA-256 ป้องกันไฟล์เปลี่ยนหลังตรวจ', 'E-Tax ใช้ UUID ไม่ซ้ำและเก็บ payload hash', 'ERP ไม่อ้างว่าส่งกรมสรรพากรสำเร็จจนกว่าจะมีผลตอบรับจากผู้ให้บริการ'],
                'outputs' => ['CSV working paper รายแบบ', 'ทะเบียนการจัดทำ ตรวจ และยื่นภาษี', 'ทะเบียน E-Tax พร้อม UUID, hash และผลตอบรับ'],
            ],
            [
                'key' => 'backup', 'title' => '3. Backup และการกู้คืน', 'route' => 'operations.index',
                'owner' => 'IT ดูแลทุกวัน ผู้บริหารตรวจผลอย่างน้อยรายเดือน',
                'purpose' => 'มีฐานข้อมูลสำรองที่ตรวจความสมบูรณ์ได้ เก็บย้อนหลัง และทดสอบกู้คืนโดยไม่เสี่ยงเขียนทับ Production',
                'setup' => ['ให้ Scheduler ของ Laravel ทำงานทุกนาที', 'กำหนด ERP_BACKUP_OFFSITE_DISK เพื่อส่งสำเนาออกนอกเครื่อง', 'กำหนด ERP_RESTORE_DATABASE เป็นฐานทดสอบแยกก่อนทดสอบ restore จริง'],
                'steps' => ['ระบบ Backup อัตโนมัติทุกวัน 02:15 หรือกด Backup ตอนนี้', 'ตรวจไฟล์ .gz และ .sha256 ในศูนย์ Backup', 'กดตรวจ Restore เพื่อทดสอบ checksum และการคลายไฟล์', 'ทุกเดือนให้ IT restore ลงฐานทดสอบแล้วเปิดตรวจข้อมูลสำคัญ', 'หาก Backup เกิน 26 ชั่วโมง Health monitor แจ้งเตือนและห้ามปิดงวด'],
                'controls' => ['ไฟล์สิทธิ์ 0600 และเก็บย้อนหลัง 30 วัน', 'ไม่เปิดปุ่ม restore ทับ Production จากหน้าเว็บ', 'บันทึกผู้สั่ง เวลา ผลลัพธ์ และ Audit Log'],
                'outputs' => ['ไฟล์ฐานข้อมูลบีบอัด', 'SHA-256 checksum', 'Operation run และผล Restore drill'],
            ],
            [
                'key' => 'security', 'title' => '4. Security, MFA และ Audit', 'route' => 'operations.index',
                'owner' => 'ผู้ดูแลระบบกำหนดสิทธิ์ เจ้าของบัญชีดูแลรหัสผ่านและ MFA',
                'purpose' => 'ลดความเสี่ยงรหัสผ่านรั่ว จำกัดสิทธิ์ตามหน้าที่และสาขา และตรวจย้อนหลังว่าใครทำอะไรเมื่อใด',
                'setup' => ['ปิดบัญชีพนักงานที่ออกทันทีและห้ามใช้บัญชีร่วมกัน', 'กำหนด Role ตามหน้าที่ แยกผู้จัดทำ ผู้อนุมัติ และผู้ตรวจ', 'เปิด MFA ให้ผู้ดูแลระบบ ผู้บริหาร การเงิน และบัญชีทุกคน'],
                'steps' => ['ผู้ใช้เปิดศูนย์ Backup/Security แล้วเลือกเปิด MFA ของฉัน', 'เพิ่ม Setup key ใน Google/Microsoft Authenticator แบบ Time based', 'กรอกรหัสผ่านและรหัส 6 หลักเพื่อเปิดใช้งาน', 'ครั้งต่อไปหลังรหัสผ่าน ระบบถามรหัส 6 หลักอีกขั้น', 'ผู้ดูแลตรวจเปอร์เซ็นต์ MFA รหัสผ่านเกิน 180 วัน Session และ Audit เป็นประจำ'],
                'controls' => ['Login จำกัด 5 ครั้งต่อนาทีต่อ username และ IP', 'Session regenerate หลัง login/MFA และ password change', 'MFA secret เข้ารหัสด้วย APP_KEY และไม่แสดงใน API/Model serialization'],
                'outputs' => ['สถานะ MFA รายผู้ใช้', 'Login/MFA Audit พร้อม IP และ user agent', 'รายการ Session และเหตุการณ์ Monitor'],
            ],
            [
                'key' => 'reconcile', 'title' => '5. Statement, สลิป และการกระทบยอด', 'route' => 'monthly-accounting.index',
                'owner' => 'พนักงานการเงินตรวจ ผู้จัดการการเงินติดตามรายการค้าง',
                'purpose' => 'เทียบเงินเข้าจาก POS/ลูกหนี้และเงินออกค่าใช้จ่ายกับ Statement ให้ครบก่อนส่งสำนักงานบัญชีหรือปิดงวด',
                'setup' => ['สร้างบัญชีธนาคารและผูกสาขาให้ถูกต้อง', 'รายการโอน/QR ต้องใช้ method และ bank account ที่ตรงกัน', 'CSV Statement ต้องมี date, description, amount, balance โดยเงินเข้าเป็นบวก เงินออกเป็นลบ'],
                'steps' => ['เลือกเดือนและสาขา นำเข้า Statement CSV', 'กดจับคู่อัตโนมัติ ระบบตรวจวันที่ บัญชี ยอด และรายการที่ยังไม่เคยใช้', 'เงินออกจับคู่ Branch Expense ส่วนเงินเข้าจับคู่ Payment Line หรือ POS transfer/QR', 'รายการไม่ชัดเจนให้เปิดตรวจ แนบสลิป ระบุประเภท ยอดอ้างอิง และเลขอ้างอิง', 'แก้ยอดต่างจนสถานะ matched ครบ จึงสร้าง ZIP ส่งสำนักงานบัญชีและปิดงวดได้'],
                'controls' => ['source_type/source_id ไม่ซ้ำ ป้องกันธุรกรรมเดียวจับคู่หลาย Statement', 'ผลต่างเกิน 0.01 บาทเป็น mismatch', 'การจับคู่อัตโนมัติให้ confidence 100 เฉพาะวันที่ บัญชีและยอดตรงทั้งหมด'],
                'outputs' => ['ทะเบียน Bank Reconciliation', 'สลิปและหลักฐานราย Statement', 'CSV กระทบยอดในชุดสำนักงานบัญชี'],
            ],
        ];
    }

    private function pillars(): array
    {
        return [
            [
                'key' => 'man',
                'label' => 'MAN',
                'title' => 'คนและความรับผิดชอบ',
                'icon' => 'bi-people-fill',
                'tone' => 'teal',
                'summary' => 'กำหนดโครงสร้างองค์กร ผู้ใช้ สิทธิ์ พนักงานขาย ลูกค้า สมาชิก และผู้จำหน่ายให้รู้ว่าใครทำอะไร ที่สาขาใด',
                'programs' => [
                    ['ผู้ใช้และสิทธิ์', 'users.index', 'พนักงาน บทบาท และสาขา', 'สิทธิ์เข้าเมนูและขอบเขตข้อมูล'],
                    ['แฟ้มพนักงาน', 'employees.index', 'ข้อมูลบุคคลและตำแหน่ง', 'ทะเบียนพนักงานกลาง'],
                    ['ผังองค์กร', 'organizational-units.index', 'หน่วยงานและผู้บังคับบัญชา', 'สายอนุมัติและผู้รับผิดชอบ'],
                    ['พนักงานขาย', 'salesmen.index', 'รหัสพนักงานและสาขา', 'ผู้ขาย/แคชเชียร์บนเอกสาร'],
                    ['ลูกค้าและสมาชิก', 'customers.index', 'ข้อมูลติดต่อ เครดิต และสมาชิก', 'AR ประวัติซื้อ และแต้ม'],
                    ['ผู้จำหน่าย', 'suppliers.index', 'คู่ค้า เงื่อนไขซื้อ และบัญชี', 'AP และประวัติการจัดซื้อ'],
                ],
            ],
            [
                'key' => 'money',
                'label' => 'MONEY',
                'title' => 'เงิน ภาษี และบัญชี',
                'icon' => 'bi-cash-coin',
                'tone' => 'red',
                'summary' => 'รับเงิน จ่ายเงิน ลูกหนี้ เจ้าหนี้ ธนาคาร เช็ค VAT และ GL ต้องอ้างอิงเอกสารต้นทางและตรวจสอบย้อนกลับได้',
                'programs' => [
                    ['POS และขายสด', 'pos.index', 'สินค้า ราคา ส่วนลด และการชำระ', 'เงินรับ ใบเสร็จ VAT และ GL'],
                    ['ขายเชื่อ/ลูกหนี้', 'bookings.index', 'ลูกค้า วงเงิน และสินค้า', 'ใบขาย AR และกำหนดชำระ'],
                    ['วางบิล/รับชำระ', 'billing-notes.index', 'รายการลูกหนี้คงค้าง', 'ใบวางบิล ใบเสร็จ และตัด AR'],
                    ['ซื้อ/เจ้าหนี้', 'purchases.index', 'ผู้ขาย สินค้า และเงื่อนไขเครดิต', 'สต็อก AP ภาษีซื้อ และ GL'],
                    ['เงินสด ธนาคาร เช็ค', 'bplus.finance', 'รายการรับจ่ายและเอกสารอ้างอิง', 'กระแสเงินสดและยอดคงเหลือ'],
                    ['GL และงบการเงิน', 'chart-of-accounts.index', 'รายการบัญชีจากทุกวงจร', 'งบทดลอง งบกำไรขาดทุน งบดุล'],
                    ['งวดบัญชี', 'accounting-periods.index', 'ช่วงวันที่และขอบเขตสาขา', 'ล็อกเอกสารย้อนหลังและหลักฐานผู้ปิดงวด'],
                    ['ปิดบัญชีรายเดือน', 'monthly-accounting.index', 'Statement สลิป ค่าใช้จ่าย VAT และ WHT', 'ชุด ZIP ส่งสำนักงานบัญชีพร้อม checksum'],
                    ['ภาษี', 'bplus.tax', 'ภาษีซื้อ/ขายและเอกสารภาษี', 'รายงาน VAT และยอดยื่นภาษี'],
                    ['ทรัพย์สินถาวร', 'fixed-assets.index', 'ข้อมูลทรัพย์สินและอายุใช้งาน', 'ค่าเสื่อมและมูลค่าคงเหลือ'],
                ],
            ],
            [
                'key' => 'material',
                'label' => 'MATERIAL',
                'title' => 'สินค้า คลัง ซื้อ และผลิต',
                'icon' => 'bi-box-seam-fill',
                'tone' => 'blue',
                'summary' => 'สินค้า หน่วย บาร์โค้ด ราคา Lot ต้นทุน คลัง โอน ตรวจนับ จัดซื้อ และผลิตต้องใช้ master เดียวกันทุกสาขา',
                'programs' => [
                    ['สินค้าและบาร์โค้ด', 'products.index', 'รหัส หน่วย ภาษี ราคา และนโยบายสต็อก', 'สินค้าอ้างอิงกลางทุกเอกสาร'],
                    ['ตารางราคา/เครื่องชั่ง', 'price-tables.index', 'ราคาขายตามหน่วยและ PLU', 'ราคา POS ป้าย และเครื่องชั่ง'],
                    ['คลังและตำแหน่งเก็บ', 'warehouse-locations.index', 'คลัง สาขา และ location', 'จุดรับเข้า/จ่ายออกที่ชัดเจน'],
                    ['โอนและปรับสต็อก', 'stock-transfers.index', 'ต้นทาง ปลายทาง และรายการสินค้า', 'movement และยอดคงเหลือสองฝั่ง'],
                    ['ตรวจนับสินค้า', 'stock-counts.index', 'ยอดระบบและยอดนับจริง', 'ผลต่างและใบปรับยอด'],
                    ['ขอซื้อ/สั่งซื้อ', 'purchase-orders.index', 'ความต้องการ สต็อกต่ำ และผู้ขาย', 'PO และงานรับสินค้า'],
                    ['รับสินค้าเข้า', 'purchases.index', 'PO/ใบส่งของผู้ขาย', 'Stock lot ต้นทุน AP และ VAT'],
                    ['คุณภาพและ Trace Lot', 'products.index', 'Hold กักกัน เรียกคืน วันผลิต และหมดอายุ', 'ระงับการขายและค้นเอกสารปลายทางย้อนหลัง'],
                    ['ผลิตและแปรรูป', 'production.index', 'สูตร วัตถุดิบ และใบสั่งผลิต', 'ตัดวัตถุดิบและรับสินค้าสำเร็จรูป'],
                    ['จัดเซ็ตแบบชั่งจริง', 'stock-transforms.index', 'วัตถุดิบที่ใช้จริง น้ำหนักผลผลิต และ PLU', 'Batch Yield ต้นทุน/กก. และป้ายถุงขาย'],
                ],
            ],
            [
                'key' => 'management',
                'label' => 'MANAGEMENT',
                'title' => 'ควบคุม อนุมัติ และตัดสินใจ',
                'icon' => 'bi-diagram-3-fill',
                'tone' => 'amber',
                'summary' => 'ผู้บริหารเห็นสถานะจริงจากเอกสารต้นทาง คุมสิทธิ์ อนุมัติ ตรวจข้อผิดพลาด และเชื่อมข้อมูลภายนอกโดยไม่บันทึกซ้ำ',
                'programs' => [
                    ['Dashboard', 'dashboard', 'ยอดขาย เงิน สต็อก และงานค้าง', 'ภาพรวมกิจการรายวัน'],
                    ['อนุมัติเอกสาร', 'bplus.approvals', 'คำขอ วงเงิน และผู้รับผิดชอบ', 'ผลอนุมัติพร้อมหลักฐาน'],
                    ['ศูนย์รวมรายงาน', 'reports.index', 'ข้อมูลธุรกรรมทุกโมดูล', 'รายงานควบคุมและไฟล์ส่งออก'],
                    ['เอกสารย้อนหลัง', 'documents.browser', 'เอกสาร ERP และ BPlus เดิม', 'ค้นหา ตรวจ และพิมพ์ซ้ำ'],
                    ['นำเข้า POS', 'pos-import.page', 'แฟ้มยอดขายจากเครื่อง/สาขา', 'staging ตรวจสอบ และ posting'],
                    ['LINE และ E-Commerce', 'line-integrations.index', 'เหตุการณ์และช่องทางออนไลน์', 'แจ้งเตือนและงานเชื่อมต่อ'],
                    ['ตั้งค่าระบบ', 'settings.index', 'บริษัท เลขเอกสาร เมนู และบัญชีเริ่มต้น', 'กติกากลางของ ERP'],
                    ['คู่มือ PopStar 4M', 'core-modules.index', 'ขั้นตอน มาตรฐาน และสถานะระบบ', 'วิธีทำงานเดียวกันทั้งองค์กร'],
                ],
            ],
        ];
    }

    private function workflows(): array
    {
        return [
            [
                'key' => 'pos', 'label' => 'POS รายวัน', 'owner' => 'แคชเชียร์ / ผู้จัดการสาขา',
                'goal' => 'ขาย รับเงิน ตัดสต็อก บันทึก VAT/GL และปิดกะให้ยอดเงินจริงตรงระบบ',
                'steps' => [
                    ['เปิดกะ', 'pos.index', 'ระบุเงินทอนต้นกะและผู้ขาย'],
                    ['ขายสินค้า', 'pos.index', 'อ่านราคา โปรโมชั่น สมาชิก และตรวจสต็อก'],
                    ['รับชำระ', 'pos.index', 'เงินสด QR โอน บัตร เช็ค หรือจ่ายผสม'],
                    ['ออกบิล', 'bplus.pos-workbench', 'สร้างใบเสร็จ เอกสารขาย stock movement VAT และ GL'],
                    ['คืน/ยกเลิก', 'bplus.pos-workbench', 'อ้างอิงบิลเดิม กลับสต็อก เงิน และบัญชี'],
                    ['ปิดกะ', 'reports.index', 'เทียบเงินนับจริงกับยอดตามช่องทางชำระ'],
                ],
            ],
            [
                'key' => 'sales', 'label' => 'ขายเชื่อ / AR', 'owner' => 'ฝ่ายขาย / การเงิน',
                'goal' => 'เริ่มจากข้อเสนอจนรับเงินครบ โดยยอดลูกหนี้ตรงกับเอกสารและบัญชี',
                'steps' => [
                    ['เสนอราคา', 'quotations.index', 'กำหนดสินค้า ราคา และเงื่อนไข'],
                    ['จอง/ขายเชื่อ', 'bookings.index', 'ยืนยันลูกค้า สาขา ผู้ขาย และตัดสต็อก'],
                    ['ส่งของ/ภาษี', 'documents.browser', 'ออกเอกสารส่งมอบและภาษีจากรายการเดียวกัน'],
                    ['วางบิล', 'billing-notes.index', 'รวม open item ที่ครบกำหนด'],
                    ['รับชำระ', 'customers.index', 'จัดสรรเงิน ใบลดหนี้ และ WHT เข้ารายการหนี้'],
                    ['ตรวจ AR', 'reports.index', 'ดู aging ยอดค้าง และลูกหนี้เกินกำหนด'],
                ],
            ],
            [
                'key' => 'purchase', 'label' => 'จัดซื้อ / AP', 'owner' => 'จัดซื้อ / คลัง / การเงิน',
                'goal' => 'ซื้อเท่าที่ต้องใช้ รับของครบ ต้นทุนถูก และชำระเจ้าหนี้ตามกำหนด',
                'steps' => [
                    ['วางแผนซื้อ', 'bplus.purchase-planning', 'ใช้สต็อกต่ำ ยอดขาย และเป้าหมายสต็อก'],
                    ['ขอซื้อ/อนุมัติ', 'purchase-orders.index', 'ระบุรายการ จำนวน เหตุผล และวงเงิน'],
                    ['สั่งซื้อ', 'purchase-orders.index', 'ยืนยันผู้ขาย ราคา วันส่ง และเงื่อนไข'],
                    ['รับสินค้า', 'purchases.index', 'รับตาม PO สร้าง Lot ต้นทุน สต็อก และ AP'],
                    ['ตรวจคุณภาพ Lot', 'products.index', 'พักตรวจ/กักกัน/เรียกคืนพร้อมเหตุผลและ Audit Log'],
                    ['ตรวจเอกสาร', 'suppliers.index', 'เทียบ PO ใบรับ และใบกำกับผู้ขาย'],
                    ['ชำระ AP', 'suppliers.index', 'จ่ายเงิน ตัดหนี้ และลง GL/ธนาคาร'],
                ],
            ],
            [
                'key' => 'stock', 'label' => 'คลังหลายสาขา', 'owner' => 'คลัง / ผู้จัดการสาขา',
                'goal' => 'รู้ว่าสินค้าอยู่ที่ไหน จำนวนเท่าไร Lot ใดควรออกก่อน และทุกการเปลี่ยนยอดมีเอกสารอ้างอิง',
                'steps' => [
                    ['ขอโอน', 'stock-transfers.request', 'สาขาปลายทางแจ้งความต้องการ'],
                    ['อนุมัติ/โอนออก', 'stock-transfers.index', 'ต้นทางจัดสินค้าและลด stock available'],
                    ['สินค้าระหว่างทาง', 'stock-transfers.index', 'ติดตามของที่ส่งแต่ยังไม่รับ'],
                    ['รับโอน', 'stock-transfers.index', 'ปลายทางตรวจจำนวนและเพิ่มสต็อก'],
                    ['ตรวจนับ', 'stock-counts.index', 'สแกนยอดจริงและส่งให้หัวหน้าตรวจ'],
                    ['ปรับยอด', 'stock-adjustments.index', 'ลงผลต่างพร้อมเหตุผลและ audit trail'],
                    ['คุมหมดอายุ', 'products.index', 'เปิดควบคุม Lot กำหนดวันเตือน และเลือกห้ามหรืออนุญาต Lot หมดอายุรายสินค้า'],
                    ['ตรวจ FEFO', 'reports.index', 'ดู Lot หมดอายุ/ใกล้หมดอายุจากกระดิ่งและรายงาน แล้วระบายหรือตัดชำรุด'],
                    ['Trace/Recall', 'products.index', 'เปิด Lot เพื่อตรวจเส้นทางเอกสารและระงับ Lot ที่มีปัญหา'],
                ],
            ],
            [
                'key' => 'production', 'label' => 'ผลิต / แปรรูป', 'owner' => 'ผลิต / คลัง / ต้นทุน',
                'goal' => 'ตัดวัตถุดิบและ Lot ตามที่ใช้จริง รับน้ำหนักผลผลิตจริง และเห็น Yield ต้นทุน/กก. กำไร และป้ายขาย',
                'steps' => [
                    ['ตั้งสูตร', 'production.index', 'กำหนดผลผลิต วัตถุดิบ และอัตราใช้'],
                    ['ชั่งวัตถุดิบ', 'stock-transforms.index', 'เลือกหมู/ของสดและกรอกน้ำหนักที่ใช้จริง'],
                    ['ชั่งผลผลิต', 'stock-transforms.index', 'กรอกน้ำหนักชุดสำเร็จจริง ระบบคำนวณ Yield และสูญเสีย'],
                    ['รับ Batch', 'stock-transforms.index', 'ตัด FIFO Lot และรับสินค้าชุดด้วยต้นทุนต่อกิโลที่คำนวณแล้ว'],
                    ['แบ่งถุง/ป้าย', 'stock-transforms.index', 'กรอกน้ำหนักแต่ละถุงและสร้างบาร์โค้ด PLU ที่ POS อ่านได้'],
                    ['ตรวจต้นทุน', 'stock-transforms.index', 'เทียบต้นทุน ราคาก่อน VAT กำไรต่อกิโล และ Margin ของ Batch'],
                ],
            ],
            [
                'key' => 'finance', 'label' => 'บัญชี / ปิดรอบ', 'owner' => 'บัญชี / การเงิน / ผู้บริหาร',
                'goal' => 'รายการจากขาย ซื้อ รับจ่าย และสต็อกลงบัญชีสมดุลก่อนออกรายงาน',
                'steps' => [
                    ['ตั้งผังบัญชี', 'chart-of-accounts.index', 'ผูกบทบาทเงินสด AR AP รายได้ VAT สต็อก และ COGS'],
                    ['ตรวจ posting', 'gl-journals.index', 'ตรวจคู่เดบิตเครดิตจากเอกสารต้นทาง'],
                    ['กระทบธนาคาร', 'bank-accounts.index', 'เทียบ statement เช็ค QR และเงินโอน'],
                    ['ตรวจภาษี', 'bplus.tax', 'เทียบ VAT ซื้อ/ขายกับเอกสารภาษี'],
                    ['ตรวจคงค้าง', 'reports.index', 'AR AP เช็คค้าง สต็อกติดลบ และเอกสารไม่ลง GL'],
                    ['ปิดงวด', 'accounting-periods.index', 'ล็อก Document และ GL พร้อม Audit Log'],
                ],
            ],
            [
                'key' => 'management', 'label' => 'บริหาร / อนุมัติ', 'owner' => 'หัวหน้างาน / ผู้บริหาร',
                'goal' => 'คุมข้อยกเว้นและตัดสินใจจากข้อมูลจริง ไม่แก้ข้อมูลธุรกรรมโดยไม่มีหลักฐาน',
                'steps' => [
                    ['ดู Dashboard', 'dashboard', 'ตรวจยอดขาย เงินสด สต็อก และงานค้าง'],
                    ['ตรวจแจ้งเตือน', 'reports.index', 'สต็อกต่ำ Lot ใกล้หมดอายุ หนี้เกินกำหนด และ import ผิดพลาด'],
                    ['อนุมัติ', 'bplus.approvals', 'พิจารณาวงเงิน ส่วนลด โอน และปรับยอด'],
                    ['ติดตามเอกสาร', 'documents.browser', 'เปิดเอกสารต้นทางและประวัติย้อนหลัง'],
                    ['วิเคราะห์', 'reports.index', 'เทียบสาขา สินค้า พนักงาน และกำไรขั้นต้น'],
                    ['ปรับมาตรฐาน', 'core-modules.index', 'อัปเดตขั้นตอน ผู้รับผิดชอบ และ control point'],
                ],
            ],
            [
                'key' => 'integration', 'label' => 'ข้อมูล / เชื่อมต่อ', 'owner' => 'IT / Data / ผู้ดูแลระบบ',
                'goal' => 'ข้อมูลระหว่าง POS BPlus ช่องทางออนไลน์ และ ERP ไม่ซ้ำ ไม่หาย และตรวจย้อนกลับได้',
                'steps' => [
                    ['เตรียม master', 'bplus.pos-preparation', 'ส่งสินค้า ราคา โปรโมชั่น และสมาชิก'],
                    ['รับแฟ้ม POS', 'pos-import.page', 'นำเข้า staging โดยยังไม่กระทบบัญชี'],
                    ['ตรวจ validation', 'pos-import.page', 'แก้รหัสไม่ครบ ยอดไม่ตรง และข้อมูลซ้ำ'],
                    ['ยืนยัน posting', 'pos-import.page', 'สร้างเอกสารขาย ชำระ และ stock movement'],
                    ['แจ้งสถานะ', 'line-integrations.index', 'ส่งผลสำเร็จ/ผิดพลาดให้ผู้รับผิดชอบ'],
                    ['ตรวจ audit', 'reports.index', 'ตรวจ batch log เอกสาร และยอดปลายทาง'],
                ],
            ],
        ];
    }

    private function gaps(): array
    {
        return [
            ['level' => 'critical', 'status' => 'พร้อมใช้', 'title' => 'ราคา โปรโมชั่น และส่วนลด POS ยืนยันฝั่ง Server', 'detail' => 'checkout คำนวณราคาหลัก โปรโมชั่น บัตร ส่วนลด แต้ม และราคาต่ำกว่าทุนซ้ำฝั่ง Server พร้อมสิทธิ์ผู้อนุมัติ'],
            ['level' => 'critical', 'status' => 'มีชุดหลัก', 'title' => 'Integration tests ธุรกรรมครบวงจร', 'detail' => 'ทดสอบซื้อ ต้นทุน VAT ขาย Stock FEFO กักกัน Batch และ rollback บนฐานข้อมูลทดสอบแล้ว ต้องขยายต่อเมื่อเพิ่มโมดูล'],
            ['level' => 'control', 'status' => 'พร้อมใช้', 'title' => 'Approval เชื่อมเอกสารจริง', 'detail' => 'PO ปรับยอดสต๊อก วงเงินเครดิตลูกค้า Payroll และงบประมาณ แยกสิทธิ์ผู้อนุมัติและห้ามผู้ขออนุมัติตนเองครบ ส่วนลด POS/ขายต่ำกว่าทุนแยกสิทธิ์แล้ว'],
            ['level' => 'control', 'status' => 'พร้อมใช้', 'title' => 'Security, MFA และ Audit การเข้าสู่ระบบ', 'detail' => 'มี MFA แบบ TOTP, login throttling, session regeneration, encrypted secret และศูนย์ตรวจสถานะ; ยังต้องเปิด MFA ให้ผู้ใช้สำคัญทุกคน'],
            ['level' => 'control', 'status' => 'พร้อมใช้', 'title' => 'Backup, restore drill และ disaster recovery', 'detail' => 'มี backup/checksum/retention, ศูนย์ตรวจจากหน้าเว็บ, health gate ก่อนปิดงวด และ restore verification; production ต้องตั้ง offsite disk และ scheduler'],
            ['level' => 'growth', 'status' => 'ทะเบียนแล้ว', 'title' => 'E-Commerce sync อัตโนมัติ', 'detail' => 'มีแฟ้มช่องทาง Lazada Shopee LINE MyShop และ TikTok Shop แต่ยังไม่มี order/stock sync จริง'],
            ['level' => 'growth', 'status' => 'พร้อมใช้', 'title' => 'Payroll และเวลาเข้างาน', 'detail' => 'บันทึกเวลาเข้างาน คำนวณเงินเดือน OT ขาดงาน ประกันสังคม กรอกภาษีหัก ณ ที่จ่าย อนุมัติ จ่าย และพิมพ์สลิปได้ครบวงจร (ผู้จัดทำอนุมัติเองไม่ได้)'],
            ['level' => 'growth', 'status' => 'พร้อมใช้', 'title' => 'งบประมาณและศูนย์ต้นทุน', 'detail' => 'ตั้ง Cost Center และงบประมาณรายเดือน/บัญชี อนุมัติงบ และดูรายงานเทียบงบ vs ค่าใช้จ่ายจริง (variance) ต่อ Cost Center ได้'],
        ];
    }

    private function routines(): array
    {
        return [
            ['period' => 'ทุกวัน', 'owner' => 'สาขา', 'items' => ['เปิด/ปิดกะครบ', 'เงินสดและยอดโอนตรง', 'ไม่มีบิลค้างหรือ stock ติดลบผิดปกติ']],
            ['period' => 'ทุกวัน', 'owner' => 'คลัง', 'items' => ['รับ/จ่าย/โอนมีเอกสาร', 'รายการระหว่างทางไม่ค้างเกินกำหนด', 'Lot และวันหมดอายุถูกต้อง']],
            ['period' => 'ทุกสัปดาห์', 'owner' => 'จัดซื้อ/ขาย', 'items' => ['ทบทวนของขาดและของค้าง', 'ติดตาม PO/AR/AP เกินกำหนด', 'ตรวจราคาและโปรโมชั่นที่จะเริ่ม/หมดอายุ']],
            ['period' => 'สิ้นเดือน', 'owner' => 'บัญชี', 'items' => ['กระทบธนาคาร', 'ตรวจ VAT/GL ไม่ดุล', 'ตรวจ AR/AP/สต็อกและปิดงวด']],
            ['period' => 'สิ้นเดือน', 'owner' => 'ผู้บริหาร', 'items' => ['กำไรขั้นต้นตามสาขา', 'ของเสียและผลต่างสต็อก', 'ยอดขาย/เงินสด/หนี้และแผนเดือนถัดไป']],
            ['period' => 'รายไตรมาส', 'owner' => 'IT', 'items' => ['ทดสอบ restore backup', 'ทบทวนสิทธิ์ผู้ใช้', 'ตรวจ integration log และอัปเดตคู่มือ']],
        ];
    }
}
