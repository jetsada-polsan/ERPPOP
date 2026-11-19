<?php

namespace App\Http\Controllers;

use App\Models\ReportDefinition;
use App\Services\Reports\ReportScope;
use App\Services\Reports\StockBalanceReport;
use App\Services\Reports\StockQuantityFormatter;
use App\Support\DecimalMath;
use App\Support\SqlDialect;
use App\Support\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** สาขาที่ไม่มีอยู่จริง ใช้กรองให้ได้ผลลัพธ์ว่างเมื่อผู้ใช้ยังไม่ผูกสาขาและไม่มีสิทธิ์ข้ามสาขา */
    private const NO_BRANCH_SENTINEL = '-1';

    public function index(Request $request)
    {
        $definitions = $this->definitions();
        $catalog = $this->catalogForUser($this->catalog($definitions), $definitions);
        if (! $request->attributes->get('closed_menu') && \App\Support\MenuVisibility::hidden()) {
            $catalog = array_filter($catalog, fn ($key) => !\App\Support\MenuVisibility::blocked('reports.index', ['category' => $key]), ARRAY_FILTER_USE_KEY);
        }
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

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        // ค่าเริ่มต้น = วันนี้ (งานหลักคือเช็ครายวัน) - เลือกช่วงย้อนหลังเองได้
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->startOfDay();
        $printMode = $request->boolean('print');
        $perPage = (int) $request->input('per_page', 25);
        if (! in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 25;
        }
        // พิมพ์เฉพาะหน้าปัจจุบันตามตัวกรองเดียวกับหน้าจอ ไม่ดึงทั้งรายงาน
        $user = auth()->user();
        $canSeeAllBranches = $user === null || $user->canSeeAllReportBranches();
        $requestedBranch = $request->input('branch_id');
        if (! $canSeeAllBranches) {
            $assignedBranch = $user->resolveReportBranch($requestedBranch);
            $requestedBranch = $assignedBranch === null ? self::NO_BRANCH_SENTINEL : (string) $assignedBranch;
        } elseif ($requestedBranch === null && ($userBranch = $user?->branch_id)) {
            $requestedBranch = (string) $userBranch;
        } elseif ($requestedBranch === 'all') {
            $requestedBranch = null;
        }
        $filters = [
            'branch_id' => $requestedBranch,
            'sales_area_id' => $request->integer('sales_area_id') ?: null,
            'q' => trim((string) $request->input('q', '')),
            'per_page' => $perPage,
            'page' => max(1, $request->integer('page', 1)),
            'paginate' => true,
            'product_id' => $request->integer('product_id') ?: null,
            'location_id' => $request->integer('location_id') ?: null,
            'salesman_id' => $this->reportSalesmanFilter($request->input('salesman_id')),
            'customer_id' => $request->integer('customer_id') ?: null,
            'document_book' => in_array($request->input('document_book'), ['DS', 'DSN'], true) ? $request->input('document_book') : null,
        ];

        // ส่งออกทั้งรายงาน ไม่ใช่เท่าที่แสดงบนจอ — per_page เป็น LIMIT ของ query
        // ถ้าใช้ค่าเดียวกับหน้าจอ คนกดออกไฟล์ภาษีขายจะได้ข้อมูลไม่ครบโดยไม่รู้ตัว
        if (in_array($request->input('export'), ['csv', 'xlsx'], true)) {
            abort_unless($user === null || $user->hasPermission('reports.export'), 403, 'ไม่มีสิทธิ์ส่งออกรายงาน');

            $result = app(ReportScope::class)->redact($this->runReport($category, $report, $from, $to, ['per_page' => self::EXPORT_ROW_CAP, 'paginate' => false] + $filters));

            return $request->input('export') === 'xlsx'
                ? $this->downloadXlsx($category, $report, $result)
                : $this->streamCsv($category, $report, $result);
        }

        return view('reports.index', [
            'catalog' => $catalog,
            'selectedCategory' => $category,
            'selectedReport' => $report,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'filters' => $filters,
            'perPage' => $perPage,
            'printMode' => $printMode,
            'printRowCap' => self::PRINT_ROW_CAP,
            'canSeeAllBranches' => $canSeeAllBranches,
            'canExport' => $user === null || $user->hasPermission('reports.export'),
            'branchLocked' => ! $canSeeAllBranches && $user->reportBranchIds(null)->isEmpty(),
            'branches' => DB::table('branches')
                ->when(! $canSeeAllBranches, fn ($query) => $query->whereIn('id', $user->reportBranchIds(null)->all()))
                ->orderBy('code')->get(['id', 'code', 'name_th']),
            'salesAreas' => DB::table('sales_areas')
                ->where('is_active', true)
                ->when($requestedBranch !== null, fn ($query) => $query->where('branch_id', $requestedBranch))
                ->when(! $canSeeAllBranches, fn ($query) => $query->whereIn('branch_id', $user->reportBranchIds(null)->all()))
                ->orderBy('code')->get(['id', 'code', 'name']),
            'bplusReportFilters' => in_array($report, ['bplus_credit_sales_by_document', 'bplus_credit_sales_by_product'], true)
                ? $this->bplusReportFilterOptions($filters)
                : ['salesmen' => collect(), 'customers' => collect()],
            'statementCustomers' => $report === 'ar_statement'
                ? DB::table('customers')
                    ->whereExists(function ($query) use ($requestedBranch, $canSeeAllBranches, $user) {
                        $query->selectRaw('1')->from('documents as sd')
                            ->join('document_types as sdt', 'sdt.id', '=', 'sd.document_type_id')
                            ->whereColumn('sd.customer_id', 'customers.id')
                            ->whereIn('sdt.code', ['OPENING_AR', 'CREDIT_SALE', 'RECEIPT'])
                            ->where('sd.status', 'active')->whereNull('sd.cancelled_at')
                            ->when($requestedBranch !== null, fn ($q) => $q->where('sd.branch_id', $requestedBranch))
                            ->when(! $canSeeAllBranches, fn ($q) => $q->whereIn('sd.branch_id', $user->reportBranchIds(null)->all()));
                    })->orderBy('code')->get(['id', 'code', 'name_th'])
                : collect(),
            'result' => app(ReportScope::class)->redact($this->runReport($category, $report, $from, $to, $filters)),
        ]);
    }

    private function reportSalesmanFilter(mixed $value): int|string|null
    {
        if ($value === 'unassigned') {
            return 'unassigned';
        }

        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    private function bplusReportFilterOptions(array $filters): array
    {
        $creditSaleIds = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('d.source_system', 'bplus')
            ->where('dt.code', 'CREDIT_SALE')
            ->where('d.status', 'active')
            ->whereNull('d.cancelled_at');
        $this->applyBranch($creditSaleIds, $filters, 'd.branch_id');

        return [
            'salesmen' => DB::table('salesmen as s')
                ->whereIn('s.id', (clone $creditSaleIds)->whereNotNull('d.salesman_id')->select('d.salesman_id'))
                ->orderBy('s.name')->get(['s.id', 's.code', 's.name']),
            'customers' => DB::table('customers as c')
                ->whereIn('c.id', (clone $creditSaleIds)->whereNotNull('d.customer_id')->select('d.customer_id'))
                ->orderBy('c.code')->get(['c.id', 'c.code', 'c.name_th']),
        ];
    }

    /**
     * เพดานแถวตอนส่งออก
     *
     * ไม่ปล่อยไม่จำกัด เพราะรายงานบางตัวกวาดทั้งตารางได้ แต่ต้องสูงพอให้รายงาน
     * ภาษีทั้งเดือนออกครบ ถ้าชนเพดานจะมีบรรทัดบอกท้ายไฟล์ ไม่ตัดเงียบ ๆ
     */
    private const EXPORT_ROW_CAP = 50000;

    /** เพดานแถวตอนสั่งพิมพ์ — สูงกว่าหน้าจอ แต่ไม่ถึงขั้นพิมพ์พันหน้าโดยไม่ตั้งใจ */
    private const PRINT_ROW_CAP = 2000;

    /**
     * ส่งออกเป็น .xlsx จริง ไม่ใช่ CSV เปลี่ยนนามสกุล
     *
     * ต่างกันตรงที่ตัวเลขมาเป็นตัวเลขให้ Excel บวกได้ทันที และวันที่เรียงได้ถูก
     * ส่วน CSV ทุกอย่างเป็นข้อความ ใครเปิดมาก็ต้องแปลงเองก่อนใช้
     *
     * @param  array<string, mixed>  $result
     */
    private function downloadXlsx(string $category, string $report, array $result): BinaryFileResponse
    {
        $temporary = tempnam(sys_get_temp_dir(), 'report-');
        $exportRows = $this->exportRows($result);
        if ($exportRows->count() >= self::EXPORT_ROW_CAP) {
            $exportRows->push([(data_get($result, 'columns.0.key') ?? 'notice') => 'ถึงเพดาน 50,000 แถว — กรุณาแบ่งช่วงวันที่']);
        }
        $this->writeWorkbook($temporary, $result, $exportRows);

        return response()
            ->download($temporary, sprintf('%s-%s-%s.xlsx', $category, $report, now()->format('Ymd-His')))
            ->deleteFileAfterSend();
    }

    public function packageReport(string $key, Carbon $from, Carbon $end, object $area): array
    {
        $filters = ['branch_id' => $area->branch_id, 'sales_area_id' => $area->id, 'q' => '', 'per_page' => PHP_INT_MAX,
            'paginate' => false, 'returns_only' => $key === 'sales_returns_by_document'];
        return app(ReportScope::class)->redact($this->runReport('', $key, $from, $end, $filters));
    }

    public function writeWorkbook(string $path, array $result, ?Collection $rows = null): void
    {
        app(XlsxWriter::class)->write($path, $result['title'], $result['columns'], $rows ?? $this->exportRows($result));
    }

    /** @param  array<string, mixed>  $result */
    private function streamCsv(string $category, string $report, array $result): StreamedResponse
    {
        $columns = $result['columns'] ?? [];
        $rows = $this->exportRows($result);
        $filename = sprintf('%s-%s-%s.csv', $category, $report, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($columns, $rows) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");   // BOM ให้ Excel อ่านภาษาไทยออก

            fputcsv($handle, array_map(fn ($column) => $column['label'] ?? $column['key'] ?? '', $columns));
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $key = $column['key'] ?? null;
                    $line[] = $key === null ? '' : (data_get($row, $key) ?? '');
                }
                fputcsv($handle, $line);
            }

            if ($rows->count() >= self::EXPORT_ROW_CAP) {
                fputcsv($handle, ['*** ถึงเพดาน '.number_format(self::EXPORT_ROW_CAP).' แถว — ข้อมูลอาจไม่ครบ กรุณาแบ่งช่วงวันที่ ***']);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Report-Title' => rawurlencode((string) ($result['title'] ?? '')),
        ]);
    }

    private function exportRows(array $result): Collection
    {
        return collect($result['rows'] ?? [])->map(function ($row) use ($result) {
            $values = (array) $row;
            foreach ($result['columns'] as $column) {
                if (($column['type'] ?? '') === 'money' && data_get($row, $column['key']) === null) {
                    $values[$column['key']] = 'ไม่ทราบ';
                }
            }
            return $values;
        });
    }

    private function catalogForUser(array $catalog, Collection $definitions): array
    {
        $visible = [];
        foreach ($catalog as $category => $group) {
            $reports = [];
            foreach ($group['reports'] as $report => $label) {
                if ($this->canSeeReport($category, $report, $definitions)) {
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

    /** สิทธิ์ที่ต้องมีเพื่อ "ดู" รายงานตัวนั้น มาจากทะเบียนรายงาน ไม่ใช่ match ที่ hardcode ไว้ */
    private function canSeeReport(string $category, string $report, Collection $definitions): bool
    {
        $user = auth()->user();
        if (! $user) {
            return true;
        }

        if ($category === 'sales' && in_array($report, ['daily_sales', 'daily_by_channel', 'sales_by_branch', 'sales_by_staff'], true) && app(ReportScope::class)->canSeeProfit()) {
            return true;
        }
        if ($category === 'management' || $report === 'legacy_sales_profit') {
            return app(ReportScope::class)->canSeeProfit();
        }

        if ($user->hasPermission('settings.manage') || $user->hasPermission('users.manage')) {
            return true;
        }

        return $user->hasPermission($definitions->get($category.'.'.$report)?->view_permission ?? 'reports.view');
    }

    private const CATEGORY_ICONS = [
        'sales' => 'bi-receipt-cutoff',
        'management' => 'bi-graph-up-arrow',
        'ar' => 'bi-person-lines-fill',
        'ap' => 'bi-person-badge',
        'inventory' => 'bi-box-seam-fill',
        'documents' => 'bi-files',
        'booking' => 'bi-journal-bookmark',
        'pos' => 'bi-cart-check-fill',
        'purchasing' => 'bi-basket-fill',
        'transfer' => 'bi-arrow-left-right',
        'payment' => 'bi-cash-coin',
        'cash' => 'bi-bank',
        'tax' => 'bi-receipt',
        'audit' => 'bi-shield-check',
        'custom' => 'bi-stars',
    ];

    /**
     * ทะเบียนรายงานที่เปิดใช้งานอยู่และมีหน้าจอจริง (status = available)
     *
     * อ่านสดทุกครั้งและส่งต่อเป็นพารามิเตอร์ ไม่เก็บไว้ใน property ของ controller —
     * ภายใต้ worker ที่ใช้ instance ซ้ำ (Octane/เทสต์) ค่าที่ cache ไว้จะค้างข้ามคำขอ
     * ทำให้รายงานที่เพิ่งถูกปิดยังโผล่ในเมนูอยู่
     */
    private function definitions(): Collection
    {
        return ReportDefinition::runnable()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('code');
    }

    /**
     * รายการรายงานสำหรับเมนู — มาจากตาราง `report_definitions` เพื่อให้ผู้บริหาร
     * เปิด/ปิดได้เองโดยไม่ต้องแก้โค้ด รายงานที่ถูกปิดจะหายจากเมนูแต่ definition ยังอยู่
     */
    private function catalog(Collection $definitions): array
    {
        $catalog = [];
        foreach ($definitions as $definition) {
            $catalog[$definition->category] ??= [
                'title' => $definition->category_title,
                'icon' => self::CATEGORY_ICONS[$definition->category] ?? 'bi-file-earmark-bar-graph',
                'reports' => [],
            ];
            $catalog[$definition->category]['reports'][$definition->reportKey()] = $definition->name;
        }

        return $catalog;
    }

    private function runReport(string $category, string $report, Carbon $from, Carbon $to, array $filters): array
    {
        if (in_array($report, ['daily_sales', 'daily_by_channel', 'legacy_daily_pos', 'legacy_daily_summary', 'sales_by_booking', 'stock_balance', 'stock_card_detail', 'ar_aging', 'received_and_unidentified', 'delivery_status'], true)) {
            abort_unless(config('reports.phase_two_enabled'), 503, 'รายงาน Phase 2 ปิดชั่วคราว');
        }
        if (in_array($report, ['daily_sales', 'daily_by_channel', 'legacy_daily_pos', 'legacy_daily_summary'], true)) {
            $data = app(\App\Services\Reports\DailySalesReport::class)->run($from, $to, $report === 'legacy_daily_pos' ? ['channel' => 'POS'] + $filters : $filters);
            return $data + ['title' => 'ยอดขายรายวัน แยกช่องทาง (สุทธิหลังคืน)', 'columns' => [
                ['label' => 'วันที่', 'key' => 'sale_date'],
                ['label' => 'ช่องทาง', 'key' => 'channel_name'],
                ['label' => 'บิล/รายการปรับ', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดรวม VAT', 'key' => 'net_sales', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'VAT', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต้นทุน', 'key' => 'cogs_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'กำไรขั้นต้น (ไม่รวม VAT)', 'key' => 'gross_profit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ข้อมูลกำไรไม่ครบ (รายการ)', 'key' => 'missing_cost_count', 'type' => 'number', 'class' => 'text-end'],
            ]];
        }

        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        return match ($report) {
            'delivery_status' => $this->pagedResult('จัดส่งตามวันที่ใบจอง — เงินเก็บปลายทางไม่ใช่รายได้เพิ่ม', [
                ['label' => 'ใบจอง', 'key' => 'doc_number'],
                ['label' => 'วันที่จอง', 'key' => 'doc_date'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'กำหนดส่ง', 'key' => 'delivery_due_at'],
                ['label' => 'สถานะขนส่ง', 'key' => 'transport_status'],
                ['label' => 'สถานะส่ง', 'key' => 'delivery_status'],
                ['label' => 'ขึ้นรถจริง', 'key' => 'loaded_at'],
                ['label' => 'ส่งจริง', 'key' => 'delivered_at'],
                ['label' => 'ช้า (วัน)', 'key' => 'late_days', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'เก็บเงินปลายทาง', 'key' => 'paid_amount', 'type' => 'money', 'class' => 'text-end'],
            ], app(\App\Services\Reports\DeliveryReport::class)->rows($from, $to, $filters), $filters),
            'legacy_salesman' => $this->tableResult('ตัวอย่างเดิม: ยอดขายตามพนักงาน', [
                ['label' => 'พนักงาน', 'key' => 'staff_name'],
                ['label' => 'ช่องทาง', 'key' => 'channel', 'type' => 'badge'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByStaff($fromStart, $toEnd, $filters)),

            'legacy_sales_profit' => $this->tableResult('ตัวอย่างเดิม: วิเคราะห์กำไรตามสินค้า', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'จำนวนขาย', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'sales_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ต้นทุน', 'key' => 'cost_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'กำไรขั้นต้น', 'key' => 'gross_profit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'รายการไม่มีทุนบันทึกไว้', 'key' => 'missing_cost_count', 'type' => 'number', 'class' => 'text-end'],
            ], $this->grossMargin($fromStart, $toEnd, $filters)),

            'legacy_reorder' => $this->tableResult('ตัวอย่างเดิม: เติมเต็ม/แผนสต๊อก', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'ตำแหน่ง', 'key' => 'location_name'],
                ['label' => 'คงเหลือ', 'key' => 'on_hand_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จองแล้ว', 'key' => 'reserved_qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->stockAlerts($filters)),

            'legacy_sales_return' => $this->tableResult('ตัวอย่างเดิม: ขาย-รับคืนตามเอกสาร', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'ประเภท', 'key' => 'document_type', 'type' => 'badge'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesReturnsByDocument($from, $to, $filters)),

            'vat_sales' => $this->tableResult('รายงานภาษีขาย', [
                ['label' => 'วันที่ใบกำกับ', 'key' => 'doc_date'],
                ['label' => 'เลขที่ใบกำกับภาษี', 'key' => 'doc_number'],
                ['label' => 'ชื่อผู้ซื้อ', 'key' => 'party_name'],
                ['label' => 'เลขผู้เสียภาษี', 'key' => 'tax_id'],
                ['label' => 'มูลค่าฐานภาษี', 'key' => 'base_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ภาษีมูลค่าเพิ่ม', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'รวม', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
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

            // ---- รายงาน P0 ----
            'outstanding' => $this->tableResult('ใบจองคงค้างและสถานะส่งสินค้า', $this->bookingColumns(), $this->bookingsOutstanding($filters, dueOnly: false)),
            'due' => $this->tableResult('ใบจองครบกำหนด/เกินกำหนดส่ง', $this->bookingColumns(), $this->bookingsOutstanding($filters, dueOnly: true)),

            'by_branch_seller' => $this->tableResult('ใบจองตามสาขา/สายขาย/พนักงานขาย', [
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
                ['label' => 'ใบจอง', 'key' => 'booking_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ค้างส่ง', 'key' => 'outstanding_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'มูลค่า', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->bookingsByBranchSeller($fromStart, $toEnd, $filters)),

            'outstanding_detail' => $this->tableResult('รายละเอียดยอดเจ้าหนี้คงค้าง', [
                ['label' => 'ผู้ขาย', 'key' => 'supplier_name'],
                ['label' => 'เลขที่', 'key' => 'document_no'],
                ['label' => 'วันที่', 'key' => 'document_date', 'type' => 'date'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date', 'type' => 'date'],
                ['label' => 'เกินกำหนด (วัน)', 'key' => 'days_overdue', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดเต็ม', 'key' => 'original_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'จ่ายแล้ว', 'key' => 'paid_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'คงค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->apOutstanding($filters)),

            'aging' => $this->tableResult('อายุหนี้เจ้าหนี้ (AP aging)', [
                ['label' => 'ผู้ขาย', 'key' => 'supplier_name'],
                ['label' => 'ยังไม่ถึงกำหนด', 'key' => 'current_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => '1-30 วัน', 'key' => 'days_1_30', 'type' => 'money', 'class' => 'text-end'],
                ['label' => '31-60 วัน', 'key' => 'days_31_60', 'type' => 'money', 'class' => 'text-end'],
                ['label' => '61-90 วัน', 'key' => 'days_61_90', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'เกิน 90 วัน', 'key' => 'over_90', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'รวม', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->apAging($filters)),

            'daily_cash_book' => $this->tableResult('สมุดเงินสดรายวัน', [
                ['label' => 'วันที่', 'key' => 'entry_date', 'type' => 'date'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'ที่มา', 'key' => 'source_type', 'type' => 'badge'],
                ['label' => 'รายการ', 'key' => 'description'],
                ['label' => 'รับ', 'key' => 'cash_in', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'จ่าย', 'key' => 'cash_out', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'คงเหลือ', 'key' => 'running_balance', 'type' => 'money', 'class' => 'text-end'],
            ], $this->dailyCashBook($fromStart, $toEnd, $filters)),

            'bank_summary' => $this->tableResult('สรุปยอดธนาคารตามบัญชี', [
                ['label' => 'ธนาคาร', 'key' => 'bank_name'],
                ['label' => 'เลขบัญชี', 'key' => 'account_no'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'รายการ', 'key' => 'line_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'เงินเข้า', 'key' => 'money_in', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'เงินออก', 'key' => 'money_out', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยังไม่กระทบยอด', 'key' => 'unreconciled_count', 'type' => 'number', 'class' => 'text-end'],
            ], $this->bankSummary($fromStart, $toEnd, $filters)),

            'bank_reconciliation' => $this->tableResult('รายการกระทบยอด statement', [
                ['label' => 'วันที่', 'key' => 'statement_date', 'type' => 'date'],
                ['label' => 'ธนาคาร', 'key' => 'bank_name'],
                ['label' => 'รายการ', 'key' => 'description'],
                ['label' => 'ยอด statement', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดที่คาด', 'key' => 'expected_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ผลต่าง', 'key' => 'difference_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'อ้างอิง', 'key' => 'reference'],
            ], $this->bankReconciliationLines($fromStart, $toEnd, $filters)),

            'received_and_unidentified' => $this->tableResult('การรับชำระ POS/หลังบ้าน — รอพิสูจน์เป็นยอดย่อย ห้ามบวกรวม', [
                ['label' => 'กลุ่ม', 'key' => 'bucket', 'type' => 'badge'],
                ['label' => 'ช่องทาง', 'key' => 'method'],
                ['label' => 'รายการ', 'key' => 'line_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->receivedAndUnidentified($fromStart, $toEnd, $filters)),

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
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ช่องทาง', 'key' => 'channel', 'type' => 'badge'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดขาย', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByCategory($fromStart, $toEnd, $filters)),

            'sales_by_seller' => $this->tableResult('ยอดขายตามคนขาย', [
                ['label' => 'คนขาย', 'key' => 'seller_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
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
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
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
                ['label' => 'รายการไม่มีทุนบันทึกไว้', 'key' => 'missing_cost_count', 'type' => 'number', 'class' => 'text-end'],
            ], $this->grossMargin($fromStart, $toEnd, $filters)),

            'credit_sales' => $this->tableResult('ใบขายเชื่อ', [
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->creditSales($from, $to, $filters)),

            'bplus_credit_sales_by_document' => $this->bplusCreditSalesByDocument($from, $to, $filters),

            'bplus_credit_sales_by_product' => $this->bplusCreditSalesByProduct($from, $to, $filters),

            'bplus_daily_sales_summary' => $this->bplusDailySalesSummary($from, $to, $filters),

            'pending_bookings' => $this->tableResult('ใบจองค้างแปลงขาย', [
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ยอดเงิน', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->pendingBookings($from, $to, $filters)),

            'sales_by_booking' => $this->pagedResult('ยอดขายตามใบจอง', [
                ['label' => 'ใบจอง', 'key' => 'booking_no'],
                ['label' => 'วันที่จอง', 'key' => 'doc_date'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'ใบขายที่แปลง', 'key' => 'sale_doc_no'],
                ['label' => 'ยอดขายที่แปลงรวม', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดใบจอง (ไม่ใช่รายได้)', 'key' => 'booking_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'A / DS มี VAT', 'key' => 'ds_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'B / DSN ไม่มี VAT', 'key' => 'dsn_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ขายไม่ทราบเล่ม A/B', 'key' => 'unclassified_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'VAT บันทึกไว้', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'จองลบขาย', 'key' => 'variance', 'type' => 'money', 'class' => 'text-end'],
            ], $this->salesByBooking($from, $to, $filters), $filters),

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
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
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

            'loss_sales' => $this->tableResult('สินค้าขายต่ำกว่าทุน / ขาดทุน (เฉพาะรายการมีต้นทุนบันทึกไว้)', [
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
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ใบค้าง', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ครบกำหนดเก่าสุด', 'key' => 'oldest_due_date'],
                ['label' => 'ยอดเกินกำหนด', 'key' => 'overdue_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดค้างรวม', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arSummary($filters)),

            'ar_summary_bplus' => $this->tableResult('รายงานสรุปยอดลูกหนี้', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ใบค้าง', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ครบกำหนดเก่าสุด', 'key' => 'oldest_due_date'],
                ['label' => 'ยอดเกินกำหนด', 'key' => 'overdue_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดค้างรวม', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arSummary($filters)),

            'ar_aging' => $this->tableResult('อายุหนี้ AR Aging — ยอดคงค้างปัจจุบัน ณ '.today()->toDateString(), [
                ['label' => 'ช่วงอายุหนี้', 'key' => 'bucket'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'จำนวนเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arAging($filters)),

            'overdue_customers' => $this->tableResult('ลูกหนี้เกินกำหนด', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงาน', 'key' => 'salesman_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'บิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ครบกำหนดเก่าสุด', 'key' => 'oldest_due_date'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->overdueCustomers($filters)),

            'open_items' => $this->tableResult('ลูกหนี้คงค้าง', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->openItems($filters)),

            'ar_detail_short' => $this->tableResult('รายงานรายละเอียดยอดลูกหนี้ แบบย่อ', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arDetails($filters, false)),

            'ar_detail_full' => $this->tableResult('รายงานรายละเอียดยอดลูกหนี้ แบบละเอียด', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
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
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'ครบกำหนด', 'key' => 'due_date'],
                ['label' => 'เกินกำหนด', 'key' => 'overdue_days', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arOverdueDetails($filters)),

            'ar_statement' => $this->arStatement($from, $to, $filters),

            'ar_over_credit_limit' => $this->tableResult('รายงานรายละเอียดลูกหนี้เกินวงเงินเครดิต', [
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'วงเงินเครดิต', 'key' => 'credit_limit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ยอดค้าง', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'เกินวงเงิน', 'key' => 'over_limit_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->arOverCreditLimit($filters)),

            'stock_balance' => app(StockBalanceReport::class)->run($filters),

            'stock_by_branch' => $this->tableResult('สต็อกตามสาขา', [
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'รายการสินค้า', 'key' => 'product_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'คงเหลือรวม', 'key' => 'on_hand_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จองรวม', 'key' => 'reserved_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'มูลค่าคงเหลือ', 'key' => 'stock_value', 'type' => 'money', 'class' => 'text-end'],
            ], $this->stockByBranch($filters)),

            'stock_alerts' => $this->tableResult('สต็อกต่ำ / ติดลบ', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'คงเหลือ (หน่วยหลัก / ย่อย)', 'key' => 'quantity_display', 'class' => 'text-end'],
                ['label' => 'ราคาต่อหน่วย', 'key' => 'unit_price', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'มูลค่าคงเหลือ', 'key' => 'stock_value', 'type' => 'money', 'class' => 'text-end'],
            ], $this->stockAlerts($filters)),

            'expiring_stock' => $this->tableResult('Lot ใกล้หมดอายุ / หมดอายุ', [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'Lot', 'key' => 'lot_number'],
                ['label' => 'คลัง/ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'วันผลิต', 'key' => 'manufacture_date'],
                ['label' => 'วันหมดอายุ', 'key' => 'expiry_date'],
                ['label' => 'เหลือ (วัน)', 'key' => 'days_left', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'คงเหลือ', 'key' => 'remaining_qty', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
                ['label' => 'ส่วนลดระบายแนะนำ', 'key' => 'clearance_suggestion'],
            ], $this->expiringStock($filters)),

            'stock_movements' => $this->tableResult('เคลื่อนไหวสินค้า', [
                ['label' => 'วันที่', 'key' => 'movement_date'],
                ['label' => 'สินค้า', 'key' => 'product_name'],
                ['label' => 'ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'ประเภท', 'key' => 'movement_type', 'type' => 'badge'],
                ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
            ], $this->stockMovements($from, $to, $filters)),

            'stock_card_detail' => $this->pagedResult('Stock Card รายละเอียด', [
                ['label' => 'วันที่', 'key' => 'movement_date'],
                ['label' => 'เวลา', 'key' => 'movement_time'],
                ['label' => 'สินค้า', 'key' => 'product_name'],
                ['label' => 'คลัง / ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'Lot', 'key' => 'lot_number'],
                ['label' => 'เอกสาร', 'key' => 'doc_number'],
                ['label' => 'ประเภท', 'key' => 'movement_type', 'type' => 'badge'],
                ['label' => 'ยอดก่อน', 'key' => 'balance_before', 'type' => 'quantity', 'class' => 'text-end'],
                ['label' => 'รับเข้า', 'key' => 'qty_in', 'type' => 'quantity', 'class' => 'text-end'],
                ['label' => 'จ่ายออก', 'key' => 'qty_out', 'type' => 'quantity', 'class' => 'text-end'],
                ['label' => 'ยอดหลัง', 'key' => 'balance_after', 'type' => 'quantity', 'class' => 'text-end'],
                ['label' => 'ทุน Lot ปัจจุบัน/หน่วย', 'key' => 'unit_cost', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ผู้ทำรายการ', 'key' => 'created_by_name'],
            ], $this->stockCardDetail($from, $to, $filters), $filters),

            'documents_summary' => $this->tableResult('สรุปเอกสาร', [
                ['label' => 'ประเภทเอกสาร', 'key' => 'document_type'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'จำนวนเอกสาร', 'key' => 'document_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'จำนวนรายการ', 'key' => 'item_count', 'type' => 'number', 'class' => 'text-end'],
                ['label' => 'ยอดเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->documentsSummary($from, $to, $filters)),

            'document_list' => $this->tableResult('รายการเอกสารทั้งหมด', [
                ['label' => 'วันที่', 'key' => 'doc_date'],
                ['label' => 'ประเภทเอกสาร', 'key' => 'document_type'],
                ['label' => 'เลขที่', 'key' => 'doc_number'],
                ['label' => 'สาขา', 'key' => 'branch_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
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
                ['label' => 'วันที่/เวลา', 'key' => 'receipt_date'],
                ['label' => 'เครื่อง', 'key' => 'terminal_name'],
                ['label' => 'เลขท้ายบัญชีผู้โอน', 'key' => 'transfer_account_last4'],
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
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'สถานะ', 'key' => 'status', 'type' => 'badge'],
            ], $this->paymentDocuments($from, $to, $filters)),

            'payment_allocations' => $this->tableResult('ตัดหนี้ / จัดสรรยอด', [
                ['label' => 'เอกสารรับชำระ', 'key' => 'doc_number'],
                ['label' => 'ลูกค้า', 'key' => 'customer_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'ยอดจัดสรร', 'key' => 'allocated_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'ส่วนลด', 'key' => 'discount_amount', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'หัก ณ ที่จ่าย', 'key' => 'wht_amount', 'type' => 'money', 'class' => 'text-end'],
            ], $this->paymentAllocations($from, $to, $filters)),

            'bplus_receipt_allocations' => $this->bplusReceiptAllocations($from, $to, $filters),

            'gl_journals' => $this->tableResult('GL Journal', [
                ['label' => 'วันที่', 'key' => 'entry_date'],
                ['label' => 'บัญชี', 'key' => 'account_name'],
                ['label' => 'สายขาย', 'key' => 'sales_area_name'],
                ['label' => 'เดบิต', 'key' => 'debit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'เครดิต', 'key' => 'credit', 'type' => 'money', 'class' => 'text-end'],
                ['label' => 'หมายเหตุ', 'key' => 'remark'],
            ], $this->glJournals($from, $to, $filters)),

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

    /* ==================== รายงาน P0 ==================== */

    /**
     * ยอดขายรายวันแยกช่องทาง — อ่านจาก `sales_postings` ซึ่งเป็นบัญชีขายฉบับจริง
     * ที่กันบิล POS กับเอกสารขายสดที่ผูกกันไม่ให้นับซ้ำอยู่แล้ว
     */
    private function bookingColumns(): array
    {
        return [
            ['label' => 'เลขที่', 'key' => 'doc_number'],
            ['label' => 'วันที่จอง', 'key' => 'doc_date', 'type' => 'date'],
            ['label' => 'ลูกค้า', 'key' => 'customer_name'],
            ['label' => 'สาขา', 'key' => 'branch_name'],
            ['label' => 'กำหนดส่ง', 'key' => 'delivery_due_at'],
            ['label' => 'เกินกำหนด (วัน)', 'key' => 'days_overdue', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'สถานะส่ง', 'key' => 'delivery_status', 'type' => 'badge'],
            ['label' => 'มูลค่า', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
        ];
    }

    /**
     * ใบจองที่ยังต้องตามส่ง
     *
     * นับเฉพาะใบที่ยืนยันเป็นใบขายแล้วและยังส่งไม่ครบ ตามที่เจ้าของกำหนด —
     * ใบที่ยังไม่ยืนยันยังไม่ถือว่าค้างส่ง เพราะยังไม่ได้ตกลงขายจริง
     * และเทียบ `delivery_due_at` กับเวลาปัจจุบัน ไม่ใช่ due_date ของลูกหนี้
     */
    private function bookingsOutstanding(array $filters, bool $dueOnly): Collection
    {
        $query = DB::table('sale_bookings as sb')
            ->join('documents as d', 'd.id', '=', 'sb.document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->where('sb.fulfillment_type', 'delivery')
            ->where('sb.status', 'converted_to_sale')
            ->whereIn('sb.delivery_status', ['pending', 'partial']);

        if ($dueOnly) {
            $query->whereNotNull('sb.delivery_due_at')->where('sb.delivery_due_at', '<=', now());
        }

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.name_th', 'b.name_th']);

        return $query
            ->orderBy('sb.delivery_due_at')
            ->selectRaw("d.doc_number, d.doc_date,
                coalesce(c.name_th, '-') as customer_name,
                coalesce(b.name_th, '-') as branch_name,
                sb.delivery_due_at,
                sb.delivery_status,
                d.total_amount as amount")
            ->limit($filters['per_page'])
            ->get()
            ->map(function ($row) {
                $due = $row->delivery_due_at ? Carbon::parse($row->delivery_due_at) : null;
                $row->days_overdue = $due && $due->isPast() ? $due->diffInDays(now()) : 0;
                $row->delivery_due_at = $due?->format('d/m/Y H:i') ?? '-';
                $row->delivery_status = ['pending' => 'ยังไม่ส่ง', 'partial' => 'ส่งบางส่วน'][$row->delivery_status] ?? $row->delivery_status;

                return $row;
            });
    }

    private function bookingsByBranchSeller(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('sale_bookings as sb')
            ->join('documents as d', 'd.id', '=', 'sb.document_id')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->leftJoin('salesmen as sm', 'sm.id', '=', 'd.salesman_id')
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');

        return $query
            ->groupBy('b.name_th', 'sa.code', 'sa.name', 'd.salesman_id', 'sm.name')
            ->orderByDesc(DB::raw('sum(d.total_amount)'))
            ->selectRaw("coalesce(b.name_th, 'ไม่ระบุสาขา') as branch_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                coalesce(sm.name, 'ไม่ระบุ') as salesman_name,
                count(*) as booking_count,
                sum(case when sb.fulfillment_type = 'delivery' and sb.delivery_status in ('pending', 'partial') then 1 else 0 end) as outstanding_count,
                sum(d.total_amount) as amount")
            ->limit($filters['per_page'])
            ->get();
    }

    /** ยอดเจ้าหนี้ค้างรายใบ — อ่านจาก supplier_open_items ไม่ใช่ supplier_ledger */
    private function apOutstanding(array $filters): Collection
    {
        return DB::table('supplier_open_items as oi')
            ->join('suppliers as s', 's.id', '=', 'oi.supplier_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->whereIn('oi.source_document_id', DB::table('documents')->where('branch_id', $filters['branch_id'])->select('id')))
            ->orderBy('oi.due_date')->orderBy('oi.document_date')
            ->selectRaw('s.name_th as supplier_name, oi.document_no, oi.document_date, oi.due_date,
                oi.original_amount, oi.paid_amount, oi.balance_amount')
            ->limit($filters['per_page'])
            ->get()
            ->map(function ($row) {
                $due = $row->due_date ? Carbon::parse($row->due_date) : null;
                $row->days_overdue = $due && $due->isPast() ? $due->diffInDays(now()) : 0;

                return $row;
            });
    }

    private function apAging(array $filters): Collection
    {
        $today = $this->dateMinusDays(0);
        $d30 = $this->dateMinusDays(30);
        $d60 = $this->dateMinusDays(60);
        $d90 = $this->dateMinusDays(90);

        return DB::table('supplier_open_items as oi')
            ->join('suppliers as s', 's.id', '=', 'oi.supplier_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->whereIn('oi.source_document_id', DB::table('documents')->where('branch_id', $filters['branch_id'])->select('id')))
            ->groupBy('s.name_th')
            ->orderByDesc(DB::raw('sum(oi.balance_amount)'))
            ->selectRaw("s.name_th as supplier_name,
                sum(case when oi.due_date is null or oi.due_date >= {$today} then oi.balance_amount else 0 end) as current_amount,
                sum(case when oi.due_date < {$today} and oi.due_date >= {$d30} then oi.balance_amount else 0 end) as days_1_30,
                sum(case when oi.due_date < {$d30} and oi.due_date >= {$d60} then oi.balance_amount else 0 end) as days_31_60,
                sum(case when oi.due_date < {$d60} and oi.due_date >= {$d90} then oi.balance_amount else 0 end) as days_61_90,
                sum(case when oi.due_date < {$d90} then oi.balance_amount else 0 end) as over_90,
                sum(oi.balance_amount) as total_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function dailyCashBook(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('cash_books as cb')
            ->leftJoin('branches as b', 'b.id', '=', 'cb.branch_id')
            ->whereBetween('cb.entry_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($query, $filters, 'cb.branch_id');

        return $query
            ->orderBy('cb.entry_date')->orderBy('cb.id')
            ->selectRaw("cb.entry_date, coalesce(b.name_th, 'ส่วนกลาง') as branch_name,
                cb.source_type, cb.description, cb.cash_in, cb.cash_out, cb.running_balance")
            ->limit($filters['per_page'])
            ->get();
    }

    private function bankSummary(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('bank_accounts as ba')
            ->leftJoin('branches as b', 'b.id', '=', 'ba.branch_id')
            ->leftJoin('bank_statements as bs', function ($join) use ($from, $to) {
                $join->on('bs.bank_account_id', '=', 'ba.id')
                    ->whereBetween('bs.statement_date', [$from->toDateString(), $to->toDateString()]);
            });
        $this->applyBranch($query, $filters, 'ba.branch_id');

        return $query
            ->groupBy('ba.bank_name', 'ba.account_no', 'b.name_th')
            ->orderBy('ba.bank_name')
            ->selectRaw("ba.bank_name, ba.account_no, coalesce(b.name_th, 'ส่วนกลาง') as branch_name,
                count(bs.id) as line_count,
                sum(case when bs.amount > 0 then bs.amount else 0 end) as money_in,
                sum(case when bs.amount < 0 then -bs.amount else 0 end) as money_out,
                sum(case when bs.id is not null and bs.reconciled = false then 1 else 0 end) as unreconciled_count")
            ->limit($filters['per_page'])
            ->get();
    }

    private function bankReconciliationLines(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('bank_statements as bs')
            ->join('bank_accounts as ba', 'ba.id', '=', 'bs.bank_account_id')
            ->leftJoin('bank_reconciliations as br', 'br.bank_statement_id', '=', 'bs.id')
            ->whereBetween('bs.statement_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($query, $filters, 'ba.branch_id');

        return $query
            ->orderBy('bs.statement_date')->orderBy('bs.id')
            ->selectRaw("bs.statement_date, ba.bank_name, bs.description, bs.amount,
                br.expected_amount, br.difference_amount,
                coalesce(br.status, 'ยังไม่กระทบยอด') as status,
                coalesce(br.reference, '-') as reference")
            ->limit($filters['per_page'])
            ->get();
    }

    /**
     * รับชำระและยอดรอพิสูจน์
     *
     * "รอพิสูจน์" = เงินที่เข้ามาแล้วแต่ยังจับคู่ไม่ได้ — โอน/QR ที่ยังไม่มีใครกระทบยอด
     * ยอดกลุ่มนี้ยิ่งค้างนานยิ่งเสี่ยงว่าจะเป็นเงินที่หายไปหรือรับซ้ำ
     */
    private function receivedAndUnidentified(Carbon $from, Carbon $to, array $filters): Collection
    {
        return app(\App\Services\Reports\PaymentReport::class)->rows($from, $to, $filters);
    }

    private function pagedResult(string $title, array $columns, Collection $rows, array $filters): array
    {
        $total = $rows->count();
        $summary = ['net_sales' => DecimalMath::sum($rows->pluck('total_amount'), 2), 'vat_amount' => DecimalMath::sum($rows->pluck('vat_amount'), 2)];
        $pagination = null;
        if ($filters['paginate'] ?? false) {
            $pagination = new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($filters['page'], $filters['per_page'])->values(), $total, $filters['per_page'], $filters['page'], ['path' => request()->url(), 'query' => request()->query()]);
            $rows = $pagination->getCollection();
        } else {
            $rows = $rows->take($filters['per_page']);
        }
        return compact('title', 'columns', 'rows', 'total', 'summary', 'pagination');
    }

    private function tableResult(string $title, array $columns, Collection $rows): array
    {
        $summaryRows = $rows->reject(fn ($r) => data_get($r, 'party_name') === 'รวมทั้งสิ้น' || data_get($r, 'customer_name') === 'รวมทั้งสิ้น'
            || in_array(data_get($r, 'status'), ['cancelled', 'void'], true));
        if ($rows->contains(fn ($r) => data_get($r, 'bucket') === 'รับแล้ว')) {
            $summaryRows = $rows->filter(fn ($r) => data_get($r, 'bucket') === 'รับแล้ว');
        }
        $moneyKey = collect(['net_sales', 'sales_amount', 'total_amount', 'amount', 'balance_amount', 'applied_total'])->first(fn ($key) => collect($columns)->contains('key', $key));
        return [
            'title' => $title, 'columns' => $columns, 'rows' => $rows, 'total' => $rows->count(),
            'summary_scope' => 'displayed',
            'summary' => [
                'net_sales' => $moneyKey ? DecimalMath::sum($summaryRows->map(fn ($r) => data_get($r, $moneyKey)), 2) : '0.00',
                'vat_amount' => DecimalMath::sum($summaryRows->map(fn ($r) => data_get($r, 'vat_amount')), 2),
            ],
        ];
    }

    // สาขาตัวแทนต่อคลัง = สาขา active ที่ id ต่ำสุดที่ใช้คลังนี้เป็นคลังหลัก
    // กันนับซ้ำเมื่อหลายสาขาใช้คลังเดียวกัน (เจริญศรี-1/-2 ใช้คลังเจริญศรีร่วมกัน)
    private function branchByLocationSub(string $locationColumn): string
    {
        return "(select min(b2.id) from branches b2 where b2.default_warehouse_location_id = {$locationColumn} and b2.is_active = true)";
    }

    private function dateMinusDays(int $days): string
    {
        return SqlDialect::dateMinusDays(DB::getDriverName(), $days);
    }

    private function truncateToHour(string $column): string
    {
        return SqlDialect::truncateToHour(DB::getDriverName(), $column);
    }

    private function applyBranch($query, array $filters, string $column = 'b.id')
    {
        if (! empty($filters['branch_id'])) {
            $query->where($column, $filters['branch_id']);
        }

        return $query;
    }

    private function applySalesArea($query, array $filters, string $column = 'd.sales_area_id')
    {
        if (! empty($filters['sales_area_id'])) {
            $query->where($column, $filters['sales_area_id']);
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

    /**
     * รายงานภาษีขาย (ภพ.30): ใบกำกับขายสด/ขายเชื่อ + บิล POS (ใบกำกับอย่างย่อ)
     * และใบรับคืน/ลดหนี้เป็นยอดติดลบ - ราคาในระบบถือเป็นราคารวม VAT
     */
    private function vatSales(Carbon $from, Carbon $to, array $filters): Collection
    {
        $docQuery = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN', 'CREDIT_NOTE', 'DEBIT_NOTE'])
            ->where('d.status', 'active')
            ->whereNull('d.cancelled_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('pos_receipts as linked_pos')->whereColumn('linked_pos.document_id', 'd.id'))
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($docQuery, $filters, 'd.branch_id');
        $this->applySalesArea($docQuery, $filters, 'd.sales_area_id');
        $this->applySearch($docQuery, $filters, ['d.doc_number', 'c.name_th']);

        $docRows = $docQuery->selectRaw(
            "d.doc_date, d.doc_number, dt.code as doc_code,
             COALESCE(c.name_th, 'ลูกค้าทั่วไป') as party_name, c.tax_id, d.subtotal_amount, d.total_amount, d.vat_amount,
             COALESCE(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name"
        )->get()->map(function ($r) {
            $sign = in_array($r->doc_code, ['SALE_RETURN', 'CREDIT_NOTE'], true) ? -1 : 1;
            $total = DecimalMath::multiply($sign, $r->total_amount, 2);
            $vat = DecimalMath::multiply($sign, $r->vat_amount, 2);
            $base = DecimalMath::multiply($sign, $r->subtotal_amount ?? DecimalMath::subtract($r->total_amount, $r->vat_amount, 2), 2);

            return [
                'doc_date' => \Illuminate\Support\Carbon::parse($r->doc_date)->thaiDate(),
                'sort_key' => $r->doc_date.'|'.$r->doc_number,
                'doc_number' => $r->doc_number,
                'party_name' => $r->party_name.($sign < 0 ? ' (รับคืน/ลดหนี้)' : ''),
                'tax_id' => $r->tax_id ?? '-',
                'base_amount' => $base,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'sales_area_name' => $r->sales_area_name,
            ];
        });

        $posQuery = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->leftJoin('documents as pd', 'pd.id', '=', 'r.document_id')
            ->leftJoin('sales_areas as psa', 'psa.id', '=', 'pd.sales_area_id')
            ->whereBetween('r.receipt_date', [$from, $to])
            ->where('r.status', 'completed');
        $this->applyBranch($posQuery, $filters, 't.branch_id');
        $this->applySalesArea($posQuery, $filters, 'pd.sales_area_id');
        $this->applySearch($posQuery, $filters, ['r.receipt_no']);

        $posRows = $posQuery->selectRaw("r.receipt_date, r.receipt_no, r.net_sales, r.vat_amount, COALESCE(psa.code || ' - ' || psa.name, 'ไม่ระบุสายขาย') as sales_area_name")
            ->get()->map(function ($r) {
                $total = DecimalMath::round($r->net_sales, 2);
                $vat = $r->vat_amount === null ? null : DecimalMath::round($r->vat_amount, 2);

                return [
                    'doc_date' => \Illuminate\Support\Carbon::parse($r->receipt_date)->thaiDate(),
                    'sort_key' => substr((string) $r->receipt_date, 0, 10).'|'.$r->receipt_no,
                    'doc_number' => $r->receipt_no,
                    'party_name' => 'ขายปลีก (POS - ใบกำกับอย่างย่อ)',
                    'tax_id' => '-',
                    'base_amount' => $vat === null ? null : DecimalMath::subtract($total, $vat, 2),
                    'vat_amount' => $vat,
                    'total_amount' => $total,
                    'sales_area_name' => $r->sales_area_name,
                ];
            });

        return $this->withVatTotals($docRows->concat($posRows));
    }

    // รายงานภาษีซื้อ (ภพ.30): ใบซื้อจากซัพพลายเออร์
    private function vatPurchase(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->where('dt.code', 'PURCHASE')->where('d.status', 'active')->where('d.claim_input_vat', true)
            ->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 's.name_th']);

        $rows = $query->selectRaw(
            "d.doc_date, d.doc_number, COALESCE(s.name_th, '-') as party_name, s.tax_id, d.total_amount, d.vat_amount"
        )->get()->map(function ($r) {
            $total = DecimalMath::round($r->total_amount, 2);
            $vat = DecimalMath::round($r->vat_amount, 2);
            $base = DecimalMath::subtract($total, $vat, 2);

            return [
                'doc_date' => \Illuminate\Support\Carbon::parse($r->doc_date)->thaiDate(),
                'sort_key' => $r->doc_date.'|'.$r->doc_number,
                'doc_number' => $r->doc_number,
                'party_name' => $r->party_name,
                'tax_id' => $r->tax_id ?? '-',
                'base_amount' => $base,
                'vat_amount' => $vat,
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
                'base_amount' => DecimalMath::sum($rows->pluck('base_amount'), 2),
                'vat_amount' => DecimalMath::sum($rows->pluck('vat_amount'), 2),
                'total_amount' => DecimalMath::sum($rows->pluck('total_amount'), 2),
            ]);
        }

        return $rows;
    }

    private function salesByBranch(Carbon $from, Carbon $to, array $filters): Collection
    {
        $branches = DB::table('branches')->get()->keyBy('id');
        return app(\App\Services\Reports\DailySalesReport::class)->source($from, $to, $filters)
            ->groupBy('branch_id')->map(function ($rows, $id) use ($branches) {
                $branch = $branches->get($id);
                return (object) ['branch_name' => trim(($branch->code ?? '').' '.($branch->name_th ?? '')), 'receipt_count' => $rows->count(), 'amount' => DecimalMath::sum($rows->pluck('net_sales'), 2)];
            })->sortByDesc('amount')->take($filters['per_page'])->values();
    }

    private function salesByStaff(Carbon $from, Carbon $to, array $filters): Collection
    {
        $rows = app(\App\Services\Reports\DailySalesReport::class)->source($from, $to, $filters);
        $receipts = DB::table('pos_receipts')->whereIn('id', $rows->where('channel', 'POS')->pluck('source_id'))->get()->keyBy('id');
        $documents = DB::table('documents')->whereIn('id', $rows->pluck('document_id')->filter())->get()->keyBy('id');
        $salesmen = DB::table('salesmen')->whereIn('id', $rows->pluck('salesman_id')->filter())->pluck('name', 'id');
        $users = DB::table('users')->whereIn('id', $receipts->pluck('cashier_id')->merge($documents->pluck('sales_user_id'))->filter())->pluck('name', 'id');
        return $rows->map(function ($row) use ($receipts, $documents, $salesmen, $users) {
            $userId = $row->channel === 'POS' ? $receipts->get($row->source_id)?->cashier_id : $documents->get($row->document_id)?->sales_user_id;
            $row->staff_name = $salesmen->get($row->salesman_id ?? null) ?? $users->get($userId) ?? 'ไม่ทราบผู้ขาย';
            return $row;
        })->groupBy(fn ($row) => $row->staff_name.'|'.$row->channel)->map(fn ($group) => (object) [
            'staff_name' => $group->first()->staff_name, 'channel' => $group->first()->channel,
            'bill_count' => $group->count(), 'amount' => DecimalMath::sum($group->pluck('net_sales'), 2),
        ])->sortByDesc('amount')->take($filters['per_page'])->values();
    }

    private function topProducts(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->leftJoin('documents as d', 'd.id', '=', 'r.document_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereBetween('r.receipt_date', [$from, $to])
            ->where('r.status', 'completed')
            ->where('r.is_legacy_import', false);

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th']);

        return $query
            ->groupBy('p.id', 'p.sku_code', 'p.name_th', 'sa.code', 'sa.name')
            ->orderByDesc(DB::raw('sum(i.net_amount)'))
            ->selectRaw("p.sku_code, p.name_th, COALESCE(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                sum(i.qty) as qty, sum(i.net_amount) as amount")
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
            $this->posSalesSlice($from, $to, $filters, ['category', 'sales_area']),
            $this->documentSalesSlice($from, $to, $filters, ['category', 'sales_area']),
            ['category_name', 'sales_area_name', 'channel'],
            $filters
        );
    }

    private function salesBySeller(Carbon $from, Carbon $to, array $filters): Collection
    {
        return $this->mergeSalesSlices(
            $this->posSalesSlice($from, $to, $filters, ['seller', 'sales_area']),
            $this->documentSalesSlice($from, $to, $filters, ['seller', 'sales_area']),
            ['seller_name', 'sales_area_name', 'channel'],
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
            ->leftJoin('documents as d', 'd.id', '=', 'r.document_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.product_category_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.cashier_id')
            ->whereBetween('r.receipt_date', [$from, $to])
            // บิลที่ยกเลิกใช้สถานะ 'void' ไม่ใช่ 'cancelled' — เทียบกับ 'cancelled' อย่างเดียว
            // ทำให้บิลที่ถูกยกเลิกยังถูกนับเป็นยอดขาย. ที่อื่นทั้งระบบใช้ 'completed'
            ->where('r.status', 'completed')
            // บิลนำเข้าจากระบบเก่าไม่ใช่ยอดขายของ ERP ใหม่ กติกาเดียวกับ view `sales_postings`
            ->where('r.is_legacy_import', false);

        $this->applyBranch($query, $filters, 't.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'pc.name_th', 'u.name']);

        $selects = ["'POS' as channel", 'count(distinct r.id) as bill_count', 'sum(i.qty) as qty', 'sum(i.net_amount) as amount'];
        $groups = [];

        if (in_array('category', $dimensions, true)) {
            $selects[] = "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า') as category_name";
            $groups[] = DB::raw("coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า')");
        }
        if (in_array('sales_area', $dimensions, true)) {
            $selects[] = "coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name";
            $groups[] = DB::raw("coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย')");
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
            ->leftJoin('users as u', 'u.id', '=', 'd.sales_user_id')
            // พนักงานขายของเอกสาร (เช่น ใบขายสายที่มาจาก BPlus) มาก่อนผู้ใช้ที่คีย์ เหมือนรายงานยอดขายตามพนักงาน
            ->leftJoin('salesmen as sm', 'sm.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            // เอกสารที่ถูกยกเลิกไม่ใช่ยอดขาย — เดิมไม่กรองสถานะเลย บิลที่ void แล้วจึงยังโผล่
            ->where('d.status', 'active')
            ->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            // ทุกบิล POS สร้างเอกสารขายสดผูกไว้เสมอ ถ้าไม่กันออกจะถูกนับซ้ำกับฝั่ง POS
            // กติกาเดียวกับ view `sales_postings` ซึ่งเป็นบัญชีขายฉบับจริงของระบบ
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('pos_receipts as pr')
                ->whereColumn('pr.document_id', 'd.id')
                ->where('pr.status', 'completed'));

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'p.sku_code', 'p.name_th', 'pc.name_th', 'u.name', 'sm.name']);

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
        if (in_array('sales_area', $dimensions, true)) {
            $selects[] = "coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name";
            $groups[] = DB::raw("coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย')");
        }
        if (in_array('seller', $dimensions, true)) {
            $selects[] = "coalesce(sm.name, 'ไม่ระบุ') as seller_name";
            $groups[] = DB::raw("coalesce(sm.name, 'ไม่ระบุ')");
        }

        $lineRows = $query
            ->groupBy(...$groups)
            ->selectRaw(implode(",\n", $selects))
            ->get();

        $lineTotals = DB::table('stock_documents as sd')
            ->join('stock_document_items as sdi', 'sdi.stock_document_id', '=', 'sd.id')
            ->groupBy('sd.document_id')
            ->selectRaw('sd.document_id, sum(sdi.qty * sdi.unit_price) as line_amount');
        $headerQuery = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoinSub($lineTotals, 'line_totals', 'line_totals.document_id', '=', 'd.id')
            ->leftJoin('salesmen as sm', 'sm.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('pos_receipts as pr')
                ->whereColumn('pr.document_id', 'd.id')->where('pr.status', 'completed'));
        $this->applyBranch($headerQuery, $filters, 'd.branch_id');
        $this->applySalesArea($headerQuery, $filters, 'd.sales_area_id');
        if (($filters['q'] ?? '') !== '') {
            $search = '%'.$filters['q'].'%';
            $headerQuery->where(function ($searchQuery) use ($search) {
                $searchQuery->where('d.doc_number', 'like', $search)
                    ->orWhere('c.code', 'like', $search)->orWhere('c.name_th', 'like', $search)
                    ->orWhere('sm.name', 'like', $search)->orWhere('sa.code', 'like', $search)->orWhere('sa.name', 'like', $search)
                    ->orWhereExists(function ($lineQuery) use ($search) {
                        $lineQuery->selectRaw('1')->from('stock_documents as qsd')
                            ->join('stock_document_items as qsdi', 'qsdi.stock_document_id', '=', 'qsd.id')
                            ->join('products as qp', 'qp.id', '=', 'qsdi.product_id')
                            ->leftJoin('product_categories as qpc', 'qpc.id', '=', 'qp.product_category_id')
                            ->whereColumn('qsd.document_id', 'd.id')
                            ->where(function ($productSearch) use ($search) {
                                $productSearch->where('qp.sku_code', 'like', $search)
                                    ->orWhere('qp.name_th', 'like', $search)->orWhere('qpc.name_th', 'like', $search);
                            });
                    });
            });
        }

        $residualDocuments = $headerQuery->get(['d.total_amount', 'dt.code as document_code',
            DB::raw('COALESCE(line_totals.line_amount, 0) as line_amount'), 'sm.name as salesman_name', 'sa.code as area_code', 'sa.name as area_name'])
            ->map(function ($document) use ($dimensions) {
                $sign = $document->document_code === 'SALE_RETURN' ? -1 : 1;
                $documentAmount = DecimalMath::multiply($sign, $document->total_amount, 2);
                $lineAmount = DecimalMath::multiply($sign, $document->line_amount, 2);
                $difference = DecimalMath::subtract($documentAmount, $lineAmount, 2);
                if (DecimalMath::compare($difference, 0) === 0) {
                    return null;
                }

                $row = ['channel' => 'เอกสาร', 'bill_count' => 0, 'qty' => '0.0000', 'amount' => $difference];
                if (in_array('category', $dimensions, true)) {
                    $row['category_name'] = 'ส่วนต่าง/ภาษีตามเอกสาร';
                }
                if (in_array('seller', $dimensions, true)) {
                    $row['seller_name'] = $document->salesman_name ?? 'ไม่ระบุ';
                }
                if (in_array('sales_area', $dimensions, true)) {
                    $row['sales_area_name'] = $document->area_code && $document->area_name
                        ? $document->area_code.' - '.$document->area_name
                        : 'ไม่ระบุสายขาย';
                }

                return (object) $row;
            })->filter();

        $dimensionKeys = [];
        foreach (['category' => 'category_name', 'seller' => 'seller_name', 'sales_area' => 'sales_area_name'] as $dimension => $key) {
            if (in_array($dimension, $dimensions, true)) {
                $dimensionKeys[] = $key;
            }
        }
        $residualRows = $residualDocuments->groupBy(fn ($row) => implode('|', array_map(fn ($key) => (string) $row->{$key}, $dimensionKeys)))
            ->map(function (Collection $rows) {
                $first = $rows->first();
                return (object) array_merge((array) $first, ['amount' => DecimalMath::sum($rows->pluck('amount'), 2)]);
            })->values();

        return $lineRows->concat($residualRows)->values();
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
                $payload['amount'] = DecimalMath::sum($rows->pluck('amount'), 2);

                return (object) $payload;
            })
            ->sortByDesc('amount')
            ->take($filters['per_page'] ?? 25)
            ->values();
    }

    private function grossMargin(Carbon $from, Carbon $to, array $filters): Collection
    {
        // POS ที่สร้างจากหน้าขายมี CASH_SALE document อยู่แล้ว จึงไม่รวมซ้ำที่นี่
        // ส่วน POS import รุ่นเก่าที่ไม่มี document ยังใช้ average_cost ปัจจุบันเป็นค่าประมาณแยกต่างหาก
        $posQuery = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereNull('r.document_id')->where('r.status', 'completed')->where('r.is_legacy_import', false)
            ->whereBetween('r.receipt_date', [$from, $to]);

        $this->applyBranch($posQuery, $filters, 't.branch_id');
        $this->applySearch($posQuery, $filters, ['p.sku_code', 'p.name_th']);

        $posRows = $posQuery
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->selectRaw('p.id as product_id, p.sku_code, p.name_th, sum(i.qty) as qty, sum(i.net_amount - i.vat_amount) as sales_amount, NULL as cost_amount, count(*) as missing_cost_count')
            ->get();

        $docQuery = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($docQuery, $filters, 'd.branch_id');
        $this->applySearch($docQuery, $filters, ['p.sku_code', 'p.name_th']);

        $docRows = $docQuery
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->selectRaw('p.id as product_id, p.sku_code, p.name_th, sum(sdi.qty) as qty, sum((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0)) as sales_amount, sum(sdi.cost_amount) as cost_amount, sum(case when sdi.cost_amount is null then 1 else 0 end) as missing_cost_count')
            ->get();

        return $posRows
            ->concat($docRows)
            ->groupBy('product_id')
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $qty = DecimalMath::sum($rows->pluck('qty'));
                $salesAmount = DecimalMath::sum($rows->pluck('sales_amount'), 2);
                $missing = $rows->sum('missing_cost_count');
                $costAmount = $missing ? null : DecimalMath::sum($rows->pluck('cost_amount'), 2);

                return (object) [
                    'sku_code' => $first->sku_code,
                    'name_th' => $first->name_th,
                    'qty' => $qty,
                    'sales_amount' => $salesAmount,
                    'cost_amount' => $costAmount,
                    'gross_profit' => $missing ? null : DecimalMath::subtract($salesAmount, $costAmount, 2),
                    'missing_cost_count' => $missing,
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
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->where('dt.code', 'CREDIT_SALE')
            ->where('d.status', 'active')
            ->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th', 's.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, coalesce(c.name_th, '-') as customer_name, coalesce(s.name, 'ไม่ระบุ') as salesman_name, coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, d.total_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function bplusCreditSaleQuery(Carbon $from, Carbon $to, array $filters, array $extraSearchColumns = [])
    {
        $itemTotals = DB::table('stock_documents as sd')
            ->join('stock_document_items as sdi', 'sdi.stock_document_id', '=', 'sd.id')
            ->groupBy('sd.document_id')
            ->selectRaw('sd.document_id, SUM(COALESCE(sdi.unit_qty, sdi.qty)) AS qty, COUNT(sdi.id) AS item_count');

        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('document_books as db', 'db.id', '=', 'd.document_book_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoinSub($itemTotals, 'item_totals', 'item_totals.document_id', '=', 'd.id')
            ->where('d.source_system', 'bplus')
            ->where('dt.code', 'CREDIT_SALE')
            ->where('d.status', 'active')
            ->whereNull('d.cancelled_at')
            ->whereIn('db.code', ['DS', 'DSN'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, array_merge(['d.doc_number', 'c.code', 'c.name_th', 's.name', 'db.code'], $extraSearchColumns));

        if (($filters['salesman_id'] ?? null) === 'unassigned') {
            $query->whereNull('d.salesman_id');
        } elseif (! empty($filters['salesman_id'])) {
            $query->where('d.salesman_id', $filters['salesman_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('d.customer_id', $filters['customer_id']);
        }
        if (! empty($filters['document_book'])) {
            $query->where('db.code', $filters['document_book']);
        }

        return $query;
    }

    private function bplusCreditSalesByDocument(Carbon $from, Carbon $to, array $filters): array
    {
        $columns = [
            ['label' => 'วันที่', 'key' => 'doc_date'],
            ['label' => 'เลขที่เอกสาร', 'key' => 'doc_number'],
            ['label' => 'รหัสลูกค้า', 'key' => 'customer_code'],
            ['label' => 'ชื่อลูกค้า', 'key' => 'customer_name'],
            ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'ยอดก่อน VAT', 'key' => 'subtotal_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'VAT', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'สุทธิ', 'key' => 'total_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'จำนวนรายการ', 'key' => 'item_count', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'รหัสสาขา', 'key' => 'branch_code'],
            ['label' => 'สาขา', 'key' => 'branch_name'],
            ['label' => 'สายขาย', 'key' => 'sales_area_name'],
            ['label' => 'พนักงานขาย', 'key' => 'salesman_name'],
        ];
        $rows = $this->bplusCreditSaleQuery($from, $to, $filters)
            ->selectRaw("d.doc_date, d.doc_number, COALESCE(c.code, '-') AS customer_code, COALESCE(c.name_th, '-') AS customer_name,
                COALESCE(item_totals.qty, 0) AS qty,
                COALESCE(d.subtotal_amount, d.total_amount - COALESCE(d.vat_amount, 0)) AS subtotal_amount,
                COALESCE(d.vat_amount, 0) AS vat_amount, d.total_amount,
                COALESCE(item_totals.item_count, 0) AS item_count, COALESCE(b.code, '-') AS branch_code,
                COALESCE(b.name_th, '-') AS branch_name, COALESCE(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') AS sales_area_name,
                COALESCE(s.name, 'ไม่ระบุ') AS salesman_name")
            ->orderBy('d.doc_date')->orderBy('d.doc_number')->get();

        $summary = [
            'document_count' => $rows->count(),
            'qty' => DecimalMath::sum($rows->pluck('qty'), 4),
            'subtotal_amount' => DecimalMath::sum($rows->pluck('subtotal_amount'), 2),
            'vat_amount' => DecimalMath::sum($rows->pluck('vat_amount'), 2),
            'net_sales' => DecimalMath::sum($rows->pluck('total_amount'), 2),
        ];
        $rows->push((object) [
            'doc_date' => '', 'doc_number' => 'รวมทั้งสิ้น '.$summary['document_count'].' เอกสาร', 'customer_code' => '', 'customer_name' => '',
            'qty' => $summary['qty'], 'subtotal_amount' => $summary['subtotal_amount'],
            'vat_amount' => $summary['vat_amount'], 'total_amount' => $summary['net_sales'],
            'item_count' => null, 'branch_code' => '', 'branch_name' => '', 'salesman_name' => '',
        ]);

        return [
            'title' => 'BPlus ใบขายเชื่อแยกตามเอกสาร', 'columns' => $columns, 'rows' => $rows,
            'total' => $summary['document_count'], 'summary_scope' => 'all',
            'summary' => ['net_sales' => $summary['net_sales'], 'vat_amount' => $summary['vat_amount']] + $summary,
            'pagination' => null,
        ];
    }

    private function bplusCreditSalesByProduct(Carbon $from, Carbon $to, array $filters): array
    {
        $columns = [
            ['label' => 'สินค้า', 'key' => 'product'],
            ['label' => 'วันที่', 'key' => 'doc_date'],
            ['label' => 'เลขที่อ้างอิง', 'key' => 'doc_number'],
            ['label' => 'รหัสลูกค้า', 'key' => 'customer_code'],
            ['label' => 'ลูกหนี้', 'key' => 'customer_name'],
            ['label' => 'สายขาย', 'key' => 'sales_area_name'],
            ['label' => 'หน่วย', 'key' => 'unit_name'],
            ['label' => 'จำนวน', 'key' => 'qty', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'ของแถม', 'key' => 'free_qty', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'ราคา/หน่วย', 'key' => 'unit_price', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'จำนวนเงิน', 'key' => 'amount', 'type' => 'money', 'class' => 'text-end'],
        ];
        $lines = $this->bplusCreditSaleQuery($from, $to, $filters, ['p.sku_code', 'p.name_th'])
            ->join('stock_documents as sd', 'sd.document_id', '=', 'd.id')
            ->join('stock_document_items as sdi', 'sdi.stock_document_id', '=', 'sd.id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->leftJoin('product_units as u', 'u.id', '=', 'sdi.unit_id')
            ->leftJoin('bplus_sync_documents as bsd', 'bsd.document_id', '=', 'd.id')
            ->leftJoin('bplus_sync_lines as bpl', function ($join) {
                $join->on('bpl.bplus_sync_document_id', '=', 'bsd.id')->on('bpl.seq', '=', 'sdi.seq');
            })
            ->selectRaw("p.sku_code, p.name_th, d.doc_date, d.doc_number, COALESCE(c.code, '-') AS customer_code,
                COALESCE(c.name_th, '-') AS customer_name, COALESCE(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') AS sales_area_name,
                COALESCE(bpl.unit_name, u.name, '-') AS unit_name,
                COALESCE(sdi.unit_qty, sdi.qty) AS qty, sdi.qty AS base_qty, COALESCE(bpl.free_qty, 0) AS free_qty,
                sdi.unit_price * COALESCE(sdi.unit_factor, 1) AS unit_price, sdi.qty * sdi.unit_price AS amount")
            ->orderBy('p.sku_code')->orderBy('p.name_th')->orderBy('d.doc_date')->orderBy('d.doc_number')->orderBy('sdi.seq')
            ->get();

        $rows = collect();
        foreach ($lines->groupBy(fn ($line) => $line->sku_code.'|'.$line->name_th.'|'.$line->sales_area_name) as $productLines) {
            $first = $productLines->first();
            $product = trim($first->sku_code.' - '.$first->name_th, ' -');
            foreach ($productLines as $line) {
                $line->product = $product;
                $rows->push($line);
            }
            $rows->push((object) [
                'product' => 'รวมสินค้า '.$product, 'doc_date' => '', 'doc_number' => '', 'customer_code' => '', 'customer_name' => '',
                'sales_area_name' => $first->sales_area_name, 'unit_name' => '',
                'qty' => DecimalMath::sum($productLines->pluck('qty'), 4),
                'free_qty' => DecimalMath::sum($productLines->pluck('free_qty'), 4), 'unit_price' => null,
                'amount' => DecimalMath::sum($productLines->pluck('amount'), 2),
            ]);
        }

        // BPlus รวมต่อสินค้าเป็นหน่วยที่ขาย แต่บรรทัด "รวมทั้งหมด" เป็นหน่วยฐาน (16/11 สาย 09: 318.9 หน่วยขาย = 1,706.9 หน่วยฐาน)
        $summaryDocuments = $this->bplusCreditSaleQuery($from, $to, $filters)->get(['d.total_amount', 'd.subtotal_amount', 'd.vat_amount']);
        $summary = [
            'qty' => DecimalMath::sum($lines->pluck('base_qty'), 4),
            'free_qty' => DecimalMath::sum($lines->pluck('free_qty'), 4),
            'subtotal_amount' => DecimalMath::sum($summaryDocuments->pluck('subtotal_amount'), 2),
            'vat_amount' => DecimalMath::sum($summaryDocuments->pluck('vat_amount'), 2),
            'net_sales' => DecimalMath::sum($summaryDocuments->pluck('total_amount'), 2),
        ];
        $lineAmount = DecimalMath::sum($lines->pluck('amount'), 2);
        $rows->push((object) [
            'product' => 'รวมมูลค่าบรรทัดสินค้า', 'doc_date' => '', 'doc_number' => '', 'customer_code' => '', 'customer_name' => '', 'sales_area_name' => '', 'unit_name' => '',
            'qty' => $summary['qty'], 'free_qty' => $summary['free_qty'], 'unit_price' => null, 'amount' => $lineAmount,
        ]);

        return [
            'title' => 'BPlus ใบขายเชื่อแยกตามสินค้า', 'columns' => $columns, 'rows' => $rows,
            'total' => $lines->count(), 'summary_scope' => 'all',
            'summary' => ['net_sales' => $summary['net_sales'], 'vat_amount' => $summary['vat_amount']] + $summary,
            'pagination' => null,
        ];
    }

    /** Daily BPlus credit sales, with cash and transfer collected on each receipt date. */
    private function bplusDailySalesSummary(Carbon $from, Carbon $to, array $filters): array
    {
        $salesQuery = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->where('d.source_system', 'bplus')->whereIn('dt.code', ['CREDIT_SALE', 'CREDIT_NOTE'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($salesQuery, $filters, 'd.branch_id');
        $this->applySalesArea($salesQuery, $filters, 'd.sales_area_id');
        $this->applySearch($salesQuery, $filters, ['d.doc_number', 'c.code', 'c.name_th']);

        $sales = $salesQuery->groupBy('d.doc_date', 'sa.code', 'sa.name')
            ->selectRaw("d.doc_date, COALESCE(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                sum(case when dt.code = 'CREDIT_SALE' then 1 else 0 end) as bill_count,
                sum(case when dt.code = 'CREDIT_NOTE' then -d.subtotal_amount else d.subtotal_amount end) as subtotal_amount,
                sum(case when dt.code = 'CREDIT_NOTE' then -d.vat_amount else d.vat_amount end) as vat_amount,
                sum(case when dt.code = 'CREDIT_NOTE' then -d.total_amount else d.total_amount end) as net_sales")
            ->get()->keyBy(fn ($row) => $row->doc_date.'|'.$row->sales_area_name);

        $receiptsQuery = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('payment_documents as pd', 'pd.document_id', '=', 'd.id')
            ->join('payment_lines as pl', 'pl.payment_document_id', '=', 'pd.id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->where('d.source_system', 'bplus')->where('dt.code', 'RECEIPT')
            ->where('d.status', 'active')->whereNull('d.cancelled_at')->where('pd.status', 'active')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($receiptsQuery, $filters, 'd.branch_id');
        $this->applySalesArea($receiptsQuery, $filters, 'd.sales_area_id');
        $this->applySearch($receiptsQuery, $filters, ['d.doc_number', 'c.code', 'c.name_th']);

        $receipts = $receiptsQuery->groupBy('d.doc_date', 'sa.code', 'sa.name')
            ->selectRaw("d.doc_date, COALESCE(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                sum(case when pl.method = 'cash' then pl.amount else 0 end) as cash_amount,
                sum(case when pl.method = 'transfer' then pl.amount else 0 end) as transfer_amount")
            ->get()->keyBy(fn ($row) => $row->doc_date.'|'.$row->sales_area_name);

        $rows = $sales->keys()->merge($receipts->keys())->unique()->sort()->map(function ($key) use ($sales, $receipts) {
            [$date, $areaName] = explode('|', $key, 2);
            $sale = $sales->get($key);
            $receipt = $receipts->get($key);

            return (object) [
                'sale_date' => $date,
                'sales_area_name' => $areaName,
                'bill_count' => (int) ($sale->bill_count ?? 0),
                'subtotal_amount' => $sale->subtotal_amount ?? '0.00',
                'vat_amount' => $sale->vat_amount ?? '0.00',
                'net_sales' => $sale->net_sales ?? '0.00',
                'cash_amount' => $receipt->cash_amount ?? '0.00',
                'transfer_amount' => $receipt->transfer_amount ?? '0.00',
            ];
        })->take($filters['per_page'] ?? 25)->values();

        return $this->tableResult('BPlus สรุปขายเชื่อรายวัน (รับชำระตามวันที่ใบเสร็จ)', [
            ['label' => 'วันที่', 'key' => 'sale_date'],
            ['label' => 'สายขาย', 'key' => 'sales_area_name'],
            ['label' => 'จำนวนบิล', 'key' => 'bill_count', 'type' => 'number', 'class' => 'text-end'],
            ['label' => 'มูลค่าก่อนภาษี', 'key' => 'subtotal_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'ภาษี', 'key' => 'vat_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'สุทธิ', 'key' => 'net_sales', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'รับเงินสด', 'key' => 'cash_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'รับโอน', 'key' => 'transfer_amount', 'type' => 'money', 'class' => 'text-end'],
        ], $rows);
    }

    private function pendingBookings(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('sale_bookings as sb')
            ->join('documents as d', 'd.id', '=', 'sb.document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->where('sb.status', 'pending')
            ->where('d.status', 'active')
            ->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th', 's.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, coalesce(c.name_th, '-') as customer_name, coalesce(s.name, 'ไม่ระบุ') as salesman_name, coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, d.total_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    // ยอดขายตามใบจอง: ทุกใบจองในช่วง + ใบขายเชื่อที่แปลงแล้ว (BPlus: รายงานใบจอง)
    private function salesByBooking(Carbon $from, Carbon $to, array $filters): Collection
    {
        return app(\App\Services\Reports\BookingSalesReport::class)->rows($from, $to, $filters);
    }

    // ใบขาย-รับคืน ตามเอกสาร (BPlus G): ขายสด/ขายเชื่อ/รับคืนรวมตารางเดียว คืนติดลบ
    private function salesReturnsByDocument(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN', 'CREDIT_NOTE'])
            // เอกสารที่ถูกยกเลิกไม่ใช่ยอดขาย — เดิมไม่กรองสถานะเลย บิลที่ void แล้วจึงยังโผล่
            ->where('d.status', 'active')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            // ทุกบิล POS สร้างเอกสารขายสดผูกไว้เสมอ ถ้าไม่กันออกจะถูกนับซ้ำกับฝั่ง POS
            // กติกาเดียวกับ view `sales_postings` ซึ่งเป็นบัญชีขายฉบับจริงของระบบ
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('pos_receipts as pr')
                ->whereColumn('pr.document_id', 'd.id')
                ->where('pr.status', 'completed'));

        if ($filters['returns_only'] ?? false) {
            $query->whereIn('dt.code', ['SALE_RETURN', 'CREDIT_NOTE'])->whereNull('d.cancelled_at');
            $this->applySalesArea($query, $filters, 'd.sales_area_id');
        }
        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th', 's.name']);

        $rows = $query
            ->orderByDesc('d.doc_date')->orderByDesc('d.id')
            ->selectRaw("d.doc_date, d.doc_number, dt.name_th as document_type, coalesce(c.name_th, '-') as customer_name, coalesce(s.name, '-') as salesman_name, case when dt.code in ('SALE_RETURN', 'CREDIT_NOTE') then -d.total_amount else d.total_amount end as total_amount")
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
            ->where('d.status', 'active')
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
        $itemTotals = DB::table('stock_documents as sd')
            ->join('stock_document_items as sdi', 'sdi.stock_document_id', '=', 'sd.id')
            ->groupBy('sd.document_id')
            ->selectRaw('sd.document_id, sum(sdi.qty) as qty');

        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->leftJoinSub($itemTotals, 'item_totals', 'item_totals.document_id', '=', 'd.id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN', 'CREDIT_NOTE'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            // ทุกบิล POS สร้างเอกสารขายสดผูกไว้เสมอ ถ้าไม่กันออกจะถูกนับซ้ำกับฝั่ง POS
            // กติกาเดียวกับ view `sales_postings` ซึ่งเป็นบัญชีขายฉบับจริงของระบบ
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('pos_receipts as pr')
                ->whereColumn('pr.document_id', 'd.id')
                ->where('pr.status', 'completed'));

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number']);

        return $query
            ->groupBy('c.id', 'c.code', 'c.name_th', 'sa.code', 'sa.name')
            ->orderByRaw("sum(case when dt.code in ('SALE_RETURN', 'CREDIT_NOTE') then -d.total_amount else d.total_amount end) desc")
            ->selectRaw("
                concat(coalesce(c.code, '-'), ' ', coalesce(c.name_th, 'ลูกค้าทั่วไป')) as customer_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                count(distinct d.id) as bill_count,
                sum(case when dt.code in ('SALE_RETURN', 'CREDIT_NOTE') then -coalesce(item_totals.qty, 0) else coalesce(item_totals.qty, 0) end) as qty,
                sum(case when dt.code not in ('SALE_RETURN', 'CREDIT_NOTE') then d.total_amount else 0 end) as sales_amount,
                sum(case when dt.code in ('SALE_RETURN', 'CREDIT_NOTE') then d.total_amount else 0 end) as return_amount,
                sum(case when dt.code in ('SALE_RETURN', 'CREDIT_NOTE') then -d.total_amount else d.total_amount end) as net_amount
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
            // เอกสารที่ถูกยกเลิกไม่ใช่ยอดขาย — เดิมไม่กรองสถานะเลย บิลที่ void แล้วจึงยังโผล่
            ->where('d.status', 'active')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            // ทุกบิล POS สร้างเอกสารขายสดผูกไว้เสมอ ถ้าไม่กันออกจะถูกนับซ้ำกับฝั่ง POS
            // กติกาเดียวกับ view `sales_postings` ซึ่งเป็นบัญชีขายฉบับจริงของระบบ
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('pos_receipts as pr')
                ->whereColumn('pr.document_id', 'd.id')
                ->where('pr.status', 'completed'));

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
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereNotNull('sdi.cost_amount')
            ->whereRaw('((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0)) < sdi.cost_amount')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'p.sku_code', 'p.name_th']);

        return $query
            ->orderByRaw('(sdi.cost_amount - ((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0))) desc')
            ->selectRaw('d.doc_date, d.doc_number, p.sku_code, p.name_th, sdi.qty as sold_qty, sdi.unit_price, sdi.unit_cost as avg_cost, (sdi.cost_amount - ((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0))) as loss_amount')
            ->limit($filters['per_page'])
            ->get();
    }

    private function lossSalesSixMonths(Carbon $to, array $filters): Collection
    {
        $month = DB::getDriverName() === 'pgsql' ? "to_char(d.doc_date, 'YYYY-MM')" : "strftime('%Y-%m', d.doc_date)";
        $from = $to->copy()->subMonthsNoOverflow(5)->startOfMonth();
        $query = $this->lossSalesBase($from, $to, $filters);

        return $query
            ->groupBy(DB::raw($month), 'p.id', 'p.sku_code', 'p.name_th')
            ->orderBy(DB::raw($month))
            ->orderByRaw('loss_amount desc')
            ->selectRaw("
                {$month} as sale_month,
                p.sku_code,
                p.name_th,
                sum(sdi.qty) as sold_qty,
                sum(((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0))) as sales_amount,
                sum(sdi.cost_amount) as cost_amount,
                sum((sdi.cost_amount - ((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0)))) as loss_amount
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
        $month = DB::getDriverName() === 'pgsql' ? "to_char(d.doc_date, 'YYYY-MM')" : "strftime('%Y-%m', d.doc_date)";
        $from = $to->copy()->subMonthsNoOverflow(5)->startOfMonth();
        $query = $this->lossSalesBase($from, $to, $filters)
            ->leftJoin('product_departments as pd', 'pd.id', '=', 'p.product_department_id')
            ->leftJoin('product_brands as pb', 'pb.id', '=', 'p.product_brand_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.product_category_id');

        $groupSql = match ($mode) {
            'department' => "coalesce(pd.name_th, 'ไม่ระบุประเภทสินค้า')",
            'brand' => "coalesce(pb.name_th, 'ไม่ระบุยี่ห้อสินค้า')",
            'category' => "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า')",
            // ใช้ subquery ไม่ใช่ join เพราะสินค้าหนึ่งตัวอาจมีผู้จำหน่ายหลักค้างไว้หลายแถว
            // join แล้วยอดขาย/ขาดทุนจะถูกนับซ้ำตามจำนวนแถวโดยไม่มีใครสังเกต
            'supplier' => "coalesce((select s.name_th from product_suppliers ps
                join suppliers s on s.id = ps.supplier_id
                where ps.product_id = p.id and ps.is_primary = true
                order by ps.id limit 1), 'ไม่ระบุผู้จำหน่ายหลัก')",
            default => "coalesce(pc.name_th, 'ไม่ระบุหมวดสินค้า')",
        };

        return $query
            ->groupBy(DB::raw($month), DB::raw($groupSql))
            ->orderBy(DB::raw($month))
            ->orderByRaw('loss_amount desc')
            ->selectRaw("
                {$month} as sale_month,
                {$groupSql} as group_name,
                count(distinct p.id) as product_count,
                sum(sdi.qty) as sold_qty,
                sum(((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0))) as sales_amount,
                sum(sdi.cost_amount) as cost_amount,
                sum((sdi.cost_amount - ((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0)))) as loss_amount
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
                ->selectRaw('d.doc_date, d.doc_number, p.sku_code, p.name_th, sdi.qty as sold_qty, sdi.unit_price, sdi.unit_cost as avg_cost, (sdi.cost_amount - ((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0))) as loss_amount')
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
                sum((sdi.cost_amount - ((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0)))) as loss_amount
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
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereNotNull('sdi.cost_amount')
            ->whereRaw('((sdi.qty * sdi.unit_price) - coalesce(sdi.vat_amount, 0)) < sdi.cost_amount')
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
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at');

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th']);

        $rows = $query
            ->groupBy('c.id', 'c.code', 'c.name_th', 'sa.code', 'sa.name')
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                count(*) as bill_count, min(oi.due_date) as oldest_due_date,
                sum(case when oi.due_date < current_date then oi.balance_amount else 0 end) as overdue_amount, sum(oi.balance_amount) as balance_amount")
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

    private function arAging(array $filters): Collection
    {
        return app(\App\Services\Reports\ReceivableReport::class)->aging($filters);
    }

    private function overdueCustomers(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereDate('oi.due_date', '<', now()->toDateString());

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 's.name']);

        return $query
            ->groupBy('c.id', 'c.code', 'c.name_th', 's.name', 'sa.code', 'sa.name')
            ->orderByDesc(DB::raw('sum(oi.balance_amount)'))
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, coalesce(s.name, 'ไม่ระบุ') as salesman_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                count(*) as bill_count, sum(oi.balance_amount) as balance_amount, min(oi.due_date) as oldest_due_date")
            ->limit($filters['per_page'])
            ->get();
    }

    private function openItems(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->leftJoin('salesmen as sm', 'sm.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at');

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number', 'sm.name']);

        return $query
            ->orderBy('oi.due_date')
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, d.doc_number, coalesce(sm.name, 'ไม่ระบุ') as salesman_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, oi.due_date, oi.status, oi.balance_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function arDetails(array $filters, bool $full): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at');

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number', 's.name']);

        $select = $full
            ? "concat(c.code, ' ', c.name_th) as customer_name, coalesce(s.name, 'ไม่ระบุ') as salesman_name, coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, d.doc_number, d.doc_date, oi.due_date, oi.net_amount, oi.paid_amount, oi.balance_amount"
            : "concat(c.code, ' ', c.name_th) as customer_name, coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, d.doc_number, oi.due_date, oi.balance_amount";

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
            ->leftJoin('salesmen as s', 's.id', '=', 'd.salesman_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereDate('oi.due_date', '<', now()->toDateString());

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['c.code', 'c.name_th', 'd.doc_number', 's.name']);

        return $query
            ->orderBy('oi.due_date')
            ->selectRaw("concat(c.code, ' ', c.name_th) as customer_name, coalesce(s.name, 'ไม่ระบุ') as salesman_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, d.doc_number, oi.due_date,
                (current_date - oi.due_date) as overdue_days, oi.balance_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function arStatement(Carbon $from, Carbon $to, array $filters): array
    {
        $columns = [
            ['label' => 'วันที่', 'key' => 'doc_date'],
            ['label' => 'ประเภท', 'key' => 'document_type'],
            ['label' => 'เลขที่เอกสาร', 'key' => 'doc_number'],
            ['label' => 'ยอดเพิ่มหนี้', 'key' => 'debit_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'ยอดรับชำระ/ลดหนี้', 'key' => 'credit_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'คงเหลือ', 'key' => 'balance_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'สายขาย', 'key' => 'sales_area_name'],
        ];
        if (empty($filters['customer_id'])) {
            return [
                'title' => 'Statement ลูกหนี้รายตัว — เลือกลูกค้าก่อน', 'columns' => $columns, 'rows' => collect(), 'total' => 0,
                'summary_scope' => 'all', 'summary' => ['opening_balance' => '0.00', 'sales_amount' => '0.00', 'credit_note_amount' => '0.00', 'receipt_amount' => '0.00', 'closing_balance' => '0.00'],
                'pagination' => null,
            ];
        }

        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->where('d.customer_id', $filters['customer_id'])
            ->whereIn('dt.code', ['OPENING_AR', 'CREDIT_SALE', 'CREDIT_NOTE', 'RECEIPT'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereDate('d.doc_date', '<=', $to->toDateString());
        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number']);

        $events = $query->orderBy('d.doc_date')->orderByRaw("case dt.code when 'OPENING_AR' then 0 when 'CREDIT_SALE' then 1 when 'CREDIT_NOTE' then 2 else 3 end")
            ->orderBy('d.id')
            ->get(['d.doc_date', 'd.doc_number', 'd.total_amount', 'dt.code as document_code', 'sa.code as area_code', 'sa.name as area_name']);

        $openingBalance = '0.00';
        $periodEvents = collect();
        foreach ($events as $event) {
            $signed = in_array($event->document_code, ['RECEIPT', 'CREDIT_NOTE'], true)
                ? DecimalMath::multiply(-1, $event->total_amount, 2)
                : DecimalMath::round($event->total_amount, 2);
            if ($event->doc_date < $from->toDateString()) {
                $openingBalance = DecimalMath::add($openingBalance, $signed, 2);
            } else {
                $periodEvents->push([$event, $signed]);
            }
        }

        $balance = $openingBalance;
        $rows = collect([(object) [
            'doc_date' => 'ก่อน '.$from->toDateString(), 'document_type' => 'ยอดยกมา', 'doc_number' => '-',
            'debit_amount' => DecimalMath::compare($openingBalance, 0) > 0 ? $openingBalance : '0.00',
            'credit_amount' => DecimalMath::compare($openingBalance, 0) < 0 ? DecimalMath::multiply(-1, $openingBalance, 2) : '0.00',
            'balance_amount' => $openingBalance, 'sales_area_name' => '',
        ]]);
        $salesAmount = '0.00';
        $creditNoteAmount = '0.00';
        $openingDocumentAmount = '0.00';
        $receiptAmount = '0.00';
        foreach ($periodEvents as [$event, $signed]) {
            $isReceipt = $event->document_code === 'RECEIPT';
            $isCreditNote = $event->document_code === 'CREDIT_NOTE';
            $balance = DecimalMath::add($balance, $signed, 2);
            if ($isReceipt) {
                $receiptAmount = DecimalMath::add($receiptAmount, $event->total_amount, 2);
            } elseif ($isCreditNote) {
                $creditNoteAmount = DecimalMath::add($creditNoteAmount, $event->total_amount, 2);
            } elseif ($event->document_code === 'OPENING_AR') {
                $openingDocumentAmount = DecimalMath::add($openingDocumentAmount, $event->total_amount, 2);
            } else {
                $salesAmount = DecimalMath::add($salesAmount, $event->total_amount, 2);
            }
            $rows->push((object) [
                'doc_date' => $event->doc_date,
                'document_type' => ['OPENING_AR' => 'ลูกหนี้ยกมา', 'CREDIT_SALE' => 'ขายเชื่อ', 'CREDIT_NOTE' => 'ใบลดหนี้', 'RECEIPT' => 'ใบเสร็จรับเงิน'][$event->document_code],
                'doc_number' => $event->doc_number,
                'debit_amount' => ! $isReceipt && ! $isCreditNote ? $event->total_amount : '0.00',
                'credit_amount' => $isReceipt || $isCreditNote ? $event->total_amount : '0.00',
                'balance_amount' => $balance,
                'sales_area_name' => trim(($event->area_code ?? '').' '.($event->area_name ?? '')) ?: 'ไม่ระบุสายขาย',
            ]);
        }

        return [
            'title' => 'Statement ลูกหนี้รายตัว', 'columns' => $columns, 'rows' => $rows, 'total' => $rows->count(),
            'summary_scope' => 'all',
            'summary' => ['net_sales' => $balance, 'opening_balance' => $openingBalance, 'opening_document_amount' => $openingDocumentAmount, 'sales_amount' => $salesAmount,
                'credit_note_amount' => $creditNoteAmount,
                'receipt_amount' => $receiptAmount, 'closing_balance' => $balance],
            'pagination' => null,
        ];
    }

    private function arOverCreditLimit(array $filters): Collection
    {
        $query = DB::table('customer_open_items as oi')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('c.credit_limit', '>', 0);

        $this->applyBranch($query, $filters, 'd.branch_id');
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
            ->selectRaw("concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, wl.name, 'คลังกลาง/ตู้')) as branch_name, count(distinct sb.product_id) as product_count, sum(sb.on_hand_qty) as on_hand_qty, sum(sb.reserved_qty) as reserved_qty, sum(sb.on_hand_qty * coalesce(p.default_price, 0)) as stock_value")
            ->limit($filters['per_page'])
            ->get();
    }

    private function stockAlerts(array $filters): Collection
    {
        $query = DB::table('stock_balances as sb')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sb.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', DB::raw($this->branchByLocationSub('sb.warehouse_location_id')))
            ->where('sb.on_hand_qty', '<', 0);
        $this->applyBranch($query, $filters, 'b.id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'w.name', 'wl.name']);

        $rows = $query->orderBy('sb.on_hand_qty')->selectRaw("p.id as product_id, p.sku_code, p.name_th,
            concat(coalesce(b.name_th, w.name), ' / ', coalesce(wl.name, wl.code)) as location_name,
            sb.on_hand_qty, coalesce(base_unit.name, '-') as unit_name, coalesce(p.default_price, 0) as unit_price,
            sb.on_hand_qty * coalesce(p.default_price, 0) as stock_value")
            ->leftJoin('product_units as base_unit', 'base_unit.id', '=', 'p.base_unit_id')
            ->limit($filters['per_page'])->get();

        return app(StockQuantityFormatter::class)->format($rows);
    }

    private function expiringStock(array $filters): Collection
    {
        $query = DB::table('stock_lots as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sl.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', DB::raw($this->branchByLocationSub('sl.warehouse_location_id')))
            ->where('p.tracks_expiry', true)
            ->where('sl.remaining_qty', '>', 0);

        $this->applyBranch($query, $filters, 'b.id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'sl.lot_number', 'wl.name']);

        return $query->orderByRaw('CASE WHEN sl.expiry_date IS NULL THEN 0 ELSE 1 END')
            ->orderBy('sl.expiry_date')
            ->selectRaw("p.sku_code, p.name_th, sl.lot_number, concat(w.name, ' / ', coalesce(wl.name, wl.code)) as location_name, sl.manufacture_date, sl.expiry_date, sl.remaining_qty, sl.quality_status, p.expiry_warning_days, p.clearance_warning_days, p.clearance_discount_percent, p.default_price")
            ->get()
            ->map(function ($lot) {
                if (! $lot->expiry_date) {
                    $lot->days_left = null;
                    $lot->status = 'ไม่ระบุวันหมดอายุ';
                    $lot->clearance_suggestion = '-';

                    return $lot;
                }
                $lot->days_left = today()->diffInDays(Carbon::parse($lot->expiry_date), false);
                $qualityLabels = ['hold' => 'พักตรวจ', 'quarantine' => 'กักกัน', 'recalled' => 'เรียกคืน'];
                $lot->status = $qualityLabels[$lot->quality_status] ?? ($lot->days_left < 0
                    ? 'หมดอายุแล้ว'
                    : ($lot->days_left <= (int) $lot->expiry_warning_days ? 'ใกล้หมดอายุ' : 'ปกติ'));
                $lot->clearance_suggestion = $lot->days_left >= 0
                    && $lot->days_left <= (int) $lot->clearance_warning_days
                    && (float) $lot->clearance_discount_percent > 0
                    ? number_format((float) $lot->clearance_discount_percent, 2).'% → '.number_format((float) $lot->default_price * (100 - (float) $lot->clearance_discount_percent) / 100, 2).' บาท'
                    : '-';

                return $lot;
            })
            ->filter(fn ($lot) => $lot->quality_status !== 'available'
                || $lot->days_left === null || $lot->days_left <= (int) $lot->expiry_warning_days)
            ->take($filters['per_page'])
            ->values();
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

    private function stockCardDetail(Carbon $from, Carbon $to, array $filters): Collection
    {
        $inTypes = "('in','transfer_in','return_in','transform_in','adjust_in','void_in','opening')";
        $query = DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sm.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'sm.stock_lot_id')
            ->leftJoin('documents as d', 'd.id', '=', 'sm.document_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.created_by')
            ->leftJoin('branches as b', 'b.id', '=', DB::raw("coalesce(".$this->branchByLocationSub('sm.warehouse_location_id').", w.branch_id)"))
            ->whereBetween('sm.movement_date', [$from->toDateString(), $to->toDateString()]);

        $query->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('sm.product_id', $id))
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('sm.warehouse_location_id', $id));
        $this->applyBranch($query, $filters, 'b.id');
        $this->applySearch($query, $filters, ['p.sku_code', 'p.name_th', 'sm.movement_type', 'd.doc_number', 'sl.lot_number']);

        $signedQty = "case when sm.movement_type in {$inTypes} then sm.qty else -sm.qty end";
        $priorBalance = "coalesce((select sum(case when sm0.movement_type in {$inTypes} then sm0.qty else -sm0.qty end)
            from stock_movements sm0
            where sm0.product_id = sm.product_id
              and sm0.warehouse_location_id = sm.warehouse_location_id
              and (sm0.movement_date < sm.movement_date
                   or (sm0.movement_date = sm.movement_date and sm0.created_at < sm.created_at)
                   or (sm0.movement_date = sm.movement_date and sm0.created_at = sm.created_at and sm0.id < sm.id))), 0)";

        return $query
            ->orderBy('sm.movement_date')->orderBy('sm.created_at')->orderBy('sm.id')
            ->selectRaw("sm.movement_date, sm.created_at as movement_time,
                concat(p.sku_code, ' ', p.name_th) as product_name,
                concat(w.name, ' / ', coalesce(wl.name, wl.code)) as location_name,
                coalesce(sl.lot_number, '-') as lot_number,
                coalesce(d.doc_number, '-') as doc_number,
                sm.movement_type,
                {$priorBalance} as balance_before,
                case when sm.movement_type in {$inTypes} then sm.qty else 0 end as qty_in,
                case when sm.movement_type in {$inTypes} then 0 else sm.qty end as qty_out,
                {$priorBalance} + {$signedQty} as balance_after,
                sl.unit_cost as unit_cost,
                coalesce(u.name, '-') as created_by_name")
            ->get()
            ->map(function ($row) {
                $row->balance_before = DecimalMath::round($row->balance_before, 8);
                $row->balance_after = DecimalMath::round($row->balance_after, 8);
                $row->qty_in = DecimalMath::round($row->qty_in, 8);
                $row->qty_out = DecimalMath::round($row->qty_out, 8);
                $row->unit_cost = $row->unit_cost === null ? null : DecimalMath::round($row->unit_cost, 8);

                return $row;
            });
    }

    private function documentColumns(): array
    {
        return [
            ['label' => 'วันที่', 'key' => 'doc_date'],
            ['label' => 'เลขที่', 'key' => 'doc_number'],
            ['label' => 'สาขา', 'key' => 'branch_name'],
            ['label' => 'สายขาย', 'key' => 'sales_area_name'],
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
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['dt.code', 'dt.name_th']);

        return $query
            ->groupBy('dt.id', 'dt.code', 'dt.name_th', 'sa.code', 'sa.name')
            ->orderBy('dt.code')
            ->selectRaw("concat(dt.code, ' - ', dt.name_th) as document_type, coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                count(*) as document_count, sum(d.total_items) as item_count, sum(d.total_amount) as amount")
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
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        if ($codes !== []) {
            $query->whereIn('dt.code', $codes);
            if (array_intersect($codes, ['CREDIT_SALE', 'RECEIPT', 'BOOKING']) !== []) {
                $query->where('d.status', 'active')->whereNull('d.cancelled_at');
            }
        }

        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'dt.code', 'dt.name_th', 'b.code', 'b.name_th', 'c.code', 'c.name_th', 's.code', 's.name_th', 'sm.name']);

        return $query
            ->orderByDesc('d.doc_date')
            ->orderByDesc('d.id')
            ->selectRaw("d.doc_date, concat(dt.code, ' - ', dt.name_th) as document_type, d.doc_number,
                concat(coalesce(b.code, '-'), ' ', coalesce(b.name_th, 'ไม่ระบุสาขา')) as branch_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                coalesce(c.name_th, s.name_th, '-') as party_name, coalesce(sm.name, 'ไม่ระบุ') as salesman_name,
                d.status, d.total_items, d.total_amount")
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
            ->selectRaw("r.receipt_no, r.receipt_date, coalesce(t.name, t.code) as terminal_name, r.status, r.net_sales,
                (select max(p.transfer_account_last4) from pos_payments p
                 where p.pos_receipt_id = r.id and p.method in ('transfer', 'qr', 'bank')) as transfer_account_last4")
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
            ->selectRaw('coalesce(t.name, t.code) as terminal_name, count(*) as receipt_count, sum(r.net_sales) as amount')
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

        $hour = $this->truncateToHour('r.receipt_date');

        return $query
            ->groupBy(DB::raw($hour))
            ->orderBy(DB::raw($hour))
            ->selectRaw("{$hour} as sale_hour, count(*) as receipt_count, sum(r.net_sales) as amount")
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
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->leftJoin('customers as c', 'c.id', '=', 'pd.customer_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'pd.supplier_id')
            ->where('pd.status', 'active')->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'pd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.name_th', 's.name_th']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, d.doc_date, pd.party_type, coalesce(c.name_th, s.name_th, '-') as party_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, pd.status")
            ->limit($filters['per_page'])
            ->get();
    }

    private function paymentAllocations(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('payment_allocations as pa')
            ->join('payment_documents as pd', 'pd.id', '=', 'pa.payment_document_id')
            ->join('documents as d', 'd.id', '=', 'pd.document_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->leftJoin('customer_open_items as oi', 'oi.id', '=', 'pa.customer_open_item_id')
            ->leftJoin('customers as c', 'c.id', '=', 'oi.customer_id')
            ->where('pd.status', 'active')->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);

        $this->applyBranch($query, $filters, 'pd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.name_th']);

        return $query
            ->orderByDesc('d.doc_date')
            ->selectRaw("d.doc_number, coalesce(c.name_th, '-') as customer_name, coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name,
                pa.allocated_amount, pa.discount_amount, pa.wht_amount")
            ->limit($filters['per_page'])
            ->get();
    }

    private function bplusReceiptAllocations(Carbon $from, Carbon $to, array $filters): array
    {
        $query = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('payment_documents as pd', 'pd.document_id', '=', 'd.id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->leftJoin('bplus_sync_documents as bsd', 'bsd.document_id', '=', 'd.id')
            ->where('d.source_system', 'bplus')->where('dt.code', 'RECEIPT')
            ->where('d.status', 'active')->whereNull('d.cancelled_at')->where('pd.status', 'active')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyBranch($query, $filters, 'd.branch_id');
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['d.doc_number', 'c.code', 'c.name_th']);

        $receipts = $query->orderBy('d.doc_date')->orderBy('d.doc_number')
            ->select(['pd.id as payment_document_id', 'd.doc_number', 'd.doc_date', 'd.total_amount', 'c.name_th as customer_name',
                'sa.code as area_code', 'sa.name as area_name', 'bsd.short_amount'])
            ->limit($filters['per_page'] ?? 25)->get();
        $paymentIds = $receipts->pluck('payment_document_id');
        $lines = DB::table('payment_lines')->whereIn('payment_document_id', $paymentIds)->get()->groupBy('payment_document_id');
        $allocations = DB::table('payment_allocations as pa')
            ->leftJoin('customer_open_items as oi', 'oi.id', '=', 'pa.customer_open_item_id')
            ->leftJoin('documents as sd', 'sd.id', '=', 'oi.document_id')
            ->whereIn('pa.payment_document_id', $paymentIds)
            ->get(['pa.payment_document_id', 'sd.doc_number', 'pa.allocated_amount', 'pa.discount_amount', 'pa.wht_amount'])
            ->groupBy('payment_document_id');

        $rows = $receipts->map(function ($receipt) use ($lines, $allocations) {
            $receiptLines = $lines->get($receipt->payment_document_id, collect());
            $receiptAllocations = $allocations->get($receipt->payment_document_id, collect());
            return (object) [
                'receipt_no' => $receipt->doc_number,
                'receipt_date' => $receipt->doc_date,
                'customer_name' => $receipt->customer_name ?? '-',
                'applied_documents' => $receiptAllocations->pluck('doc_number')->filter()->unique()->implode(', ') ?: '-',
                'applied_total' => DecimalMath::sum($receiptAllocations->pluck('allocated_amount'), 2),
                'discount_amount' => DecimalMath::sum($receiptAllocations->pluck('discount_amount'), 2),
                'wht_amount' => DecimalMath::sum($receiptAllocations->pluck('wht_amount'), 2),
                'short_amount' => $receipt->short_amount ?? '0.00',
                'cash_amount' => DecimalMath::sum($receiptLines->where('method', 'cash')->pluck('amount'), 2),
                'transfer_amount' => DecimalMath::sum($receiptLines->where('method', 'transfer')->pluck('amount'), 2),
                'other_amount' => DecimalMath::sum($receiptLines->where('method', 'other')->pluck('amount'), 2),
                'sales_area_name' => trim(($receipt->area_code ?? '').' '.($receipt->area_name ?? '')) ?: 'ไม่ระบุสายขาย',
            ];
        })->values();

        return $this->tableResult('BPlus ใบเสร็จรับเงินตัดหนี้ตามเอกสาร', [
            ['label' => 'เลขที่ใบเสร็จ', 'key' => 'receipt_no'],
            ['label' => 'วันที่', 'key' => 'receipt_date'],
            ['label' => 'ลูกค้า', 'key' => 'customer_name'],
            ['label' => 'บิลที่ตัด', 'key' => 'applied_documents'],
            ['label' => 'ยอดตัด', 'key' => 'applied_total', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'ส่วนลด', 'key' => 'discount_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'หัก ณ ที่จ่าย', 'key' => 'wht_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'เงินขาด', 'key' => 'short_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'เงินสด', 'key' => 'cash_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'โอน', 'key' => 'transfer_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'อื่นๆ', 'key' => 'other_amount', 'type' => 'money', 'class' => 'text-end'],
            ['label' => 'สายขาย', 'key' => 'sales_area_name'],
        ], $rows);
    }

    private function glJournals(Carbon $from, Carbon $to, array $filters): Collection
    {
        $query = DB::table('gl_journals as gj')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'gj.account_id')
            ->leftJoin('documents as d', 'd.id', '=', 'gj.document_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereBetween('gj.entry_date', [$from->toDateString(), $to->toDateString()]);

        app(ReportScope::class)->applyGl($query, 'gj', $filters['branch_id'] ?? null);
        $this->applySalesArea($query, $filters, 'd.sales_area_id');
        $this->applySearch($query, $filters, ['coa.code', 'coa.name_th', 'gj.remark']);

        return $query
            ->orderByDesc('gj.entry_date')
            ->selectRaw("gj.document_id, gj.entry_date, concat(coa.code, ' ', coa.name_th) as account_name,
                coalesce(sa.code || ' - ' || sa.name, 'ไม่ระบุสายขาย') as sales_area_name, gj.debit, gj.credit, gj.remark")
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
        ];
    }
}
