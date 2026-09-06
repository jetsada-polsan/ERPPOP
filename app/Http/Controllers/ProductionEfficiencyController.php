<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * รายงานเปรียบเทียบแผน vs จริงของงานผลิต แยกสองส่วนเพราะเป็นคนละระบบ/ตารางกัน:
 *
 * 1) "ใบสั่งผลิตตามสูตร" (production_orders/production_recipes ผ่าน ProductionController)
 *    ไม่เคยมีรายงานเทียบต้นทุน/ปริมาณแผนกับจริงมาก่อน - คำนวณต้นทุนตามแผนจากสูตร x
 *    ต้นทุนเฉลี่ยปัจจุบันของวัตถุดิบ (standard cost ปัจจุบัน ไม่ใช่ต้นทุน ณ วันที่ผลิต
 *    จริง เพราะระบบไม่ได้ snapshot ต้นทุนไว้ในสูตร) เทียบกับต้นทุนจริงที่บันทึกไว้แล้ว
 *    ในเอกสาร PRODUCTION_RECEIPT (ตัด FIFO จริงผ่าน ProductionReceiptService)
 *
 * 2) "แปรรูปชั่งน้ำหนัก" (production_batches ผ่าน StockTransformService) มี yield%/
 *    ต้นทุน/margin บันทึกไว้ต่อรอบอยู่แล้ว แต่ไม่เคยมีหน้าสรุปภาพรวมมาก่อนเช่นกัน -
 *    ส่วนนี้แค่รวมยอดของที่มีอยู่แล้ว ไม่ได้คำนวณอะไรใหม่
 */
class ProductionEfficiencyController extends Controller
{
    public function index(Request $request): View
    {
        $from = $this->parseDate($request->query('from')) ?? now()->subDays(30)->startOfDay();
        $to = $this->parseDate($request->query('to'))?->endOfDay() ?? now()->endOfDay();
        $branchId = $request->integer('branch_id') ?: null;

        $orders = ProductionOrder::with([
            'recipe.items.product:id,average_cost',
            'finishedProduct:id,sku_code,name_th,base_unit_id',
            'finishedProduct.baseUnit:id,name',
            'branch:id,code,name_th',
            'closedBy:id,name',
        ])
            ->where('status', 'completed')
            ->whereBetween('doc_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('doc_date')
            ->get();

        $actualCostByDocNo = Document::query()
            ->whereHas('documentType', fn ($q) => $q->where('code', 'PRODUCTION_RECEIPT'))
            ->whereIn('reference', $orders->pluck('doc_no'))
            ->selectRaw('reference, sum(total_amount) as actual_cost')
            ->groupBy('reference')
            ->pluck('actual_cost', 'reference');

        $rows = $orders->map(function (ProductionOrder $order) use ($actualCostByDocNo) {
            $recipe = $order->recipe;
            $plannedUnitCost = null;
            if ($recipe && DecimalMath::compare($recipe->output_qty, 0) > 0) {
                $recipeCost = $recipe->items->sum(fn ($item) => (float) $item->qty * (float) ($item->product->average_cost ?? 0));
                $plannedUnitCost = $recipeCost / (float) $recipe->output_qty;
            }
            $producedQty = (float) $order->produced_qty;
            $plannedCost = $plannedUnitCost !== null ? $plannedUnitCost * $producedQty : null;
            $actualCost = (float) ($actualCostByDocNo[$order->doc_no] ?? 0);
            $variance = $plannedCost !== null ? $actualCost - $plannedCost : null;
            $variancePercent = ($plannedCost !== null && $plannedCost > 0.0001) ? ($variance / $plannedCost * 100) : null;
            $plannedQty = (float) $order->planned_qty;
            $fulfillmentPercent = $plannedQty > 0.0001 ? ($producedQty / $plannedQty * 100) : 0.0;

            return [
                'order' => $order,
                'planned_cost' => $plannedCost,
                'actual_cost' => $actualCost,
                'variance' => $variance,
                'variance_percent' => $variancePercent,
                'fulfillment_percent' => $fulfillmentPercent,
                'closed_short' => (bool) $order->closed_at,
            ];
        })->values();

        $withPlanCost = $rows->filter(fn ($r) => $r['planned_cost'] !== null);
        $summary = [
            'count' => $rows->count(),
            'avg_fulfillment' => $rows->isNotEmpty() ? $rows->avg('fulfillment_percent') : 0.0,
            'total_planned_cost' => $withPlanCost->sum('planned_cost'),
            'total_actual_cost' => $rows->sum('actual_cost'),
            'over_budget_count' => $rows->filter(fn ($r) => $r['variance'] !== null && $r['variance'] > 0.0001)->count(),
            'closed_short_count' => $rows->filter(fn ($r) => $r['closed_short'])->count(),
            'missing_recipe_count' => $rows->count() - $withPlanCost->count(),
        ];

        $batches = ProductionBatch::with([
            'document:id,doc_date,branch_id',
            'document.branch:id,code,name_th',
            'outputProduct:id,sku_code,name_th',
        ])
            ->whereHas('document', fn ($q) => $q->whereBetween('doc_date', [$from->toDateString(), $to->toDateString()])
                ->when($branchId, fn ($bq) => $bq->where('branch_id', $branchId)))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $batchSummary = [
            'count' => $batches->count(),
            'avg_yield' => $batches->isNotEmpty() ? $batches->avg('yield_percent') : 0.0,
            'avg_margin' => $batches->isNotEmpty() ? $batches->avg('estimated_margin_percent') : 0.0,
            'total_input_cost' => $batches->sum('total_input_cost'),
            'total_loss_cost' => $batches->sum('loss_cost_amount'),
        ];

        return view('production.efficiency', [
            'from' => $from,
            'to' => $to,
            'branchId' => $branchId,
            'branches' => Branch::where('is_active', true)->orderBy('code')->get(),
            'rows' => $rows,
            'summary' => $summary,
            'batches' => $batches,
            'batchSummary' => $batchSummary,
        ]);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
