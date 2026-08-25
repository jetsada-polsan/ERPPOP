<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->subDays(6);

        // Branch-scoped visibility: users bound to a branch (แคชเชียร์/พนักงานสาขา)
        // เห็นเฉพาะยอดขายสาขาตัวเอง; ส่วนกลาง/ผู้บริหาร (branch_id = null) เห็นทุกสาขา.
        $branchId = auth()->user()?->branchScopeId();
        $scopeBranchName = $branchId ? Branch::whereKey($branchId)->value('name_th') : null;

        // pos_receipts ผูกสาขาผ่าน pos_terminals.branch_id
        $receiptBranchScope = fn ($query, string $receiptAlias) => $query
            ->join('pos_terminals as _bt', '_bt.id', '=', "{$receiptAlias}.pos_terminal_id")
            ->where('_bt.branch_id', $branchId);

        // Dashboard ผู้บริหารอ่าน ledger กลาง: POS และขายหลังบ้านอยู่คนละตาราง
        // ได้ แต่หนึ่งการขายปรากฏครั้งเดียวใน sales_postings.
        $salesSummary = fn (Carbon $rangeFrom, Carbon $rangeTo) => DB::table('sales_postings')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('sale_date', [$rangeFrom->toDateString(), $rangeTo->toDateString()])
            ->selectRaw('count(*) as receipt_count, coalesce(sum(net_sales),0) as total_sales, coalesce(sum(gross_sales),0) as total_gross, coalesce(sum(discount_amount),0) as total_discount, coalesce(sum(cogs_amount),0) as total_cogs, coalesce(sum(gross_profit),0) as gross_profit')
            ->first();
        $summary = $salesSummary($from, $to);
        $periodDays = $from->diffInDays($to) + 1;
        $previous = $salesSummary($from->copy()->subDays($periodDays), $from->copy()->subDay());
        $summary->previous_sales = (float) $previous->total_sales;
        $summary->sales_change_percent = (float) $previous->total_sales === 0.0
            ? null
            : round((((float) $summary->total_sales - (float) $previous->total_sales) / (float) $previous->total_sales) * 100, 1);
        $summary->average_bill = (float) $summary->receipt_count > 0
            ? round((float) $summary->total_sales / (int) $summary->receipt_count, 2)
            : 0;
        $summary->gross_margin_percent = (float) $summary->total_sales > 0
            ? round((float) $summary->gross_profit / (float) $summary->total_sales * 100, 1)
            : null;

        $posTerminalSummary = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->when($branchId, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereBetween('r.receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('t.id', 't.code', 't.name')
            ->orderByDesc(DB::raw('sum(r.net_sales)'))
            ->selectRaw("coalesce(t.code, '-') as pos_code, coalesce(t.name, t.code, '-') as pos_name, count(*) as bill_count, coalesce(sum(r.net_sales), 0) as amount")
            ->limit(3)
            ->get();

        $salesDocumentSummary = DB::table('sales_postings')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('channel')
            ->orderByDesc(DB::raw('sum(net_sales)'))
            ->selectRaw("channel as doc_code, case channel when 'POS' then 'POS' when 'CASH_SALE' then 'ขายสดหลังบ้าน' when 'CREDIT_SALE' then 'ขายเชื่อ' else channel end as doc_name, count(*) as bill_count, coalesce(sum(net_sales), 0) as amount")
            ->limit(3)
            ->get();

        $itemCount = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->when($branchId, fn ($q) => $receiptBranchScope($q, 'r'))
            ->whereBetween('r.receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->sum('i.qty');

        $byBranch = DB::table('sales_postings as s')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->when($branchId, fn ($q) => $q->where('b.id', $branchId))
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('b.id', 'b.code', 'b.name_th')
            ->orderByDesc(DB::raw('sum(s.net_sales)'))
            ->select('b.code', 'b.name_th', DB::raw('count(*) as receipt_count'), DB::raw('sum(s.net_sales) as total_sales'))
            ->get();

        $dailySales = DB::table('sales_postings')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('sale_date, sum(net_sales) as total_sales, count(*) as receipt_count')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get();

        $receivables = DB::table('customer_open_items as oi')
            ->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->whereIn('oi.status', ['open', 'partial'])
            ->selectRaw('count(*) as open_count, coalesce(sum(oi.balance_amount), 0) as open_amount, coalesce(sum(case when oi.due_date < current_date then oi.balance_amount else 0 end), 0) as overdue_amount')
            ->first();

        $topProducts = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->when($branchId, fn ($q) => $receiptBranchScope($q, 'r'))
            ->whereBetween('r.receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('p.id', 'p.sku_code', 'p.name_th')
            ->orderByDesc(DB::raw('sum(i.net_amount)'))
            ->select('p.sku_code', 'p.name_th', DB::raw('sum(i.qty) as total_qty'), DB::raw('sum(i.net_amount) as total_amount'))
            ->limit(20)
            ->get();

        $lowStock = DB::table('stock_balances as sb')
            ->join('products as p', 'p.id', '=', 'sb.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sb.warehouse_location_id')
            ->orderBy('sb.on_hand_qty')
            ->select('p.sku_code', 'p.name_th', 'wl.name as location_name', 'sb.on_hand_qty')
            ->limit(20)
            ->get();

        $expiryAlerts = DB::table('stock_lots as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sl.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->when($branchId, fn ($query) => $query->where('w.branch_id', $branchId))
            ->where('p.tracks_expiry', true)->where('sl.remaining_qty', '>', 0)
            ->orderByRaw('CASE WHEN sl.expiry_date IS NULL THEN 0 ELSE 1 END')->orderBy('sl.expiry_date')
            ->get(['p.sku_code', 'p.name_th', 'sl.lot_number', 'sl.expiry_date', 'sl.remaining_qty', 'sl.quality_status', 'p.expiry_warning_days'])
            ->map(function ($lot) {
                $lot->days_left = $lot->expiry_date ? today()->diffInDays(Carbon::parse($lot->expiry_date), false) : null;

                return $lot;
            })
            ->filter(fn ($lot) => $lot->quality_status !== 'available'
                || $lot->days_left === null || $lot->days_left <= (int) $lot->expiry_warning_days)
            ->take(20)->values();

        // ── ข้อมูลเพิ่มสำหรับแผงควบคุมแบบ Odoo ───────────────────────────
        // คิดเฉพาะตอนใช้ layout นี้ ไม่ให้ layout เดิมช้าลงเพราะ query ที่ไม่ได้ใช้
        $odooLayout = \App\Models\AppSetting::get('erp_layout', 'classic') === 'odoo';
        $openReceipts = 0;
        $poPending = 0;
        $poOverdue = 0;
        $receiptStatus = collect();
        $recentReceipts = collect();
        $cashVariance = collect();

        if ($odooLayout) {
            $receiptScope = fn ($q) => $q
                ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
                ->when($branchId, fn ($qq) => $qq->where('t.branch_id', $branchId));

            $openReceipts = (int) $receiptScope(DB::table('pos_receipts as r'))
                ->where('r.status', 'open')->count();

            $poBase = fn () => DB::table('purchase_orders')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereIn('status', ['approved', 'ordered']);
            $poPending = (int) $poBase()->count();
            // เลยกำหนดรับแล้ว — ของไม่เข้าตามนัดคือสิ่งที่ต้องโทรตามวันนี้
            $poOverdue = (int) $poBase()->whereNotNull('need_by_date')
                ->whereDate('need_by_date', '<', today())->count();

            $receiptStatus = $receiptScope(DB::table('pos_receipts as r'))
                ->whereBetween('r.receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
                ->selectRaw("case when r.voided_at is not null then 'voided' else r.status end as state, count(*) as total")
                ->groupBy('state')->pluck('total', 'state');

            $recentReceipts = $receiptScope(DB::table('pos_receipts as r'))
                ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
                ->leftJoin('users as u', 'u.id', '=', 'r.cashier_id')
                ->orderByDesc('r.receipt_date')->limit(6)
                ->get([
                    'r.receipt_no', 'r.receipt_date', 'r.net_sales', 'r.status', 'r.voided_at',
                    'b.name_th as branch_name', 't.code as pos_code', 'u.name as cashier_name',
                ]);

            // เงินขาด/เกินหน้าเคาน์เตอร์ — ตัวเลขที่เจ้าของอยากเห็นทุกเช้า
            // ข้อมูลมีอยู่ใน pos_shifts อยู่แล้วแต่เดิมไม่เคยเอามาแสดง
            $cashVariance = DB::table('pos_shifts as s')
                ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
                ->leftJoin('pos_terminals as t', 't.id', '=', 's.pos_terminal_id')
                ->leftJoin('users as u', 'u.id', '=', 's.cashier_id')
                ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
                ->whereNotNull('s.closed_at')
                ->orderByDesc('s.closed_at')->limit(6)
                ->get([
                    's.shift_no', 's.closed_at', 's.expected_cash', 's.counted_cash',
                    'b.name_th as branch_name', 't.code as pos_code', 'u.name as cashier_name',
                ])
                ->map(function ($shift) {
                    $shift->variance = (float) $shift->counted_cash - (float) $shift->expected_cash;

                    return $shift;
                });
        }

        return view($odooLayout ? 'dashboard-odoo' : 'dashboard', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'scopeBranchName' => $scopeBranchName,
            'summary' => $summary,
            'posTerminalSummary' => $posTerminalSummary,
            'salesDocumentSummary' => $salesDocumentSummary,
            'itemCount' => $itemCount,
            'byBranch' => $byBranch,
            'dailySales' => $dailySales,
            'receivables' => $receivables,
            'topProducts' => $topProducts,
            'lowStock' => $lowStock,
            'expiryAlerts' => $expiryAlerts,
            'openReceipts' => $openReceipts,
            'poPending' => $poPending,
            'poOverdue' => $poOverdue,
            'receiptStatus' => $receiptStatus,
            'recentReceipts' => $recentReceipts,
            'cashVariance' => $cashVariance,
        ]);
    }
}
