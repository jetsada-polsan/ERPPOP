<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\BranchStockPolicy;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * แนะนำว่าสาขาปลายทางควรได้รับสินค้าอะไร/เท่าไรจากคลังต้นทาง (โอนย้ายระหว่างสาขา)
 * ต่างจาก ReplenishmentService (แนะนำซื้อจาก supplier ระดับบริษัท) ตรงที่มองสต๊อก/
 * ยอดขายเฉพาะสาขาปลายทาง และเพดานด้วยของจริงที่คลังต้นทางมี
 *
 * เกณฑ์ Min/Max อ่านจาก branch_stock_policies ก่อน ถ้าสาขานั้นไม่ได้ตั้งไว้สำหรับ
 * สินค้านั้น fallback ไปที่ products.minimum_stock/maximum_stock (ค่ากลาง) สินค้าที่
 * ไม่มีเกณฑ์ทั้งสองระดับเลยจะไม่ถูกแนะนำ (ไม่เดาเกณฑ์เอง)
 *
 * v1: ใช้วิธี "เติมเต็มพื้นที่ว่าง" (เหมือนโหมด full-to-max ของ MNSP1760/Bplus) -
 * ปัดลงตามขนาดบรรจุ (pack size) เพื่อไม่ให้แนะนำเกินพื้นที่ว่างจริง ยังไม่ทำโหมด
 * "จำนวนใช้จริง/ประมาณ" (สำหรับกรณีสต๊อกไม่แม่นยำ) - เพิ่มทีหลังได้ถ้าจำเป็น
 */
class BranchReplenishmentService
{
    /** @return Collection<int, array<string, mixed>> */
    public function suggestions(int $sourceBranchId, int $destinationBranchId, int $salesDays = 30, int $safetyDays = 3): Collection
    {
        if ($sourceBranchId === $destinationBranchId) {
            throw new RuntimeException('คลังต้นทางและสาขาปลายทางต้องไม่ใช่สาขาเดียวกัน');
        }

        $source = Branch::findOrFail($sourceBranchId);
        $destination = Branch::findOrFail($destinationBranchId);
        $fromLocationId = $source->default_warehouse_location_id;
        $toLocationId = $destination->default_warehouse_location_id;
        if (! $fromLocationId) {
            throw new RuntimeException("สาขา {$source->code} ยังไม่ได้ตั้งคลังต้นทาง (default) - ติดต่อผู้ดูแลระบบ");
        }
        if (! $toLocationId) {
            throw new RuntimeException("สาขา {$destination->code} ยังไม่ได้ตั้งคลังปลายทาง (default) - ติดต่อผู้ดูแลระบบ");
        }

        $from = now()->subDays(max(1, $salesDays))->toDateString();

        $balances = fn (int $locationId) => DB::table('stock_balances')
            ->where('warehouse_location_id', $locationId)
            ->selectRaw('product_id, sum(on_hand_qty) as on_hand, sum(reserved_qty) as reserved')
            ->groupBy('product_id')->get()->keyBy('product_id');

        $destStock = $balances($toLocationId);
        $sourceStock = $balances($fromLocationId);

        // ยอดขายสาขาปลายทางย้อนหลัง N วัน - POS checkout ก็ผ่าน CashSaleService
        // (document_type CASH_SALE) เหมือนขายหลังร้าน จึงครอบคลุมยอดขายหน้าร้านด้วย
        $sales = DB::table('stock_movements as sm')
            ->join('documents as d', 'd.id', '=', 'sm.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('d.branch_id', $destinationBranchId)->where('sm.movement_type', 'out')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->whereDate('sm.movement_date', '>=', $from)
            ->selectRaw('sm.product_id, sum(sm.qty) as sold_qty')
            ->groupBy('sm.product_id')->pluck('sold_qty', 'product_id');

        // ใบขอโอน/ใบโอนที่ยังไม่ active เข้าสาขานี้อยู่แล้ว - กันแนะนำซ้ำ
        $incoming = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('dt.code', 'STOCK_TRANSFER')->where('d.status', 'pending')
            ->where('sd.to_warehouse_location_id', $toLocationId)
            ->selectRaw('sdi.product_id, sum(sdi.qty) as incoming_qty')
            ->groupBy('sdi.product_id')->pluck('incoming_qty', 'product_id');

        $policies = BranchStockPolicy::where('branch_id', $destinationBranchId)
            ->where('is_active', true)->get()->keyBy('product_id');

        return Product::query()->where('is_active', true)
            ->with('baseUnit:id,code,name,qty_per_base_unit')
            ->orderBy('sku_code')->get()
            ->map(function (Product $product) use ($policies, $destStock, $sourceStock, $sales, $incoming, $salesDays, $safetyDays): ?array {
                $policy = $policies->get($product->id);
                $minimum = (float) ($policy?->minimum_stock ?? $product->minimum_stock ?? 0);
                $maximum = (float) ($policy?->maximum_stock ?? $product->maximum_stock ?? 0);
                if ($maximum <= 0.0001) {
                    return null; // ไม่มีเกณฑ์ตั้งไว้เลย (ทั้งต่อสาขาและระดับสินค้า) - ไม่เดาเอง
                }

                $destBalance = $destStock->get($product->id);
                $onHand = (float) ($destBalance->on_hand ?? 0);
                $reserved = (float) ($destBalance->reserved ?? 0);
                $available = $onHand - $reserved;
                $incomingQty = (float) ($incoming[$product->id] ?? 0);
                $soldQty = (float) ($sales[$product->id] ?? 0);
                $dailySales = $soldQty / max(1, $salesDays);

                $reorderPoint = $policy?->reorder_point !== null
                    ? (float) $policy->reorder_point
                    : ($product->reorder_point !== null ? (float) $product->reorder_point : max($minimum, $dailySales * $safetyDays));

                $stockPosition = $available + $incomingQty;
                if ($stockPosition > $reorderPoint + 0.0001) {
                    return null; // ยังไม่ถึงจุดสั่งเติมของสาขานี้
                }

                $rawSuggestion = max(0, $maximum - $stockPosition);
                $packSize = $product->baseUnit?->packSize() ?? 1.0;
                $suggested = $packSize > 1 ? floor(($rawSuggestion + 0.0001) / $packSize) * $packSize : $rawSuggestion;

                $sourceBalance = $sourceStock->get($product->id);
                $sourceAvailable = max(0, (float) ($sourceBalance->on_hand ?? 0) - (float) ($sourceBalance->reserved ?? 0));
                $suggested = min($suggested, $sourceAvailable);
                if ($suggested <= 0.0001) {
                    return null; // คลังต้นทางไม่มีของพอจะส่ง หรือปัดลงเหลือ 0 แพ็ค
                }

                return [
                    'product_id' => $product->id,
                    'sku_code' => $product->sku_code,
                    'name_th' => $product->name_th,
                    'unit' => $product->baseUnit?->cleanName() ?? '-',
                    'destination_on_hand' => $onHand,
                    'destination_available' => $available,
                    'incoming' => $incomingQty,
                    'sold_qty' => $soldQty,
                    'daily_sales' => round($dailySales, 4),
                    'source_available' => $sourceAvailable,
                    'minimum_stock' => $minimum,
                    'maximum_stock' => $maximum,
                    'reorder_point' => $reorderPoint,
                    'urgency' => $available <= 0 ? 'critical' : 'planned',
                    'suggested_qty' => round($suggested, 4),
                    'has_branch_policy' => $policy !== null,
                ];
            })->filter()->sortByDesc(fn ($row) => $row['urgency'] === 'critical' ? 1 : 0)->values();
    }
}
