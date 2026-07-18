<?php

namespace App\Http\Controllers;

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
        $scopeBranchName = $branchId ? \App\Models\Branch::whereKey($branchId)->value('name_th') : null;

        // pos_receipts ผูกสาขาผ่าน pos_terminals.branch_id
        $receiptBranchScope = fn ($query, string $receiptAlias) => $query
            ->join('pos_terminals as _bt', '_bt.id', '=', "{$receiptAlias}.pos_terminal_id")
            ->where('_bt.branch_id', $branchId);

        $summary = DB::table('pos_receipts')
            ->when($branchId, fn ($q) => $receiptBranchScope($q, 'pos_receipts'))
            ->whereBetween('receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('count(*) as receipt_count, coalesce(sum(net_sales),0) as total_sales, coalesce(sum(gross_sales),0) as total_gross, coalesce(sum(discount_amount),0) as total_discount')
            ->first();

        $posTerminalSummary = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->when($branchId, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereBetween('r.receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('t.id', 't.code', 't.name')
            ->orderByDesc(DB::raw('sum(r.net_sales)'))
            ->selectRaw("coalesce(t.code, '-') as pos_code, coalesce(t.name, t.code, '-') as pos_name, count(*) as bill_count, coalesce(sum(r.net_sales), 0) as amount")
            ->limit(3)
            ->get();

        $salesDocumentSummary = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('dt.code', 'dt.name_th')
            ->orderByDesc(DB::raw('sum(d.total_amount)'))
            ->selectRaw("dt.code as doc_code, coalesce(dt.name_th, dt.code) as doc_name, count(*) as bill_count, coalesce(sum(d.total_amount), 0) as amount")
            ->limit(3)
            ->get();

        $itemCount = DB::table('pos_receipt_items as i')
            ->join('pos_receipts as r', 'r.id', '=', 'i.pos_receipt_id')
            ->when($branchId, fn ($q) => $receiptBranchScope($q, 'r'))
            ->whereBetween('r.receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->sum('i.qty');

        $byBranch = DB::table('pos_receipts as r')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->join('branches as b', 'b.id', '=', 't.branch_id')
            ->when($branchId, fn ($q) => $q->where('b.id', $branchId))
            ->whereBetween('r.receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('b.id', 'b.code', 'b.name_th')
            ->orderByDesc(DB::raw('sum(r.net_sales)'))
            ->select('b.code', 'b.name_th', DB::raw('count(*) as receipt_count'), DB::raw('sum(r.net_sales) as total_sales'))
            ->get();

        $dailySales = DB::table('pos_receipts')
            ->when($branchId, fn ($q) => $receiptBranchScope($q, 'pos_receipts'))
            ->whereBetween('receipt_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('date(receipt_date) as sale_date, sum(net_sales) as total_sales, count(*) as receipt_count')
            ->groupBy(DB::raw('date(receipt_date)'))
            ->orderBy('sale_date')
            ->get();

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

        $pendingBatches = DB::table('import_batches')
            ->whereIn('status', ['has_error', 'parsed', 'validated'])
            ->orderByDesc('sale_date')
            ->select('id', 'pos_code', 'sale_date', 'status', 'record_count')
            ->limit(20)
            ->get();

        return view('dashboard', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'scopeBranchName' => $scopeBranchName,
            'summary' => $summary,
            'posTerminalSummary' => $posTerminalSummary,
            'salesDocumentSummary' => $salesDocumentSummary,
            'itemCount' => $itemCount,
            'byBranch' => $byBranch,
            'dailySales' => $dailySales,
            'topProducts' => $topProducts,
            'lowStock' => $lowStock,
            'pendingBatches' => $pendingBatches,
        ]);
    }
}
