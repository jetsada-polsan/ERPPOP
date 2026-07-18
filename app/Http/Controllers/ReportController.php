<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $catalog = $this->catalogForUser($this->catalog());
        if (empty($catalog)) {
            abort(403, 'ไม่มีสิทธิ์ดูรายงาน');
        }

        $category = $request->input('category', 'sales');
        if (! isset($catalog[$category])) {
            $category = array_key_first($catalog);
        }

        $report = $request->input('report', array_key_first($catalog[$category]['reports']));
        if (! isset($catalog[$category]['reports'][$report])) {
            $report = array_key_first($catalog[$category]['reports']);
        }

        // ค่าเริ่มต้น = วันนี้ (งานหลักคือเช็ครายวัน) - เลือกช่วงย้อนหลังเองได้
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->startOfDay();
        $perPage = (int) $request->input('per_page', 25);
        if (! in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 25;
        }
        // ผู้ใช้ที่สังกัดสาขา -> ดีฟอลต์กรองเฉพาะสาขาตัวเอง (คลังใครคลังมัน)
        // เลือก 'all' เพื่อดูทุกสาขา; ผู้บริหาร/ส่วนกลาง (ไม่มีสาขา) เห็นทุกสาขาปกติ
        $requestedBranch = $request->input('branch_id');
        if ($requestedBranch === null && ($userBranch = auth()->user()?->branch_id)) {
            $requestedBranch = (string) $userBranch;
        } elseif ($requestedBranch === 'all') {
            $requestedBranch = null;
        }
        $filters = [
            'branch_id' => $requestedBranch,
            'q' => trim((string) $request->input('q', '')),
            'per_page' => $perPage,
        ];

        return view('reports.index', [
            'catalog' => $catalog,
            'selectedCategory' => $category,
            'selectedReport' => $report,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'filters' => $filters,
            'perPage' => $perPage,
            'branches' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name_th']),
            'result' => $this->runReport($category, $report, $from, $to, $filters),
        ]);
    }

    private function catalogForUser(array $catalog): array
    {
        $visible = [];
        foreach ($catalog as $category => $group) {
            $reports = [];
            foreach ($group['reports'] as $report => $label) {
                if ($this->canSeeReport($category, $report)) {
                    $reports[$report] = $label;
                }
            }

            if ($reports !== []) {
                $group['reports'] = $reports;
                $visible[$category] = $group;
            }
        }

        return $visible;
    }

    private function canSeeReport(string $category, string $report): bool
    {
        $user = auth()->user();
        if (! $user) {
            return true;
        }

        if ($user->hasPermission('settings.manage') || $user->hasPermission('users.manage')) {
            return true;
        }

        $profitReports = [
            'gross_margin',
            'loss_sales',
            'loss_sales_6m',
            'loss_sales_6m_by_type',
            'loss_sales_6m_by_brand',
            'loss_sales_6m_by_category',
            'loss_sales_6m_by_supplier',
            'loss_price_table',
            'loss_sales_documents_summary',
            'loss_sales_documents_detail',
        ];

        if (in_array($report, $profitReports, true)) {
            return $user->hasPermission('finance.manage');
        }

        return match ($category) {
            'sales', 'documents', 'pos' => $user->hasPermission('sales.manage'),
            'management' => $user->hasPermission('finance.manage'),
            'ar', 'payment', 'tax' => $user->hasPermission('finance.manage'),
            'inventory', 'transfer' => $user->hasPermission('stock.manage'),
            'purchasing' => $user->hasPermission('purchasing.manage'),
            'audit' => $user->hasPermission('settings.manage') || $user->hasPermission('users.manage'),
            default => $user->hasPermission('reports.view'),
        };
    }

    private function catalog(): array
    {
        return [
            'sales' => [
                'title' => 'ขาย',
                'icon' => 'bi-receipt-cutoff',
                'reports' => [
                    'daily_sales' => 'ยอดขายรายวัน',
                    'sales_by_branch' => 'ยอดขายตามสาขา',
                    'sales_by_staff' => 'ยอดขายตามพนักงาน/แคชเชียร์',
                    'sales_by_category' => 'ยอดขายตามหมวดสินค้า',
                    'sales_by_seller' => 'ยอดขายตามคนขาย',
                    'sales_by_category_seller' => 'ยอดขายตามหมวดสินค้า / คนขาย',
                    'top_products' => 'สินค้าขายดี',
                    'products_by_branch' => 'สินค้าขายตามสาขา',
                    'credit_sales' => 'ใบขายเชื่อ',
                    'pending_bookings' => 'ใบจองค้างแปลงขาย',
                    'sales_by_booking' => 'ยอดขายตามใบจอง',
                    'sales_returns_by_document' => 'ใบขาย-รับคืน ตามเอกสาร',
                    'bplus_sales_return_by_document' => 'รายงานรับจ่าย-รับคืนสินค้า ตามเอกสาร',
                    'sale_return_by_product' => 'สรุปขาย-รับคืน ตามสินค้า',
                    'bplus_sale_return_by_product' => 'รายงานสรุปการขาย-รับคืนตามสินค้า',
                    'sales_summary_by_customer' => 'รายงานสรุปยอดขายตามลูกค้า',
                    'sales_summary_12m_customer' => 'รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้',
                    'sales_summary_12m_customer_product' => 'รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้-สินค้า',
                    'sales_summary_12m_category' => 'รายงานสรุปยอดขาย 12 เดือน ตามหมวดสินค้า',
                    'sales_summary_12m_salesman_product' => 'รายงานสรุปยอดขาย 12 เดือน ตามพนักงานขาย-สินค้า',
                ],
            ],
            'management' => [
                'title' => 'ผู้บริหาร',
                'icon' => 'bi-graph-up-arrow',
                'reports' => [
                    'gross_margin' => 'กำไรขั้นต้นเบื้องต้น',
                    'loss_sales' => 'สินค้าขายต่ำกว่าทุน / ขาดทุน',
                    'loss_sales_6m' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน',
                    'loss_sales_6m_by_type' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามประเภทสินค้า',
                    'loss_sales_6m_by_brand' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามยี่ห้อสินค้า',
                    'loss_sales_6m_by_category' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามหมวดสินค้า',
                    'loss_sales_6m_by_supplier' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามผู้จำหน่ายหลัก',
                    'loss_price_table' => 'รายงานราคาขายต่ำกว่าทุนตามตารางราคา',
                    'loss_sales_documents_summary' => 'รายงานสรุปเอกสารขายที่ขาดทุน',
                    'loss_sales_documents_detail' => 'รายงานรายละเอียดเอกสารขายที่ขาดทุน',
                ],
            ],
            'ar' => [
                'title' => 'ลูกหนี้',
                'icon' => 'bi-person-lines-fill',
                'reports' => [
                    'ar_summary' => 'สรุปยอดลูกหนี้',
                    'ar_summary_bplus' => 'รายงานสรุปยอดลูกหนี้',
                    'ar_aging' => 'อายุหนี้ AR Aging',
                    'overdue_customers' => 'ลูกหนี้เกินกำหนด',
                    'open_items' => 'ลูกหนี้คงค้าง',
                    'ar_detail_short' => 'รายงานรายละเอียดยอดลูกหนี้ แบบย่อ',
                    'ar_detail_full' => 'รายงานรายละเอียดยอดลูกหนี้ แบบละเอียด',
                    'ar_overdue_detail' => 'รายงานรายละเอียดลูกหนี้เกินกำหนดชำระ',
                    'ar_over_credit_limit' => 'รายงานรายละเอียดลูกหนี้เกินวงเงินเครดิต',
                ],
            ],
            'inventory' => [
                'title' => 'สินค้าและสต็อก',
                'icon' => 'bi-box-seam-fill',
                'reports' => [
                    'stock_balance' => 'สินค้าคงเหลือ',
                    'stock_by_branch' => 'สต็อกตามสาขา',
                    'stock_alerts' => 'สต็อกต่ำ / ติดลบ',
                    'stock_movements' => 'เคลื่อนไหวสินค้า',
                ],
            ],
            'documents' => [
                'title' => 'เอกสาร',
                'icon' => 'bi-files',
                'reports' => [
                    'documents_summary' => 'สรุปเอกสาร',
                    'document_list' => 'รายการเอกสารทั้งหมด',
                    'document_items' => 'รายการสินค้าในเอกสาร',
                    'booking_documents' => 'ใบจอง',
                    'cash_sale_documents' => 'ใบขายสด',
                    'credit_sale_documents' => 'ใบขายเชื่อ',
                    'sale_return_documents' => 'ใบรับคืนสินค้า',
                    'receipt_documents' => 'ใบเสร็จรับเงิน',
                ],
            ],
            'pos' => [
                'title' => 'POS',
                'icon' => 'bi-cart-check-fill',
                'reports' => [
                    'pos_receipts' => 'ใบเสร็จ POS',
                    'pos_by_terminal' => 'ยอดขายตามเครื่อง POS',
                    'pos_payments' => 'รับชำระตามช่องทาง',
                    'pos_hourly' => 'ยอดขายรายชั่วโมง',
                    'pos_tax_discount' => 'ภาษี / ส่วนลด POS',
                ],
            ],
            'purchasing' => [
                'title' => 'ซื้อสินค้า',
                'icon' => 'bi-basket-fill',
                'reports' => [
                    'purchase_documents' => 'เอกสารซื้อสินค้า',
                    'purchase_by_supplier' => 'ยอดซื้อตามผู้ขาย',
                    'purchase_items' => 'รับสินค้าเข้าตามสินค้า',
                ],
            ],
            'transfer' => [
                'title' => 'โอนสินค้า',
                'icon' => 'bi-arrow-left-right',
                'reports' => [
                    'stock_transfers' => 'เอกสารโอนสินค้า',
                    'transfer_items' => 'รายการสินค้าโอน',
                    'transfer_by_location' => 'ยอดโอนตามคลังต้นทาง/ปลายทาง',
                ],
            ],
            'payment' => [
                'title' => 'รับชำระ / การเงิน',
                'icon' => 'bi-cash-coin',
                'reports' => [
                    'payment_documents' => 'เอกสารรับชำระ',
                    'payment_allocations' => 'ตัดหนี้ / จัดสรรยอด',
                    'gl_journals' => 'GL Journal',
                ],
            ],
            'tax' => [
                'title' => 'ภาษี (ภพ.30)',
                'icon' => 'bi-receipt',
                'reports' => [
                    'vat_sales' => 'รายงานภาษีขาย',
                    'vat_purchase' => 'รายงานภาษีซื้อ',
                ],
            ],
            'audit' => [
                'title' => 'ตรวจสอบระบบ',
                'icon' => 'bi-shield-check',
                'reports' => [
                    'import_batches' => 'Import / Sync status',
                    'import_errors' => 'Import errors',
                    'import_error_summary' => 'สรุป Import errors',
                    'void_bill_history' => 'ประวัติลบบิล / ยกเลิกบิลย้อนหลัง',
                    'deleted_bill_audit' => 'ตรวจสอบเอกสารที่ถูกยกเลิก',
                    'pending_work' => 'งานค้างต้องตาม',
                ],
            ],
        ];
    }

    private function runReport(string $category, string $report, Carbon $from, Carbon $to, array $filters): array
    {
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return match ($report) {
            'vat_sales' => $this->tableResult('รายงานภาษีขาย', [
                ['label' => 'วันที่ใบกำกับ', 'key' => 'doc_date'],
                ['label' => 'เลขที่ใบกำกับภาษี', 'key' => 'doc_number'],
                ['label' => 'ชื่อผู้ซื้อ', 'key' => 'party_name'],
                ['label' => 'เลขผู้เสียภาษี', 'key' => 'tax_id'],
                ['label' => 'มูลค่าฐานภาษี', 'key' => 'base_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ภาษีมูลค่าเพิ่ม', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'รวม', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->vatSales($fromStart, $toEnd, $filters)),

            'vat_purchase' => $this->tableResult('รายงานภาษีซื้อ', [
                ['label' => 'วันที่ใบกำกับ', 'key' => 'doc_date'],
                ['label' => 'เลขที่ใบกำกับภาษี', 'key' => 'doc_number'],
                ['label' => 'ชื่อผู้ขาย', 'key' => 'party_name'],
                ['label' => 'เลขผู้เสียภาษี', 'key' => 'tax_id'],
                ['label' => 'มูลค่าฐานภาษี', 'key' => 'base_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ภาษีมูลค่าเพิ่ม', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'รวม', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->vatPurchase($fromStart, $toEnd, $filters)),

            'daily_sales' => $this->tableResult('ยอดขายรายวัน', [
                ['label' => 'วันที่', 'key' => 'sale_date'],
                ['label' => 'ช่องทาง', 'key' => 'channel', 'type' => 'badge'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->dailySales($fromStart, $toEnd, $filters)),

            'sales_by_branch' => $this->tableResult('ยอดขายตามสาขา', [
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'บิล', 'key' => 'receipt_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByBranch($fromStart, $toEnd, $filters)),

            'sales_by_staff' => $this->tableResult('ยอดขายตามพนักงาน/แคชเชียร์', [
                ['label' => 'พนักงาน', 'key' => 'staff_name'],
                ['label' => 'ช่องทาง', 'key' => 'channel', 'type' => 'badge'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByStaff($fromStart, $toEnd, $filters)),

            'sales_by_category' => $this->tableResult('ยอดขายตามหมวดสินค้า', [
                ['label' => 'หมวดสินค้า', 'key' => 'category_name'],
                ['label' => 'ช่องทาง', 'key' => 'channel', 'type' => 'badge'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByCategory($fromStart, $toEnd, $filters)),

            'sales_by_seller' => $this->tableResult('ยอดขายตามคนขาย', [
                ['label' => 'คนขาย', 'key' => 'seller_name'],
                ['label' => 'ช่องทาง', 'key' => 'channel', 'type' => 'badge'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesBySeller($fromStart, $toEnd, $filters)),

            'sales_by_category_seller' => $this->tableResult('ยอดขายตามหมวดสินค้า / คนขาย', [
                ['label' => 'หมวดสินค้า', 'key' => 'category_name'],
                ['label' => 'คนขาย', 'key' => 'seller_name'],
                ['label' => 'ช่องทาง', 'key' => 'channel', 'type' => 'badge'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByCategorySeller($fromStart, $toEnd, $filters)),

            'top_products' => $this->tableResult('สินค้าขายดี', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->topProducts($fromStart, $toEnd, $filters)),

            'products_by_branch' => $this->tableResult('สินค้าขายตามสาขา', [
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->productsByBranch($fromStart, $toEnd, $filters)),

            'gross_margin' => $this->tableResult('กำไรขั้นต้นเบื้องต้น', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวนขาย', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'sales_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต้นทุนประมาณ', 'key' => 'cost_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'กำไรขั้นต้น', 'key' => 'gross_profit', 'type' => 'money', 'class' => 'text-end'],
            ], $this->grossMargin($fromStart, $toEnd, $filters)),

            'credit_sales' => $this->tableResult('ใบขายเชื่อ', [
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->creditSales($from, $to, $filters)),

            'pending_bookings' => $this->tableResult('ใบจองค้างแปลงขาย', [
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->pendingBookings($from, $to, $filters)),

            'sales_by_booking' => $this->tableResult('ยอดขายตามใบจอง', [
                ['label' => 'ใบจอง', 'key' => 'booking_no'],
                ['label' => 'วันที่จอง', 'key' => 'doc_date'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'ใบขายที่แปลง', 'key' => 'sale_doc_no'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByBooking($from, $to, $filters)),

            'sales_returns_by_document' => $this->tableResult('ใบขาย-รับคืน ตามเอกสาร', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'ประเภท', 'key' => 'document_type', 'type' => 'badge'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'ยอดเงิน (คืน = ลบ)', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesReturnsByDocument($from, $to, $filters)),

            'bplus_sales_return_by_document' => $this->tableResult('รายงานรับจ่าย-รับคืนสินค้า ตามเอกสาร', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'ประเภท', 'key' => 'document_type', 'type' => 'badge'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'ยอดเงิน (คืน = ลบ)', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesReturnsByDocument($from, $to, $filters)),

            'sale_return_by_product' => $this->tableResult('สรุปขาย-รับคืน ตามสินค้า', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวนขาย', 'key' => 'sold_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'sold_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'จำนวนคืน', 'key' => 'return_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดคืน', 'key' => 'return_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->saleReturnByProduct($from, $to, $filters)),

            'bplus_sale_return_by_product' => $this->tableResult('รายงานสรุปการขาย-รับคืนตามสินค้า', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวนขาย', 'key' => 'sold_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'sold_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'จำนวนคืน', 'key' => 'return_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดคืน', 'key' => 'return_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->saleReturnByProduct($from, $to, $filters)),

            'sales_summary_by_customer' => $this->tableResult('รายงานสรุปยอดขายตามลูกค้า', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'sales_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดคืน', 'key' => 'return_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesSummaryByCustomer($from, $to, $filters)),

            'sales_summary_12m_customer' => $this->tableResult('รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้', [
                ['label' => 'เดือน', 'key' => 'sale_month'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesSummary12Months($to, $filters, 'customer')),

            'sales_summary_12m_customer_product' => $this->tableResult('รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้-สินค้า', [
                ['label' => 'เดือน', 'key' => 'sale_month'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesSummary12Months($to, $filters, 'customer_product')),

            'sales_summary_12m_category' => $this->tableResult('รายงานสรุปยอดขาย 12 เดือน ตามหมวดสินค้า', [
                ['label' => 'เดือน', 'key' => 'sale_month'],
                ['label' => 'หมวดสินค้า', 'key' => 'category_name'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesSummary12Months($to, $filters, 'category')),

            'sales_summary_12m_salesman_product' => $this->tableResult('รายงานสรุปยอดขาย 12 เดือน ตามพนักงานขาย-สินค้า', [
                ['label' => 'เดือน', 'key' => 'sale_month'],
                ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesSummary12Months($to, $filters, 'salesman_product')),

            'loss_sales' => $this->tableResult('สินค้าขายต่ำกว่าทุน / ขาดทุน', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวน', 'key' => 'sold_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ราคาขาย', 'key' => 'unit_price', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต้นทุนเฉลี่ย', 'key' => 'avg_cost', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ขาดทุนรวม', 'key' => 'loss_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->lossSales($from, $to, $filters)),

            'loss_sales_6m' => $this->tableResult('รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน', [
                ['label' => 'เดือน', 'key' => 'sale_month'],
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวน', 'key' => 'sold_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'sales_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต้นทุน', 'key' => 'cost_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ขาดทุน', 'key' => 'loss_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->lossSalesSixMonths($to, $filters)),

            'loss_sales_6m_by_type' => $this->tableResult('รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามประเภทสินค้า', $this->lossGroupColumns('ประเภทสินค้า'), $this->lossSalesGroupedSixMonths($to, $filters, 'department')),
            'loss_sales_6m_by_brand' => $this->tableResult('รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามยี่ห้อสินค้า', $this->lossGroupColumns('ยี่ห้อสินค้า'), $this->lossSalesGroupedSixMonths($to, $filters, 'brand')),
            'loss_sales_6m_by_category' => $this->tableResult('รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามหมวดสินค้า', $this->lossGroupColumns('หมวดสินค้า'), $this->lossSalesGroupedSixMonths($to, $filters, 'category')),
            'loss_sales_6m_by_supplier' => $this->tableResult('รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามผู้จำหน่ายหลัก', $this->lossGroupColumns('ผู้จำหน่ายหลัก'), $this->lossSalesGroupedSixMonths($to, $filters, 'supplier')),
            'loss_price_table' => $this->tableResult('รายงานราคาขายต่ำกว่าทุนตามตารางราคา', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'ตารางราคา', 'key' => 'price_table_name'],
                ['label' => 'ราคาขาย', 'key' => 'price', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต้นทุน', 'key' => 'cost_price', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต่ำกว่าทุน', 'key' => 'loss_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->lossPriceTable($filters)),
            'loss_sales_documents_summary' => $this->tableResult('รายงานสรุปเอกสารขายที่ขาดทุน', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'รายการขาดทุน', 'key' => 'line_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ขาดทุนรวม', 'key' => 'loss_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->lossSalesDocuments($from, $to, $filters, true)),
            'loss_sales_documents_detail' => $this->tableResult('รายงานรายละเอียดเอกสารขายที่ขาดทุน', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวน', 'key' => 'sold_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ราคาขาย', 'key' => 'unit_price', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต้นทุน', 'key' => 'avg_cost', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ขาดทุนรวม', 'key' => 'loss_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->lossSalesDocuments($from, $to, $filters, false)),

            'ar_summary' => $this->tableResult('สรุปยอดลูกหนี้', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'ใบค้าง', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ครบกำหนดเก่าสุด', 'key' => 'oldest_due_date'],
                ['label' => 'ยอดเกินกำหนด', 'key' => 'overdue_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดค้างรวม', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arSummary($filters)),

            'ar_summary_bplus' => $this->tableResult('รายงานสรุปยอดลูกหนี้', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'ใบค้าง', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ครบกำหนดเก่าสุด', 'key' => 'oldest_due_date'],
                ['label' => 'ยอดเกินกำหนด', 'key' => 'overdue_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดค้างรวม', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arSummary($filters)),

            'ar_aging' => $this->tableResult('อายุหนี้ AR Aging', [
                ['label' => 'ช่วงอายุหนี้', 'key' => 'bucket'],
                ['label' => 'จำนวนเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arAging()),

            'overdue_customers' => $this->tableResult('ลูกหนี้เกินกำหนด', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ครบกำหนดเก่าสุด', 'key' => 'oldest_due_date'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->overdueCustomers($filters)),

            'open_items' => $this->tableResult('ลูกหนี้คงค้าง', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->openItems($filters)),

            'ar_detail_short' => $this->tableResult('รายงานรายละเอียดยอดลูกหนี้ แบบย่อ', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arDetails($filters, false)),

            'ar_detail_full' => $this->tableResult('รายงานรายละเอียดยอดลูกหนี้ แบบละเอียด', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'วันที่เอกสาร', 'key' => 'doc_date'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'รับแล้ว', 'key' => 'paid_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arDetails($filters, true)),

            'ar_overdue_detail' => $this->tableResult('รายงานรายละเอียดลูกหนี้เกินกำหนดชำระ', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date'],
                ['label' => 'เกินกำหนด', 'key' => 'overdue_days', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arOverdueDetails($filters)),

            'ar_over_credit_limit' => $this->tableResult('รายงานรายละเอียดลูกหนี้เกินวงเงินเครดิต', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'วงเงินเครดิต', 'key' => 'credit_limit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'เกินวงเงิน', 'key' => 'over_limit_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arOverCreditLimit($filters)),

            'stock_balance' => $this->tableResult('สินค้าคงเหลือ', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'คลัง/ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'คงเหลือ', 'key' => 'on_hand_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จอง', 'key' => 'reserved_qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->stockBalance($filters)),

            'stock_by_branch' => $this->tableResult('สต็อกตามสาขา', [
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'รายการสินค้า', 'key' => 'product_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'คงเหลือรวม', 'key' => 'on_hand_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จองรวม', 'key' => 'reserved_qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->stockByBranch($filters)),

            'stock_alerts' => $this->tableResult('สต็อกต่ำ / ติดลบ', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'คลัง/ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'คงเหลือ', 'key' => 'on_hand_qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->stockAlerts($filters)),

            'stock_movements' => $this->tableResult('เคลื่อนไหวสินค้า', [
                ['label' => 'วันที่', 'key' => 'movement_date'],
                ['label' => 'สินค้า', 'key' => 'product_name'],
                ['label' => 'ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'ประเภท', 'key' => 'movement_type', 'type' => 'badge'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->stockMovements($from, $to, $filters)),

            'documents_summary' => $this->tableResult('สรุปเอกสาร', [
                ['label' => 'ประเภทเอกสาร', 'key' => 'document_type'],
                ['label' => 'จำนวนเอกสาร', 'key' => 'document_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวนรายการ', 'key' => 'item_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->documentsSummary($from, $to, $filters)),

            'document_list' => $this->tableResult('รายการเอกสารทั้งหมด', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ประเภทเอกสาร', 'key' => 'document_type'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'คู่ค้า/ลูกค้า', 'key' => 'party_name'],
                ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->documentList($from, $to, $filters)),

            'document_items' => $this->tableResult('รายการสินค้าในเอกสาร', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ประเภทเอกสาร', 'key' => 'document_type'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'สินค้า', 'key' => 'product_name'],
                ['label' => 'คลัง/ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ราคา', 'key' => 'unit_price', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->documentItems($from, $to, $filters)),

            'booking_documents' => $this->tableResult('ใบจอง', $this->documentColumns(), $this->documentList($from, $to, $filters, ['BOOKING'])),
            'cash_sale_documents' => $this->tableResult('ใบขายสด', $this->documentColumns(), $this->documentList($from, $to, $filters, ['CASH_SALE'])),
            'credit_sale_documents' => $this->tableResult('ใบขายเชื่อ', $this->documentColumns(), $this->documentList($from, $to, $filters, ['CREDIT_SALE'])),
            'sale_return_documents' => $this->tableResult('ใบรับคืนสินค้า', $this->documentColumns(), $this->documentList($from, $to, $filters, ['SALE_RETURN'])),
            'receipt_documents' => $this->tableResult('ใบเสร็จรับเงิน', $this->documentColumns(), $this->documentList($from, $to, $filters, ['RECEIPT'])),

            'pos_receipts' => $this->tableResult('ใบเสร็จ POS', [
                ['label' => 'เลขที่', 'key' => 'receipt_no'],
                ['label' => 'วันที่', 'key' => 'receipt_date'],
                ['label' => 'เครื่อง', 'key' => 'terminal_name'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'ยอดขาย', 'key' => 'net_sales', 'type' => 'money', 'class' => 'text-end'],
            ], $this->posReceipts($fromStart, $toEnd, $filters)),

            'pos_by_terminal' => $this->tableResult('ยอดขายตามเครื่อง POS', [
                ['label' => 'เครื่อง', 'key' => 'terminal_name'],
                ['label' => 'บิล', 'key' => 'receipt_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->posByTerminal($fromStart, $toEnd, $filters)),

            'pos_payments' => $this->tableResult('รับชำระตามช่องทาง', [
                ['label' => 'ช่องทาง', 'key' => 'method'],
                ['label' => 'รายการ', 'key' => 'line_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->paymentsByMethod($fromStart, $toEnd, $filters)),

            'pos_hourly' => $this->tableResult('ยอดขายรายชั่วโมง', [
                ['label' => 'ชั่วโมงขาย', 'key' => 'sale_hour'],
                ['label' => 'บิล', 'key' => 'receipt_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->posHourly($fromStart, $toEnd, $filters)),

            'pos_tax_discount' => $this->tableResult('ภาษี / ส่วนลด POS', [
                ['label' => 'วันที่', 'key' => 'sale_date'],
                ['label' => 'บิล', 'key' => 'receipt_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดก่อนลด', 'key' => 'gross_sales', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ส่วนลด', 'key' => 'discount_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'VAT', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดสุทธิ', 'key' => 'net_sales', 'type' => 'money', 'class' => 'text-end'],
            ], $this->posTaxDiscount($fromStart, $toEnd, $filters)),

            'purchase_documents' => $this->tableResult('เอกสารซื้อสินค้า', [
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'ผู้ขาย', 'key' => 'supplier_name'],
                ['label' => 'รายการ', 'key' => 'total_items', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->purchaseDocuments($from, $to, $filters)),

            'purchase_by_supplier' => $this->tableResult('ยอดซื้อตามผู้ขาย', [
                ['label' => 'ผู้ขาย', 'key' => 'supplier_name'],
                ['label' => 'เอกสาร', 'key' => 'document_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'รายการ', 'key' => 'item_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->purchaseBySupplier($from, $to, $filters)),

            'purchase_items' => $this->tableResult('รับสินค้าเข้าตามสินค้า', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวนรับเข้า', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'มูลค่า', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->purchaseItems($from, $to, $filters)),

            'stock_transfers' => $this->tableResult('เอกสารโอนสินค้า', [
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'ต้นทาง', 'key' => 'from_location'],
                ['label' => 'ปลายทาง', 'key' => 'to_location'],
                ['label' => 'จำนวน', 'key' => 'total_qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->stockTransfers($from, $to, $filters)),

            'transfer_items' => $this->tableResult('รายการสินค้าโอน', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'สินค้า', 'key' => 'product_name'],
                ['label' => 'ต้นทาง', 'key' => 'from_location'],
                ['label' => 'ปลายทาง', 'key' => 'to_location'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->transferItems($from, $to, $filters)),

            'transfer_by_location' => $this->tableResult('ยอดโอนตามคลังต้นทาง/ปลายทาง', [
                ['label' => 'ต้นทาง', 'key' => 'from_location'],
                ['label' => 'ปลายทาง', 'key' => 'to_location'],
                ['label' => 'เอกสาร', 'key' => 'document_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวนสินค้า', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->transferByLocation($from, $to, $filters)),

            'payment_documents' => $this->tableResult('เอกสารรับชำระ', [
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ประเภทคู่ค้า', 'key' => 'party_type', 'type' => 'badge'],
                ['label' => 'คู่ค้า', 'key' => 'party_name'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
            ], $this->paymentDocuments($from, $to, $filters)),

            'payment_allocations' => $this->tableResult('ตัดหนี้ / จัดสรรยอด', [
                ['label' => 'เอกสารรับชำระ', 'key' => 'doc_number'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'ยอดจัดสรร', 'key' => 'allocated_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ส่วนลด', 'key' => 'discount_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'หัก ณ ที่จ่าย', 'key' => 'wht_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->paymentAllocations($from, $to, $filters)),

            'gl_journals' => $this->tableResult('GL Journal', [
                ['label' => 'วันที่', 'key' => 'entry_date'],
                ['label' => 'บัญชี', 'key' => 'account_name'],
                ['label' => 'เดบิต', 'key' => 'debit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'เครดิต', 'key' => 'credit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'หมายเหตุ', 'key' => 'remark'],
            ], $this->glJournals($from, $to, $filters)),

            'import_batches' => $this->tableResult('Import / Sync status', [
                ['label' => 'POS', 'key' => 'pos_code'],
                ['label' => 'วันที่ขาย', 'key' => 'sale_date'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'จำนวน', 'key' => 'record_count', 'type' => 'number', 'class' => 'text-end'],
            ], $this->importBatches($from, $to, $filters)),

            'import_errors' => $this->tableResult('Import errors', [
                ['label' => 'วันที่', 'key' => 'created_at'],
                ['label' => 'ใบเสร็จ', 'key' => 'receipt_no'],
                ['label' => 'ประเภท', 'key' => 'error_type', 'type' => 'badge'],
                ['label' => 'รายละเอียด', 'key' => 'error_message'],
            ], $this->importErrors($fromStart, $toEnd, $filters)),

            'import_error_summary' => $this->tableResult('สรุป Import errors', [
                ['label' => 'ประเภท', 'key' => 'error_type', 'type' => 'badge'],
                ['label' => 'จำนวน Error', 'key' => 'error_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวนบิล', 'key' => 'receipt_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ครั้งแรก', 'key' => 'first_seen'],
                ['label' => 'ล่าสุด', 'key' => 'last_seen'],
            ], $this->importErrorSummary($fromStart, $toEnd, $filters)),

            'void_bill_history' => $this->tableResult('ประวัติลบบิล / ยกเลิกบิลย้อนหลัง', [
                ['label' => 'วันที่', 'key' => 'cancelled_at'],
                ['label' => 'แหล่งที่มา', 'key' => 'source', 'type' => 'badge'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'ผู้ทำรายการ', 'key' => 'user_name'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'หมายเหตุ', 'key' => 'remark'],
            ], $this->voidBillHistory($fromStart, $toEnd, $filters)),

            'deleted_bill_audit' => $this->tableResult('ตรวจสอบเอกสารที่ถูกยกเลิก', [
                ['label' => 'วันที่', 'key' => 'cancelled_at'],
                ['label' => 'แหล่งที่มา', 'key' => 'source', 'type' => 'badge'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'ผู้ทำรายการ', 'key' => 'user_name'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'หมายเหตุ', 'key' => 'remark'],
            ], $this->voidBillHistory($fromStart, $toEnd, $filters)),

            'pending_work' => $this->tableResult('งานค้างต้องตาม', [
                ['label' => 'รายการ', 'key' => 'label'],
                ['label' => 'จำนวน', 'key' => 'count', 'type' => 'number', 'class' => 'text-end'],
            ], collect($this->pendingWork())->map(fn ($count, $label) => (object) compact('label', 'count'))),

            default => $this->tableResult('ไม่พบรายงาน', [], collect()),
        };
    }

    private function tableResult(string $title, array $columns, Collection $rows): array
    {
        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'total' => $rows->count(),
        ];
    }

    // สาขาตัวแทนต่อคลัง = สาขา active ที่ id ต่ำสุดที่ใช้คลังนี้เป็นคลังหลัก
    // กันนับซ้ำเมื่อหลายสาขาใช้คลังเดียวกัน (เจริญศรี-1/-2 ใช้คลังเจริญศรีร่วมกัน)
    private function branchByLocationSub(string $locationColumn): string
    {
        return "(select min(b2.id) from branches b2 where b2.default_warehouse_location_id = {$locationColumn} and b2.is_active = true)";
    }

    private function applyBranch($query, array $filters, string $column = 'b.id')
    {
        if (! empty($filters['branch_id'])) {
            $query->where($column, $filters['branch_id']);
        }

        return $query;
    }

    private function applySearch($query, array $filters, array $columns)
    {
        if ($filters['q'] === '') {
            return $query;
        }

        return $query->where(function ($query) use ($filters, $columns) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$filters['q'].'%');
            }
        });
    }

    // อัตรา VAT ปัจจุบันจากตาราง vat_rates (ไม่มี = 7%)
    private function vatRatePercent(): float
    {
        $rate = DB::table('vat_rates')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')
            ->value('rate_percent');

        return $rate !== null ? (float) $rate : 7.0;
    }

    /**
     * รายงานภาษีขาย (ภพ.30): ใบกำกับขายสด/ขายเชื่อ + บิล POS (ใบกำกับอย่างย่อ)
     * และใบรับคืน/ลดหนี้เป็นยอดติดลบ - ราคาในระบบถือเป็นราคารวม VAT
     */
    private function vatSales(Carbon $from, Carbon $to, array $filters): Collection
    {
        $rate = $this->vatRatePercent();

        $docQuery = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            ->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($docQuery, $filters, 'd.branch_id');
        $this->applySearch($docQuery, $filters, ['d.doc_number', 'c.name_th']);

        $docRows = $docQuery->selectRaw(
            "d.doc_date, d.doc_number, dt.code as doc_code,
             COALESCE(c.name_th, 'ลูกค้าทั่วไป') as party_name, c.tax_id, d.total_amount"
        )->get()->map(function ($r) use ($rate) {
            $sign = $r->doc_code === 'SALE_RETURN' ? -1 : 1;
            $total = $sign * (float) $r->total_amount;
            $base = round($total * 100 / (100 + $rate), 2);

            return [
                'doc_date' => \Illuminate\Support\Carbon::parse($r->doc_date)->thaiDate(),
                'sort_key' => $r->doc_date.'|'.$r->doc_number,
                'doc_number' => $r->doc_number,
                'party_name' => $r->party_name.($sign < 0 ? ' (รับคืน/ลดหนี้)' : ''),
                'tax_id' => $r->tax_id ?? '-',
                'base_amount' => $base,
                'vat_amount' => round($total - $base, 2),
                'total_amount' => $total,
            ];
        });

        $posQuery = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->whereBetween('r.receipt_date', [$from, $to])
            ->where('r.status', '!=', 'cancelled');
        $this->applyBranch($posQuery, $filters, 't.branch_id');
        $this->applySearch($posQuery, $filters, ['r.receipt_no']);

        $posRows = $posQuery->selectRaw('r.receipt_date, r.receipt_no, r.net_sales, r.vat_amount')
            ->get()->map(function ($r) use ($rate) {
                $total = (float) $r->net_sales;
                $vat = $r->vat_amount !== null && (float) $r->vat_amount > 0
                    ? (float) $r->vat_amount
                    : round($total - ($total * 100 / (100 + $rate)), 2);

                return [
                    'doc_date' => \Illuminate\Support\Carbon::parse($r->receipt_date)->thaiDate(),
                    'sort_key' => substr((string) $r->receipt_date, 0, 10).'|'.$r->receipt_no,
                    'doc_number' => $r->receipt_no,
                    'party_name' => 'ขายปลีก (POS - ใบกำกับอย่างย่อ)',
                    'tax_id' => '-',
                    'base_amount' => round($total - $vat, 2),
                    'vat_amount' => $vat,
                    'total_amount' => $total,
                ];
            });

        return $this->withVatTotals($docRows->concat($posRows));
    }

    // รายงานภาษีซื้อ (ภพ.30): ใบซื้อจากซัพพลายเออร์
    private function vatPurchase(Carbon $from, Carbon $to, array $filters): Collection
    {
        $rate = $this->vatRatePercent();

        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->where('dt.code', 'PURCHASE')
            ->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 's.name_th']);

        $rows = $query->selectRaw(
            "d.doc_date, d.doc_number, COALESCE(s.name_th, '-') as party_name, s.tax_id, d.total_amount"
        )->get()->map(function ($r) use ($rate) {
            $total = (float) $r->total_amount;
            $base = round($total * 100 / (100 + $rate), 2);

            return [
                'doc_date' => \Illuminate\Support\Carbon::parse($r->doc_date)->thaiDate(),
                'sort_key' => $r->doc_date.'|'.$r->doc_number,
                'doc_number' => $r->doc_number,
                'party_name' => $r->party_name,
                'tax_id' => $r->tax_id ?? '-',
                'base_amount' => $base,
                'vat_amount' => round($total - $base, 2),
                'total_amount' => $total,
            ];
        });

        return $this->withVatTotals($rows);
    }

    // เรียงตามวันที่+เลขที่ และปิดท้ายด้วยแถวรวมทั้งสิ้นสำหรับกรอก ภพ.30
    private function withVatTotals(Collection $rows): Collection
    {
        $rows = $rows->sortBy('sort_key')->values()->map(function ($r) {
            unset($r['sort_key']);

            return $r;
        });

        if ($rows->isNotEmpty()) {
            $rows->push([
                'doc_date' => '',
                'doc_number' => '',
                'party_name' => 'รวมทั้งสิ้น',
                'tax_id' => '',
                'base_amount' => round($rows->sum('base_amount'), 2),
                'vat_amount' => round($rows->sum('vat_amount'), 2),
                'total_amount' => round($rows->sum('total_amount'), 2),
            ]);
        }

        return $rows;
    }

    private function dailySales(Carbon $from, Carbon $to, array $filters): Collection
    {
        $posQuery = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($posQuery, $filters, 't.branch_id');
        $this->applySearch($posQuery, $filters, ['r.receipt_no', 't.code', 't.name']);

        $posRows = $posQuery
            ->groupBy(DB::raw('date(r.receipt_date)'))
            ->selectRaw("date(r.receipt_date) as sale_date, 'POS' as channel, count(*) as bill_count, sum(r.net_sales) as amount")
            ->get();

        $docQuery = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($docQuery, $filters, 'd.branch_id');
        $this->applySearch($docQuery, $filters, ['d.doc_number']);

        $docRows = $docQuery
            ->groupBy('d.doc_date', 'dt.code')
            ->selectRaw("d.doc_date as sale_date, dt.code as channel, count(*) as bill_count, sum(d.total_amount) as amount")
            ->get();

        return $posRows
            ->concat($docRows)
            ->sortByDesc('sale_date')
            ->take($filters['per_page'])
            ->values();
    }

    private function salesByBranch(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters);

        return $query
            ->groupBy('b.id', 'b.code', 'b.name_th')
            ->orderByDesc(DB::raw('sum(r.net_sales)'))
            ->selectRaw("concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, 'ไม่ระบุสาขา')) as branch_name, count(*) as receipt_count, sum(r.net_sales) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function salesByStaff(Carbon $from, Carbon $to, array $filters): Collection
    {
        $posQuery = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.cashier_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($posQuery, $filters, 't.branch_id');
        $this->applySearch($posQuery, $filters, ['r.receipt_no', 'u.name', 't.code', 't.name']);

        $posRows = $posQuery
            ->groupBy('u.id', 'u.name')
            ->selectRaw("coalesce(u.name, 'ไม่ระบุแคชเชียร์') as staff_name, 'POS' as channel, count(*) as bill_count, sum(r.net_sales) as amount")
            ->get();

        $docQuery = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($docQuery, $filters, 'd.branch_id');
        $this->applySearch($docQuery, $filters, ['d.doc_number', 's.name']);

        $docRows = $docQuery
            ->groupBy('s.id', 's.name', 'dt.code')
            ->selectRaw("coalesce(s.name, 'ไม่ระบุพนักงานขาย') as staff_name, dt.code as channel, count(*) as bill_count, sum(d.total_amount) as amount")
            ->get();

        return $posRows
            ->concat($docRows)
            ->sortByDesc('amount')
            ->take($filters['per_page'])
            ->values();
    }

    private function topProducts(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th']);

        return $query
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->orderByDesc(DB::raw('sum(i.net_amount)'))
            ->selectRaw('p.sku_code, p.name_th, sum(i.qty) as qty, sum(i.net_amount) as amount')
            ->limit($filters['per_page'])
            ->get();
    }

    private function productsByBranch(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySearch($query, $filters, ['b.code', 'b.name_th', 'p.sku_code', 'p.name_th']);

        return $query
            ->groupBy('b.id', 'b.code', 'b.name_th', 'p.id', 'p.sku_code', 'p.name_th')
            ->orderBy('b.code')
            ->orderByDesc(DB::raw('sum(i.net_amount)'))
            ->selectRaw("concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, 'ไม่ระบุสาขา')) as branch_name, p.sku_code, p.name_th, sum(i.qty) as qty, sum(i.net_amount) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function salesByCategory(Carbon $from, Carbon $to, array $filters): Collection
    {
        return $this->mergeSalesSlices(
            $this->posSalesSlice($from, $to, $filters, ['category']),
            $this->documentSalesSlice($from, $to, $filters, ['category']),
            ['category_name', 'channel'],
            $filters
        );
    }

    private function salesBySeller(Carbon $from, Carbon $to, array $filters): Collection
    {
        return $this->mergeSalesSlices(
            $this->posSalesSlice($from, $to, $filters, ['seller']),
            $this->documentSalesSlice($from, $to, $filters, ['seller']),
            ['seller_name', 'channel'],
            $filters
        );
    }

    private function salesByCategorySeller(Carbon $from, Carbon $to, array $filters): Collection
    {
        return $this->mergeSalesSlices(
            $this->posSalesSlice($from, $to, $filters, ['category', 'seller']),
            $this->documentSalesSlice($from, $to, $filters, ['category', 'seller']),
            ['category_name', 'seller_name', 'channel'],
            $filters
        );
    }

    private function posSalesSlice(Carbon $from, Carbon $to, array $filters, array $dimensions): Collection
    {
        $query = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.product_category_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.cashier_id')
            ->whereBetween('r.receipt_date', [$from, $to])
            ->where('r.status', '!=', 'cancelled');

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'pc.name_th', 'u.name']);

        $selects = ["'POS' as channel", 'count(distinct r.id) as bill_count', 'sum(i.qty) as qty', 'sum(i.net_amount) as amount'];
        $groups = [];

        if (in_array('category', $dimensions, true)) {
            $selects[] = "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า') as category_name";
            $groups[] = DB::raw("coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า')");
        }
        if (in_array('seller', $dimensions, true)) {
            $selects[] = "coalesce(u.name, 'ไม่ระบุคนขาย') as seller_name";
            $groups[] = DB::raw("coalesce(u.name, 'ไม่ระบุคนขาย')");
        }
        return $query
            ->groupBy(...$groups)
            ->selectRaw(implode(",\n", $selects))
            ->get();
    }

    private function documentSalesSlice(Carbon $from, Carbon $to, array $filters, array $dimensions): Collection
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.product_category_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'p.sku_code', 'p.name_th', 'pc.name_th', 's.name']);

        $selects = [
            "'เอกสาร' as channel",
            'count(distinct d.id) as bill_count',
            "sum(case when dt.code = 'SALE_RETURN' then -sdi.qty else sdi.qty end) as qty",
            "sum(case when dt.code = 'SALE_RETURN' then -sdi.qty * sdi.unit_price else sdi.qty * sdi.unit_price end) as amount",
        ];
        $groups = [];

        if (in_array('category', $dimensions, true)) {
            $selects[] = "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า') as category_name";
            $groups[] = DB::raw("coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า')");
        }
        if (in_array('seller', $dimensions, true)) {
            $selects[] = "coalesce(s.name, 'ไม่ระบุคนขาย') as seller_name";
            $groups[] = DB::raw("coalesce(s.name, 'ไม่ระบุคนขาย')");
        }
        return $query
            ->groupBy(...$groups)
            ->selectRaw(implode(",\n", $selects))
            ->get();
    }

    private function mergeSalesSlices(Collection $posRows, Collection $docRows, array $keys, array $filters): Collection
    {
        return $posRows
            ->concat($docRows)
            ->groupBy(fn ($row) => implode('|', array_map(fn ($key) => (string) ($row->{$key} ?? ''), $keys)))
            ->map(function (Collection $rows) use ($keys) {
                $first = $rows->first();
                $payload = [];
                foreach ($keys as $key) {
                    $payload[$key] = $first->{$key} ?? '';
                }
                $payload['bill_count'] = (int) $rows->sum('bill_count');
                $payload['qty'] = (float) $rows->sum('qty');
                $payload['amount'] = (float) $rows->sum('amount');

                return (object) $payload;
            })
            ->sortByDesc('amount')
            ->take($filters['per_page'] ?? 25)
            ->values();
    }

    private function grossMargin(Carbon $from, Carbon $to, array $filters): Collection
    {
        $costRows = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('dt.code', 'PURCHASE')
            ->where('sdi.unit_price', '>', 0)
            ->groupBy('sdi.product_id')
            ->selectRaw('sdi.product_id, sum(sdi.qty * sdi.unit_price) / nullif(sum(sdi.qty), 0) as avg_cost')
            ->pluck('avg_cost', 'product_id');

        $posQuery = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($posQuery, $filters, 't.branch_id');
        $this->applySearch($posQuery, $filters, ['p.sku_code', 'p.name_th']);

        $posRows = $posQuery
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->selectRaw('p.id as product_id, p.sku_code, p.name_th, sum(i.qty) as qty, sum(i.net_amount) as sales_amount')
            ->get();

        $docQuery = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($docQuery, $filters, 'd.branch_id');
        $this->applySearch($docQuery, $filters, ['p.sku_code', 'p.name_th']);

        $docRows = $docQuery
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->selectRaw('p.id as product_id, p.sku_code, p.name_th, sum(sdi.qty) as qty, sum(sdi.qty * sdi.unit_price) as sales_amount')
            ->get();

        return $posRows
            ->concat($docRows)
            ->groupBy('product_id')
            ->map(function (Collection $rows) use ($costRows) {
                $first = $rows->first();
                $qty = (float) $rows->sum('qty');
                $salesAmount = (float) $rows->sum('sales_amount');
                $avgCost = (float) ($costRows[$first->product_id] ?? 0);
                $costAmount = $qty * $avgCost;

                return (object) [
                    'sku_code' => $first->sku_code,
                    'name_th' => $first->name_th,
                    'qty' => $qty,
                    'sales_amount' => $salesAmount,
                    'cost_amount' => $costAmount,
                    'gross_profit' => $salesAmount - $costAmount,
                ];
            })
            ->sortByDesc('gross_profit')
            ->take($filters['per_page'])
            ->values();
    }

    private function creditSales(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->where('dt.code', 'CREDIT_SALE')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th', 's.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, coalesce(c.name_th, '-') as customer_name, coalesce(s.name, '-') as salesman_name, d.total_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function pendingBookings(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('sale_bookings as sb')
            ->join('documents as d', 'd.id', '=', 'sb.document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->where('sb.status', 'pending')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th', 's.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, coalesce(c.name_th, '-') as customer_name, coalesce(s.name, '-') as salesman_name, d.total_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    // ยอดขายตามใบจอง: ทุกใบจองในช่วง + ใบขายเชื่อที่แปลงแล้ว (BPlus: รายงานใบจอง)
    private function salesByBooking(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('sale_bookings as sb')
            ->join('documents as d', 'd.id', '=', 'sb.document_id')
            ->leftJoin('documents as sd', 'sd.id', '=', 'sb.confirmed_document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'sd.doc_number', 'c.code', 'c.name_th', 's.name']);

        $statusLabels = ['pending' => 'รอแปลงขาย', 'converted_to_sale' => 'แปลงขายแล้ว', 'cancelled' => 'ยกเลิก'];

        return $query
            ->orderByDesc('d.doc_date')->orderByDesc('d.id')
            ->selectRaw("d.doc_number as booking_no, d.doc_date, coalesce(c.name_th, '-') as customer_name, coalesce(s.name, '-') as salesman_name, sb.status, coalesce(sd.doc_number, '-') as sale_doc_no, coalesce(sd.total_amount, d.total_amount) as total_amount")
            ->limit($filters['per_page'])
            ->get()
            ->map(function ($row) use ($statusLabels) {
                $row->status = $statusLabels[$row->status] ?? $row->status;

                return $row;
            });
    }

    // ใบขาย-รับคืน ตามเอกสาร (BPlus G): ขายสด/ขายเชื่อ/รับคืนรวมตารางเดียว คืนติดลบ
    private function salesReturnsByDocument(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th', 's.name']);

        $rows = $query
            ->orderByDesc('d.doc_date')->orderByDesc('d.id')
            ->selectRaw("d.doc_date, d.doc_number, dt.name_th as document_type, coalesce(c.name_th, '-') as customer_name, coalesce(s.name, '-') as salesman_name, case when dt.code = 'SALE_RETURN' then -d.total_amount else d.total_amount end as total_amount")
            ->limit($filters['per_page'])
            ->get();

        if ($rows->isNotEmpty()) {
            $rows->push((object) [
                'doc_date' => '', 'doc_number' => '', 'document_type' => '',
                'customer_name' => '', 'salesman_name' => 'รวมทั้งสิ้น',
                'total_amount' => $rows->sum('total_amount'),
            ]);
        }

        return $rows;
    }

    // สรุปขาย-รับคืน ตามสินค้า (BPlus U): จำนวน/ยอดขาย เทียบจำนวน/ยอดคืน ต่อสินค้า
    private function saleReturnByProduct(Carbon $from, Carbon $to, array $filters): Collection
    {
        $base = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($base, $filters, 'd.branch_id');
        $this->applySearch($base, $filters, ['p.sku_code', 'p.name_th']);

        $rows = (clone $base)
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->selectRaw("p.sku_code, p.name_th,
                sum(case when dt.code <> 'SALE_RETURN' then sdi.qty else 0 end) as sold_qty,
                sum(case when dt.code <> 'SALE_RETURN' then sdi.qty * sdi.unit_price else 0 end) as sold_amount,
                sum(case when dt.code = 'SALE_RETURN' then sdi.qty else 0 end) as return_qty,
                sum(case when dt.code = 'SALE_RETURN' then sdi.qty * sdi.unit_price else 0 end) as return_amount")
            ->orderByRaw('sold_amount desc')
            ->limit($filters['per_page'])
            ->get()
            ->map(function ($row) {
                $row->net_amount = (float) $row->sold_amount - (float) $row->return_amount;

                return $row;
            });

        if ($rows->isNotEmpty()) {
            $rows->push((object) [
                'sku_code' => '', 'name_th' => 'รวมทั้งสิ้น',
                'sold_qty' => $rows->sum('sold_qty'), 'sold_amount' => $rows->sum('sold_amount'),
                'return_qty' => $rows->sum('return_qty'), 'return_amount' => $rows->sum('return_amount'),
                'net_amount' => $rows->sum('net_amount'),
            ]);
        }

        return $rows;
    }

    private function salesSummaryByCustomer(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number']);

        return $query
            ->groupBy('c.id', 'c.code', 'c.name_th')
            ->orderByRaw("sum(case when dt.code = 'SALE_RETURN' then -sdi.qty * sdi.unit_price else sdi.qty * sdi.unit_price end) desc")
            ->selectRaw("
                concat(coalesce(c.code, '-'), ' ', coalesce(c.name_th, 'ลูกค้าทั่วไป')) as customer_name,
                count(distinct d.id) as bill_count,
                sum(case when dt.code = 'SALE_RETURN' then -sdi.qty else sdi.qty end) as qty,
                sum(case when dt.code <> 'SALE_RETURN' then sdi.qty * sdi.unit_price else 0 end) as sales_amount,
                sum(case when dt.code = 'SALE_RETURN' then sdi.qty * sdi.unit_price else 0 end) as return_amount,
                sum(case when dt.code = 'SALE_RETURN' then -sdi.qty * sdi.unit_price else sdi.qty * sdi.unit_price end) as net_amount
            ")
            ->limit($filters['per_page'])
            ->get();
    }

    private function salesSummary12Months(Carbon $to, array $filters, string $mode): Collection
    {
        $from = $to->copy()->subMonthsNoOverflow(11)->startOfMonth();
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.product_category_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th', 's.name', 'p.sku_code', 'p.name_th', 'pc.name_th']);

        $selects = [
            "to_char(date_trunc('month', d.doc_date), 'YYYY-MM') as sale_month",
            "sum(case when dt.code = 'SALE_RETURN' then -sdi.qty else sdi.qty end) as qty",
            "sum(case when dt.code = 'SALE_RETURN' then -sdi.qty * sdi.unit_price else sdi.qty * sdi.unit_price end) as net_amount",
        ];
        $groups = [DB::raw("date_trunc('month', d.doc_date)")];

        if (in_array($mode, ['customer', 'customer_product'], true)) {
            $selects[] = "concat(coalesce(c.code, '-'), ' ', coalesce(c.name_th, 'ลูกค้าทั่วไป')) as customer_name";
            $groups = array_merge($groups, ['c.id', 'c.code', 'c.name_th']);
        }
        if (in_array($mode, ['customer_product', 'salesman_product'], true)) {
            $selects[] = 'p.sku_code';
            $selects[] = 'p.name_th';
            $groups = array_merge($groups, ['p.id', 'p.sku_code', 'p.name_th']);
        }
        if ($mode === 'category') {
            $selects[] = "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า') as category_name";
            $groups = array_merge($groups, ['pc.id', 'pc.name_th']);
        }
        if ($mode === 'salesman_product') {
            $selects[] = "coalesce(s.name, '-') as salesman_name";
            $groups = array_merge($groups, ['s.id', 's.name']);
        }
        if ($mode === 'customer') {
            $selects[] = 'count(distinct d.id) as bill_count';
        }

        return $query
            ->groupBy(...$groups)
            ->orderBy(DB::raw("date_trunc('month', d.doc_date)"))
            ->orderByRaw('net_amount desc')
            ->selectRaw(implode(",\n", $selects))
            ->limit($filters['per_page'])
            ->get();
    }

    // สินค้าขายต่ำกว่าทุน (BPlus ฝ่ายบริหาร): รายการขายที่ราคาต่ำกว่าต้นทุนเฉลี่ย
    private function lossSales(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->where('p.average_cost', '>', 0)
            ->whereColumn('sdi.unit_price', '<', 'p.average_cost')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'p.sku_code', 'p.name_th']);

        return $query
            ->orderByRaw('(p.average_cost - sdi.unit_price) * sdi.qty desc')
            ->selectRaw('d.doc_date, d.doc_number, p.sku_code, p.name_th, sdi.qty as sold_qty, sdi.unit_price, p.average_cost as avg_cost, (p.average_cost - sdi.unit_price) * sdi.qty as loss_amount')
            ->limit($filters['per_page'])
            ->get();
    }

    private function lossSalesSixMonths(Carbon $to, array $filters): Collection
    {
        $from = $to->copy()->subMonthsNoOverflow(5)->startOfMonth();
        $query = $this->lossSalesBase($from, $to, $filters);

        return $query
            ->groupBy(DB::raw("date_trunc('month', d.doc_date)"), 'p.id', 'p.sku_code', 'p.name_th')
            ->orderBy(DB::raw("date_trunc('month', d.doc_date)"))
            ->orderByRaw('loss_amount desc')
            ->selectRaw("
                to_char(date_trunc('month', d.doc_date), 'YYYY-MM') as sale_month,
                p.sku_code,
                p.name_th,
                sum(sdi.qty) as sold_qty,
                sum(sdi.qty * sdi.unit_price) as sales_amount,
                sum(sdi.qty * p.average_cost) as cost_amount,
                sum((p.average_cost - sdi.unit_price) * sdi.qty) as loss_amount
            ")
            ->limit($filters['per_page'])
            ->get();
    }

    private function lossGroupColumns(string $groupLabel): array
    {
        return [
            ['label' => 'เดือน', 'key' => 'sale_month'],
            ['label' => $groupLabel, 'key' => 'group_name'],
            ['label' => 'รายการสินค้า', 'key' => 'product_count', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'จำนวน', 'key' => 'sold_qty', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'ยอดขาย', 'key' => 'sales_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'ต้นทุน', 'key' => 'cost_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'ขาดทุน', 'key' => 'loss_amount', 'type' => 'money', 'class' => 'text-end'],
        ];
    }

    private function lossSalesGroupedSixMonths(Carbon $to, array $filters, string $mode): Collection
    {
        $from = $to->copy()->subMonthsNoOverflow(5)->startOfMonth();
        $query = $this->lossSalesBase($from, $to, $filters)
            ->leftJoin('product_departments as pd', 'pd.id', '=', 'p.product_department_id')
            ->leftJoin('product_brands as pb', 'pb.id', '=', 'p.product_brand_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.product_category_id');

        $groupSql = match ($mode) {
            'department' => "coalesce(pd.name_th, 'ไม่ระบุประเภทสินค้า')",
            'brand' => "coalesce(pb.name_th, 'ไม่ระบุยี่ห้อสินค้า')",
            'category' => "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า')",
            'supplier' => "'ไม่ระบุผู้จำหน่ายหลัก'",
            default => "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า')",
        };

        return $query
            ->groupBy(DB::raw("date_trunc('month', d.doc_date)"), DB::raw($groupSql))
            ->orderBy(DB::raw("date_trunc('month', d.doc_date)"))
            ->orderByRaw('loss_amount desc')
            ->selectRaw("
                to_char(date_trunc('month', d.doc_date), 'YYYY-MM') as sale_month,
                {$groupSql} as group_name,
                count(distinct p.id) as product_count,
                sum(sdi.qty) as sold_qty,
                sum(sdi.qty * sdi.unit_price) as sales_amount,
                sum(sdi.qty * p.average_cost) as cost_amount,
                sum((p.average_cost - sdi.unit_price) * sdi.qty) as loss_amount
            ")
            ->limit($filters['per_page'])
            ->get();
    }

    private function lossPriceTable(array $filters): Collection
    {
        $query = DB::table('product_prices as pp')
            ->join('products as p', 'p.id', '=', 'pp.product_id')
            ->join('price_tables as pt', 'pt.id', '=', 'pp.price_table_id')
            ->where('pp.is_active', true)
            ->where('pp.price', '>', 0)
            ->where('pp.cost_price', '>', 0)
            ->whereColumn('pp.price', '<', 'pp.cost_price');

        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'pt.code', 'pt.name']);

        return $query
            ->orderByRaw('(pp.cost_price - pp.price) desc')
            ->selectRaw("p.sku_code, p.name_th, concat(pt.code, ' ', pt.name) as price_table_name, pp.price, pp.cost_price, pp.cost_price - pp.price as loss_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function lossSalesDocuments(Carbon $from, Carbon $to, array $filters, bool $summary): Collection
    {
        $query = $this->lossSalesBase($from, $to, $filters)
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id');

        if (! $summary) {
            return $query
                ->orderByDesc('d.doc_date')
                ->selectRaw("d.doc_date, d.doc_number, p.sku_code, p.name_th, sdi.qty as sold_qty, sdi.unit_price, p.average_cost as avg_cost, (p.average_cost - sdi.unit_price) * sdi.qty as loss_amount")
                ->limit($filters['per_page'])
                ->get();
        }

        return $query
            ->groupBy('d.id', 'd.doc_date', 'd.doc_number', 'c.code', 'c.name_th')
            ->orderByDesc('d.doc_date')
            ->selectRaw("
                d.doc_date,
                d.doc_number,
                concat(coalesce(c.code, '-'), ' ', coalesce(c.name_th, 'ลูกค้าทั่วไป')) as customer_name,
                count(*) as line_count,
                sum((p.average_cost - sdi.unit_price) * sdi.qty) as loss_amount
            ")
            ->limit($filters['per_page'])
            ->get();
    }

    private function lossSalesBase(Carbon $from, Carbon $to, array $filters)
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->where('p.average_cost', '>', 0)
            ->whereColumn('sdi.unit_price', '<', 'p.average_cost')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'p.sku_code', 'p.name_th']);

        return $query;
    }

    // สรุปยอดลูกหนี้ (BPlus การเงิน): ยอดค้างต่อลูกค้า + ส่วนที่เกินกำหนด
    private function arSummary(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->whereIn('oi.status', ['open', 'partial']);

        $this->applyBranch($query, $filters, 'c.branch_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th']);

        $rows = $query
            ->groupBy('c.id', 'c.code', 'c.name_th')
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, count(*) as bill_count, min(oi.due_date) as oldest_due_date, sum(case when oi.due_date < current_date then oi.balance_amount else 0 end) as overdue_amount, sum(oi.balance_amount) as balance_amount")
            ->orderByRaw('balance_amount desc')
            ->limit($filters['per_page'])
            ->get();

        if ($rows->isNotEmpty()) {
            $rows->push((object) [
                'customer_name' => 'รวมทั้งสิ้น', 'bill_count' => $rows->sum('bill_count'),
                'oldest_due_date' => '', 'overdue_amount' => $rows->sum('overdue_amount'),
                'balance_amount' => $rows->sum('balance_amount'),
            ]);
        }

        return $rows;
    }

    private function arAging(): Collection
    {
        $rows = DB::table('customer_open_items')
            ->whereIn('status', ['open', 'partial'])
            ->selectRaw("
                sum(case when due_date is null or due_date >= current_date then balance_amount else 0 end) as current_amount,
                sum(case when due_date < current_date and due_date >= current_date - interval '30 days' then balance_amount else 0 end) as days_1_30,
                sum(case when due_date < current_date - interval '30 days' and due_date >= current_date - interval '60 days' then balance_amount else 0 end) as days_31_60,
                sum(case when due_date < current_date - interval '60 days' and due_date >= current_date - interval '90 days' then balance_amount else 0 end) as days_61_90,
                sum(case when due_date < current_date - interval '90 days' then balance_amount else 0 end) as over_90
            ")
            ->first();

        return collect([
            ['bucket' => 'ยังไม่ถึงกำหนด', 'amount' => (float) ($rows->current_amount ?? 0)],
            ['bucket' => '1-30 วัน', 'amount' => (float) ($rows->days_1_30 ?? 0)],
            ['bucket' => '31-60 วัน', 'amount' => (float) ($rows->days_31_60 ?? 0)],
            ['bucket' => '61-90 วัน', 'amount' => (float) ($rows->days_61_90 ?? 0)],
            ['bucket' => 'เกิน 90 วัน', 'amount' => (float) ($rows->over_90 ?? 0)],
        ])->map(fn ($row) => (object) $row);
    }

    private function overdueCustomers(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'oi.salesman_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->whereDate('oi.due_date', '<', now()->toDateString());

        $this->applyBranch($query, $filters, 'c.branch_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 's.name']);

        return $query
            ->groupBy('c.id', 'c.code', 'c.name_th', 's.name')
            ->orderByDesc(DB::raw('sum(oi.balance_amount)'))
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, coalesce(s.name, '-') as salesman_name, count(*) as bill_count, sum(oi.balance_amount) as balance_amount, min(oi.due_date) as oldest_due_date")
            ->limit($filters['per_page'])
            ->get();
    }

    private function openItems(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->whereIn('oi.status', ['open', 'partial']);

        $this->applyBranch($query, $filters, 'c.branch_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number']);

        return $query
            ->orderBy('oi.due_date')
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, d.doc_number, oi.due_date, oi.status, oi.balance_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function arDetails(array $filters, bool $full): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'oi.salesman_id')
            ->whereIn('oi.status', ['open', 'partial']);

        $this->applyBranch($query, $filters, 'c.branch_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number', 's.name']);

        $select = $full
            ? "concat(c.code, ' ', c.name_th) as customer_name, coalesce(s.name, '-') as salesman_name, d.doc_number, d.doc_date, oi.due_date, oi.net_amount, oi.paid_amount, oi.balance_amount"
            : "concat(c.code, ' ', c.name_th) as customer_name, d.doc_number, oi.due_date, oi.balance_amount";

        return $query
            ->orderBy('oi.due_date')
            ->selectRaw($select)
            ->limit($filters['per_page'])
            ->get();
    }

    private function arOverdueDetails(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'oi.salesman_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->whereDate('oi.due_date', '<', now()->toDateString());

        $this->applyBranch($query, $filters, 'c.branch_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number', 's.name']);

        return $query
            ->orderBy('oi.due_date')
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, coalesce(s.name, '-') as salesman_name, d.doc_number, oi.due_date, (current_date - oi.due_date) as overdue_days, oi.balance_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function arOverCreditLimit(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('c.credit_limit', '>', 0);

        $this->applyBranch($query, $filters, 'c.branch_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th']);

        return $query
            ->groupBy('c.id', 'c.code', 'c.name_th', 'c.credit_limit')
            ->havingRaw('sum(oi.balance_amount) > c.credit_limit')
            ->orderByRaw('sum(oi.balance_amount) - c.credit_limit desc')
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, c.credit_limit, sum(oi.balance_amount) as balance_amount, sum(oi.balance_amount) - c.credit_limit as over_limit_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function stockBalance(array $filters): Collection
    {
        // เชื่อมสาขาผ่านคลังหลักของสาขา (branches.default_warehouse_location_id)
        // เพราะ warehouses.branch_id ว่าง - นี่คือสายจริงที่บอกว่า location นี้ของสาขาไหน
        $query = DB::table('stock_balances as sb')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sb.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', DB::raw($this->branchByLocationSub('sb.warehouse_location_id')));

        $this->applyBranch($query, $filters, 'b.id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'w.name', 'wl.name']);

        return $query
            ->orderBy('p.sku_code')
            ->selectRaw("p.sku_code, p.name_th, concat(coalesce(b.name_th, w.name), ' / ', coalesce(wl.name, wl.code)) as location_name, sb.on_hand_qty, sb.reserved_qty")
            ->limit($filters['per_page'])
            ->get();
    }

    private function stockByBranch(array $filters): Collection
    {
        $query = DB::table('stock_balances as sb')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sb.warehouse_location_id')
            ->leftJoin('branches as b', 'b.id', '=', DB::raw($this->branchByLocationSub('sb.warehouse_location_id')));

        $this->applyBranch($query, $filters, 'b.id');
        $this->applySearch($query, $filters, ['b.code', 'b.name_th', 'wl.name', 'p.sku_code', 'p.name_th']);

        return $query
            ->groupBy('b.id', 'b.code', 'b.name_th', 'wl.name', 'wl.code')
            ->orderBy('b.code')
            ->selectRaw("concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, wl.name, 'คลังกลาง/ตู้')) as branch_name, count(distinct sb.product_id) as product_count, sum(sb.on_hand_qty) as on_hand_qty, sum(sb.reserved_qty) as reserved_qty")
            ->limit($filters['per_page'])
            ->get();
    }

    private function stockAlerts(array $filters): Collection
    {
        return $this->stockBalance($filters)->sortBy('on_hand_qty')->values();
    }

    private function stockMovements(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sm.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', DB::raw($this->branchByLocationSub('sm.warehouse_location_id')))
            ->whereBetween('sm.movement_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'b.id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'sm.movement_type']);

        return $query
            ->orderByDesc('sm.movement_date')
            ->selectRaw("sm.movement_date, concat(p.sku_code, ' ', p.name_th) as product_name, concat(w.name, ' / ', coalesce(wl.name, wl.code)) as location_name, sm.movement_type, sm.qty")
            ->limit($filters['per_page'])
            ->get();
    }

    private function documentColumns(): array
    {
        return [
            ['label' => 'วันที่', 'key' => 'doc_date'],
            ['label' => 'เลขที่', 'key' => 'doc_number'],
            ['label' => 'สาขา', 'key' => 'branch_name'],
            ['label' => 'คู่ค้า/ลูกค้า', 'key' => 'party_name'],
            ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
            ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
            ['label' => 'รายการ', 'key' => 'total_items', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
        ];
    }

    private function documentsSummary(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['dt.code', 'dt.name_th']);

        return $query
            ->groupBy('dt.id', 'dt.code', 'dt.name_th')
            ->orderBy('dt.code')
            ->selectRaw("concat(dt.code, ' - ', dt.name_th) as document_type, count(*) as document_count, sum(d.total_items) as item_count, sum(d.total_amount) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function documentList(Carbon $from, Carbon $to, array $filters, array $codes = []): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->leftJoin('salesmen as sm', 'sm.id', '=', 'd.salesman_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        if ($codes !== []) {
            $query->whereIn('dt.code', $codes);
        }

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'dt.code', 'dt.name_th', 'b.code', 'b.name_th', 'c.code', 'c.name_th', 's.code', 's.name_th', 'sm.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->orderByDesc('d.id')
            ->selectRaw("d.doc_date, concat(dt.code, ' - ', dt.name_th) as document_type, d.doc_number, concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, 'ไม่ระบุสาขา')) as branch_name, coalesce(c.name_th, s.name_th, '-') as party_name, coalesce(sm.name, '-') as salesman_name, d.status, d.total_items, d.total_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function documentItems(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->leftJoin('warehouse_locations as wl', 'wl.id', '=', 'sdi.warehouse_location_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'dt.code', 'dt.name_th', 'p.sku_code', 'p.name_th', 'w.name', 'wl.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->orderByDesc('d.id')
            ->selectRaw("d.doc_date, concat(dt.code, ' - ', dt.name_th) as document_type, d.doc_number, concat(p.sku_code, ' ', p.name_th) as product_name, concat(coalesce(w.name, '-'), ' / ', coalesce(wl.name, wl.code, '-')) as location_name, sdi.qty, sdi.unit_price, sdi.qty * coalesce(sdi.unit_price, 0) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function posReceipts(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySearch($query, $filters, ['r.receipt_no', 't.code', 't.name']);

        return $query
            ->orderByDesc('r.receipt_date')
            ->selectRaw("r.receipt_no, r.receipt_date, coalesce(t.name, t.code) as terminal_name, r.status, r.net_sales")
            ->limit($filters['per_page'])
            ->get();
    }

    private function posByTerminal(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters, 't.branch_id');

        return $query
            ->groupBy('t.id', 't.code', 't.name')
            ->orderByDesc(DB::raw('sum(r.net_sales)'))
            ->selectRaw("coalesce(t.name, t.code) as terminal_name, count(*) as receipt_count, sum(r.net_sales) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function paymentsByMethod(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_payments as p')
            ->join('pos_receipts as r', 'r.id', '=', 'p.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters, 't.branch_id');

        return $query
            ->groupBy('p.method')
            ->orderByDesc(DB::raw('sum(p.amount)'))
            ->selectRaw('p.method, count(*) as line_count, sum(p.amount) as amount')
            ->get();
    }

    private function posHourly(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySearch($query, $filters, ['r.receipt_no', 't.code', 't.name']);

        return $query
            ->groupBy(DB::raw("date_trunc('hour', r.receipt_date)"))
            ->orderBy(DB::raw("date_trunc('hour', r.receipt_date)"))
            ->selectRaw("to_char(date_trunc('hour', r.receipt_date), 'YYYY-MM-DD HH24:00') as sale_hour, count(*) as receipt_count, sum(r.net_sales) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function posTaxDiscount(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySearch($query, $filters, ['r.receipt_no', 't.code', 't.name']);

        return $query
            ->groupBy(DB::raw('date(r.receipt_date)'))
            ->orderBy(DB::raw('date(r.receipt_date)'))
            ->selectRaw('date(r.receipt_date) as sale_date, count(*) as receipt_count, sum(r.gross_sales) as gross_sales, sum(r.discount_amount) as discount_amount, sum(r.vat_amount) as vat_amount, sum(r.net_sales) as net_sales')
            ->limit($filters['per_page'])
            ->get();
    }

    private function purchaseDocuments(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->where('dt.code', 'PURCHASE')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'b.code', 'b.name_th', 's.code', 's.name_th']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, 'ไม่ระบุสาขา')) as branch_name, coalesce(s.name_th, '-') as supplier_name, d.total_items, d.total_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function purchaseBySupplier(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->where('dt.code', 'PURCHASE')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['s.code', 's.name_th']);

        return $query
            ->groupBy('s.id', 's.code', 's.name_th')
            ->orderByDesc(DB::raw('sum(d.total_amount)'))
            ->selectRaw("concat(coalesce(s.code, '-'), ' ', coalesce(s.name_th, 'ไม่ระบุผู้ขาย')) as supplier_name, count(d.id) as document_count, sum(d.total_items) as item_count, sum(d.total_amount) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function purchaseItems(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->where('dt.code', 'PURCHASE')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th']);

        return $query
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->orderByDesc(DB::raw('sum(sdi.qty * coalesce(sdi.unit_price, 0))'))
            ->selectRaw('p.sku_code, p.name_th, sum(sdi.qty) as qty, sum(sdi.qty * coalesce(sdi.unit_price, 0)) as amount')
            ->limit($filters['per_page'])
            ->get();
    }

    private function stockTransfers(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('stock_documents as sd', 'sd.document_id', '=', 'd.id')
            ->join('stock_document_items as sdi', 'sdi.stock_document_id', '=', 'sd.id')
            ->join('warehouse_locations as from_wl', 'from_wl.id', '=', 'sdi.warehouse_location_id')
            ->leftJoin('warehouses as from_w', 'from_w.id', '=', 'from_wl.warehouse_id')
            ->leftJoin('warehouse_locations as to_wl', 'to_wl.id', '=', 'sd.to_warehouse_location_id')
            ->leftJoin('warehouses as to_w', 'to_w.id', '=', 'to_wl.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->where('dt.code', 'STOCK_TRANSFER')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'from_w.name', 'from_wl.name', 'to_w.name', 'to_wl.name']);

        return $query
            ->groupBy('d.id', 'd.doc_number', 'd.doc_date', 'b.code', 'b.name_th', 'from_w.name', 'from_wl.code', 'from_wl.name', 'to_w.name', 'to_wl.code', 'to_wl.name')
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, 'ไม่ระบุสาขา')) as branch_name, concat(coalesce(from_w.name, '-'), ' / ', coalesce(from_wl.name, from_wl.code)) as from_location, concat(coalesce(to_w.name, '-'), ' / ', coalesce(to_wl.name, to_wl.code, '-')) as to_location, sum(sdi.qty) as total_qty")
            ->limit($filters['per_page'])
            ->get();
    }

    private function transferItems(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->join('warehouse_locations as from_wl', 'from_wl.id', '=', 'sdi.warehouse_location_id')
            ->leftJoin('warehouses as from_w', 'from_w.id', '=', 'from_wl.warehouse_id')
            ->leftJoin('warehouse_locations as to_wl', 'to_wl.id', '=', 'sd.to_warehouse_location_id')
            ->leftJoin('warehouses as to_w', 'to_w.id', '=', 'to_wl.warehouse_id')
            ->where('dt.code', 'STOCK_TRANSFER')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'p.sku_code', 'p.name_th', 'from_w.name', 'from_wl.name', 'to_w.name', 'to_wl.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_date, d.doc_number, concat(p.sku_code, ' ', p.name_th) as product_name, concat(coalesce(from_w.name, '-'), ' / ', coalesce(from_wl.name, from_wl.code)) as from_location, concat(coalesce(to_w.name, '-'), ' / ', coalesce(to_wl.name, to_wl.code, '-')) as to_location, sdi.qty")
            ->limit($filters['per_page'])
            ->get();
    }

    private function transferByLocation(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('warehouse_locations as from_wl', 'from_wl.id', '=', 'sdi.warehouse_location_id')
            ->leftJoin('warehouses as from_w', 'from_w.id', '=', 'from_wl.warehouse_id')
            ->leftJoin('warehouse_locations as to_wl', 'to_wl.id', '=', 'sd.to_warehouse_location_id')
            ->leftJoin('warehouses as to_w', 'to_w.id', '=', 'to_wl.warehouse_id')
            ->where('dt.code', 'STOCK_TRANSFER')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['from_w.name', 'from_wl.name', 'to_w.name', 'to_wl.name']);

        return $query
            ->groupBy('from_w.name', 'from_wl.code', 'from_wl.name', 'to_w.name', 'to_wl.code', 'to_wl.name')
            ->orderByDesc(DB::raw('sum(sdi.qty)'))
            ->selectRaw("concat(coalesce(from_w.name, '-'), ' / ', coalesce(from_wl.name, from_wl.code)) as from_location, concat(coalesce(to_w.name, '-'), ' / ', coalesce(to_wl.name, to_wl.code, '-')) as to_location, count(distinct d.id) as document_count, sum(sdi.qty) as qty")
            ->limit($filters['per_page'])
            ->get();
    }

    private function paymentDocuments(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('payment_documents as pd')
            ->join('documents as d', 'd.id', '=', 'pd.document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'pd.customer_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'pd.supplier_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'pd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.name_th', 's.name_th']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, pd.party_type, coalesce(c.name_th, s.name_th, '-') as party_name, pd.status")
            ->limit($filters['per_page'])
            ->get();
    }

    private function paymentAllocations(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('payment_allocations as pa')
            ->join('payment_documents as pd', 'pd.id', '=', 'pa.payment_document_id')
            ->join('documents as d', 'd.id', '=', 'pd.document_id')
            ->leftJoin('customer_open_items as oi', 'oi.id', '=', 'pa.customer_open_item_id')
            ->leftJoin('customers as c', 'c.id', '=', 'oi.customer_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'pd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.name_th']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, coalesce(c.name_th, '-') as customer_name, pa.allocated_amount, pa.discount_amount, pa.wht_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function glJournals(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('gl_journals as gj')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'gj.account_id')
            ->whereBetween('gj.entry_date', [$from->toDateString(), $to->toDateString()]);

        $this->applySearch($query, $filters, ['coa.code', 'coa.name_th', 'gj.remark']);

        return $query
            ->orderByDesc('gj.entry_date')
            ->selectRaw("gj.entry_date, concat(coa.code, ' ', coa.name_th) as account_name, gj.debit, gj.credit, gj.remark")
            ->limit($filters['per_page'])
            ->get();
    }

    private function importBatches(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('import_batches')
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()]);

        $this->applySearch($query, $filters, ['pos_code', 'status', 'source_zip_name', 'source_cds_name']);

        return $query
            ->orderByDesc('sale_date')
            ->select('pos_code', 'sale_date', 'status', 'record_count')
            ->limit($filters['per_page'])
            ->get();
    }

    private function importErrors(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('import_errors')
            ->whereBetween('created_at', [$from, $to]);

        $this->applySearch($query, $filters, ['receipt_no', 'error_type', 'error_message']);

        return $query
            ->orderByDesc('created_at')
            ->select('created_at', 'receipt_no', 'error_type', 'error_message')
            ->limit($filters['per_page'])
            ->get();
    }

    private function importErrorSummary(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('import_errors')
            ->whereBetween('created_at', [$from, $to]);

        $this->applySearch($query, $filters, ['receipt_no', 'error_type', 'error_message']);

        return $query
            ->groupBy('error_type')
            ->orderByDesc(DB::raw('count(*)'))
            ->selectRaw('error_type, count(*) as error_count, count(distinct receipt_no) as receipt_count, min(created_at) as first_seen, max(created_at) as last_seen')
            ->limit($filters['per_page'])
            ->get();
    }

    private function voidBillHistory(Carbon $from, Carbon $to, array $filters): Collection
    {
        $docQuery = DB::table('documents as d')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.created_by')
            ->where(function ($q) {
                $q->whereNotNull('d.cancelled_at')->orWhere('d.status', 'cancelled');
            })
            ->whereBetween(DB::raw('coalesce(d.cancelled_at, d.updated_at)'), [$from, $to]);
        $this->applyBranch($docQuery, $filters, 'd.branch_id');
        $this->applySearch($docQuery, $filters, ['d.doc_number', 'b.code', 'b.name_th', 'u.name']);

        $docRows = $docQuery
            ->selectRaw("
                coalesce(d.cancelled_at, d.updated_at) as cancelled_at,
                'DOCUMENT' as source,
                d.doc_number,
                concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, '')) as branch_name,
                coalesce(u.name, '-') as user_name,
                d.total_amount,
                coalesce(d.reference, '-') as remark
            ")
            ->get();

        $posQuery = DB::table('pos_receipts as r')
            ->leftJoin('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.cashier_id')
            ->whereIn('r.status', ['cancelled', 'void', 'voided'])
            ->whereBetween('r.receipt_date', [$from, $to]);
        $this->applyBranch($posQuery, $filters, 'b.id');
        $this->applySearch($posQuery, $filters, ['r.receipt_no', 'b.code', 'b.name_th', 'u.name']);

        $posRows = $posQuery
            ->selectRaw("
                r.receipt_date as cancelled_at,
                'POS' as source,
                r.receipt_no as doc_number,
                concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, '')) as branch_name,
                coalesce(u.name, '-') as user_name,
                r.net_sales as total_amount,
                r.status as remark
            ")
            ->get();

        $auditQuery = DB::table('audit_logs as al')
            ->leftJoin('branches as b', 'b.id', '=', 'al.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->whereIn('al.action', ['delete', 'void', 'cancel'])
            ->whereBetween('al.created_at', [$from, $to]);
        $this->applyBranch($auditQuery, $filters, 'al.branch_id');
        $this->applySearch($auditQuery, $filters, ['al.table_name', 'b.code', 'b.name_th', 'u.name']);

        $auditRows = $auditQuery
            ->selectRaw("
                al.created_at as cancelled_at,
                'AUDIT' as source,
                concat(al.table_name, '#', coalesce(al.record_id::text, '-')) as doc_number,
                concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, '')) as branch_name,
                coalesce(u.name, '-') as user_name,
                0 as total_amount,
                al.action as remark
            ")
            ->get();

        return $docRows
            ->concat($posRows)
            ->concat($auditRows)
            ->sortByDesc('cancelled_at')
            ->take($filters['per_page'])
            ->values();
    }

    private function pendingWork(): array
    {
        return [
            'ใบจองรอแปลงขาย' => DB::table('sale_bookings')->where('status', 'pending')->count(),
            'ลูกหนี้ค้างชำระ' => DB::table('customer_open_items')->whereIn('status', ['open', 'partial'])->whereDate('due_date', '<', now()->toDateString())->count(),
            'POS import รอจัดการ' => DB::table('import_batches')->whereIn('status', ['uploaded', 'parsed', 'has_error', 'validated', 'confirmed'])->count(),
            'Import errors' => DB::table('import_errors')->count(),
        ];
    }
}
