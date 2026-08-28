<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * หน้าจอผู้บริหาร — ตัวเลขที่ต้องดูทุกวัน อัปเดตเองไม่ต้องกดรีเฟรช
 *
 * แยกจากหน้ารายงานเพราะคนละงาน: รายงานคือการค้นหาคำตอบของคำถามที่ตั้งไว้แล้ว
 * ส่วนหน้านี้คือการเห็นว่าวันนี้ผิดปกติตรงไหนโดยที่ยังไม่มีคำถาม
 *
 * ตัวเลขทั้งหมดอ่านจาก sales_postings ซึ่งเป็นด่านเดียวที่การขายทุกช่องทาง
 * ไหลมารวมกัน จึงไม่มีทางที่หน้านี้กับรายงานจะให้ตัวเลขคนละอย่าง
 */
class ExecutiveDashboardController extends Controller
{
    private const TREND_DAYS = 14;

    public function index(Request $request): View
    {
        $payload = $this->payload($request);

        // ส่งก้อนเดียวให้ Alpine ตั้งต้น หน้าจอจะได้ไม่กระพริบรอ fetch รอบแรก
        return view('executive.index', $payload + ['refreshSeconds' => 60, 'boardData' => $payload]);
    }

    /** ข้อมูลชุดเดียวกันในรูป JSON ให้หน้าจอดึงซ้ำเองเป็นระยะ */
    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request) + ['refreshed_at' => now()->format('H:i:s')]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $branchId = $this->scopedBranchId($request);
        $today = now()->startOfDay();
        $yesterday = $today->copy()->subDay();

        $todayFigures = $this->figuresFor($today, $today, $branchId);
        $yesterdayFigures = $this->figuresFor($yesterday, $yesterday, $branchId);

        return [
            'today' => $todayFigures,
            'compare' => [
                'sales' => $this->changePercent($todayFigures['sales'], $yesterdayFigures['sales']),
                'bills' => $this->changePercent($todayFigures['bills'], $yesterdayFigures['bills']),
                'yesterday_sales' => $yesterdayFigures['sales'],
            ],
            'trend' => $this->trend($branchId),
            'branches' => $this->salesByBranch($today, $branchId),
            'channels' => $this->salesByChannel($today, $branchId),
            'topProducts' => $this->topProducts($today, $branchId),
            'profitAlerts' => $this->profitAlerts($branchId),
            'attention' => $this->needsAttention($branchId),
            'branchName' => $branchId ? DB::table('branches')->where('id', $branchId)->value('name_th') : 'ทุกสาขา',
        ];
    }

    /**
     * สาขาที่ผู้ใช้ดูได้
     *
     * ไม่มีสิทธิ์ข้ามสาขา = เห็นเฉพาะสาขาตัวเองเสมอ ส่งพารามิเตอร์อะไรมาก็ไม่หลุด
     * ผู้จัดการสาขาเห็นยอดสาขาอื่นได้คือเรื่องคนละเรื่องกับการเห็นยอดตัวเอง
     */
    private function scopedBranchId(Request $request): ?int
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('reports.all_branches')) {
            return $user->branch_id ? (int) $user->branch_id : -1;
        }

        $requested = $request->input('branch_id');

        return $requested !== null && $requested !== '' && $requested !== 'all' ? (int) $requested : null;
    }

    /** @return array<string, float|int> */
    private function figuresFor(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $row = DB::table('sales_postings')
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->selectRaw('coalesce(sum(net_sales), 0) as sales,
                         coalesce(sum(cogs_amount), 0) as cost,
                         coalesce(sum(gross_profit), 0) as profit,
                         count(*) as bills')
            ->first();

        $sales = (float) ($row->sales ?? 0);
        $bills = (int) ($row->bills ?? 0);

        return [
            'sales' => round($sales, 2),
            'cost' => round((float) ($row->cost ?? 0), 2),
            'profit' => round((float) ($row->profit ?? 0), 2),
            'margin' => $sales > 0 ? round(((float) ($row->profit ?? 0)) / $sales * 100, 1) : 0.0,
            'bills' => $bills,
            'average_bill' => $bills > 0 ? round($sales / $bills, 2) : 0.0,
        ];
    }

    /** เทียบกับเมื่อวาน — ไม่มีฐานให้เทียบก็บอกตรง ๆ ว่าเทียบไม่ได้ ไม่ใช่ 100% */
    private function changePercent(float|int $now, float|int $before): ?float
    {
        if ((float) $before <= 0.0) {
            return null;
        }

        return round(((float) $now - (float) $before) / (float) $before * 100, 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function trend(?int $branchId): array
    {
        $from = now()->startOfDay()->subDays(self::TREND_DAYS - 1);
        $rows = DB::table('sales_postings')
            ->where('sale_date', '>=', $from->toDateString())
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->groupBy('sale_date')
            ->selectRaw('sale_date, coalesce(sum(net_sales), 0) as sales, coalesce(sum(gross_profit), 0) as profit')
            ->pluck('sales', 'sale_date');

        $trend = [];
        for ($day = 0; $day < self::TREND_DAYS; $day++) {
            $date = $from->copy()->addDays($day);
            $key = $date->toDateString();
            $trend[] = [
                'date' => $key,
                'label' => $date->format('j/n'),
                'sales' => round((float) ($rows[$key] ?? 0), 2),
            ];
        }

        return $trend;
    }

    /** @return array<int, array<string, mixed>> */
    private function salesByBranch(Carbon $day, ?int $branchId): array
    {
        return DB::table('sales_postings as sp')
            ->join('branches as b', 'b.id', '=', 'sp.branch_id')
            ->where('sp.sale_date', $day->toDateString())
            ->when($branchId, fn ($query) => $query->where('sp.branch_id', $branchId))
            ->groupBy('b.code', 'b.name_th')
            ->orderByDesc('sales')
            ->selectRaw('b.code, b.name_th as name, coalesce(sum(sp.net_sales), 0) as sales, count(*) as bills')
            ->get()
            ->map(fn ($row) => ['code' => $row->code, 'name' => $row->name, 'sales' => round((float) $row->sales, 2), 'bills' => (int) $row->bills])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function salesByChannel(Carbon $day, ?int $branchId): array
    {
        return DB::table('sales_postings')
            ->where('sale_date', $day->toDateString())
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->groupBy('channel')
            ->selectRaw('channel, coalesce(sum(net_sales), 0) as sales, count(*) as bills')
            ->get()
            ->map(fn ($row) => [
                'channel' => $row->channel === 'pos' ? 'หน้าร้าน (POS)' : 'หลังบ้าน',
                'sales' => round((float) $row->sales, 2),
                'bills' => (int) $row->bills,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function topProducts(Carbon $day, ?int $branchId): array
    {
        return DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->where('d.status', 'active')
            ->whereDate('d.doc_date', $day->toDateString())
            ->when($branchId, fn ($query) => $query->where('d.branch_id', $branchId))
            ->groupBy('p.sku_code', 'p.name_th')
            ->orderByDesc('amount')
            ->limit(5)
            ->selectRaw('p.sku_code, p.name_th as name, sum(sdi.qty) as qty, sum(sdi.qty * sdi.unit_price) as amount')
            ->get()
            ->map(fn ($row) => [
                'sku' => $row->sku_code, 'name' => $row->name,
                'qty' => round((float) $row->qty, 2), 'amount' => round((float) $row->amount, 2),
            ])
            ->all();
    }

    /** สินค้าที่มียอดขายแต่ margin ต่ำ เพื่อให้ผู้บริหารแก้ราคาหรือสูตรต้นทุนได้ก่อนปิดงวด */
    private function profitAlerts(?int $branchId): array
    {
        return DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->join('products as p', 'p.id', '=', 'sdi.product_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->where('d.status', 'active')
            ->whereDate('d.doc_date', '>=', now()->subDays(30)->toDateString())
            ->when($branchId, fn ($query) => $query->where('d.branch_id', $branchId))
            ->groupBy('p.sku_code', 'p.name_th')
            ->selectRaw('p.sku_code, p.name_th as name, sum(sdi.qty * sdi.unit_price) as sales, sum(coalesce(sdi.cost_amount, 0)) as cost, sum(sdi.qty) as qty')
            ->get()
            ->map(function ($row): array {
                $sales = (float) $row->sales;
                $profit = $sales - (float) $row->cost;
                return ['sku' => $row->sku_code, 'name' => $row->name, 'sales' => round($sales, 2), 'profit' => round($profit, 2), 'margin' => $sales > 0 ? round($profit / $sales * 100, 1) : 0.0, 'qty' => round((float) $row->qty, 2)];
            })
            ->filter(fn (array $row): bool => $row['margin'] < 10)
            ->sortBy('margin')->take(8)->values()->all();
    }

    /**
     * เรื่องที่ต้องตามวันนี้
     *
     * ตั้งใจให้เป็นตัวเลขที่ "ควรเป็นศูนย์" ทั้งหมด อะไรไม่เป็นศูนย์คือมีงานค้าง
     * ผู้บริหารจะได้กวาดตาผ่านแล้วรู้ทันทีว่าต้องถามใคร
     *
     * @return array<int, array<string, mixed>>
     */
    private function needsAttention(?int $branchId): array
    {
        $overdueReceivables = DB::table('customer_open_items')
            ->where('status', 'open')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->selectRaw('count(*) as n, coalesce(sum(balance_amount), 0) as amount')
            ->first();

        $overduePayables = DB::table('supplier_open_items')
            ->where('status', 'open')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->selectRaw('count(*) as n, coalesce(sum(balance_amount), 0) as amount')
            ->first();

        $undeliveredBookings = DB::table('sale_bookings')
            ->whereNotIn('delivery_status', ['delivered', 'cancelled'])
            ->whereNotNull('delivery_due_at')
            ->whereDate('delivery_due_at', '<', now()->toDateString())
            ->count();

        $belowReorder = DB::table('stock_balances as sb')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->when($branchId, fn ($query) => $query->join('warehouse_locations as wl', 'wl.id', '=', 'sb.warehouse_location_id')
                ->join('branches as br', 'br.default_warehouse_location_id', '=', 'wl.id')
                ->where('br.id', $branchId))
            ->whereNotNull('p.reorder_point')
            ->where('p.reorder_point', '>', 0)
            ->whereColumn('sb.on_hand_qty', '<=', 'p.reorder_point')
            ->count();

        return [
            ['label' => 'ลูกหนี้เกินกำหนด', 'count' => (int) ($overdueReceivables->n ?? 0), 'amount' => round((float) ($overdueReceivables->amount ?? 0), 2)],
            ['label' => 'เจ้าหนี้เกินกำหนด', 'count' => (int) ($overduePayables->n ?? 0), 'amount' => round((float) ($overduePayables->amount ?? 0), 2)],
            ['label' => 'ใบจองเลยกำหนดส่ง', 'count' => $undeliveredBookings, 'amount' => null],
            ['label' => 'สินค้าถึงจุดสั่งซื้อ', 'count' => $belowReorder, 'amount' => null],
        ];
    }
}
