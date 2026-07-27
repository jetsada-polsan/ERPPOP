<?php

namespace App\Services\Sales;

use App\Models\DiscountCard;
use App\Models\FlashSaleItem;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\Promotion;
use App\Models\QtyPromotion;
use App\Models\User;
use App\Services\Inventory\ScaleBarcodeService;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosPricingGuard
{
    public function __construct(
        private readonly MemberPointService $points,
        private readonly ScaleBarcodeService $scaleBarcodes,
    ) {}

    /**
     * Turn scanned scale labels into normal priced lines before any other check.
     *
     * A weighed label carries the total price for that one bag, so its barcode is
     * different on every bag and can never be pre-registered in product_barcodes.
     * The server therefore decodes the label itself and derives the weight from
     * its own per-unit price — the client's qty is never trusted.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public function resolveScaleLines(array $items, int $branchId): array
    {
        $scanned = collect($items)->pluck('barcode')->filter()->unique();
        $registered = $scanned->isEmpty()
            ? collect()
            : ProductBarcode::whereIn('barcode', $scanned)->where('is_active', true)->pluck('barcode')->flip();

        return collect($items)->map(function (array $item) use ($branchId, $registered): array {
            $code = (string) ($item['barcode'] ?? '');

            // บาร์โค้ดที่ลงทะเบียนไว้จริงเป็นบาร์โค้ดสินค้าปกติเสมอ — กันสินค้านำเข้าที่ขึ้นต้น
            // 800/801 (รหัสประเทศอิตาลี) ถูกตีความเป็นป้ายชั่งแล้วคิดเงินผิด
            if ($code === '' || $registered->has($code)) {
                return $item;
            }

            $scale = $this->scaleBarcodes->decode($code);
            if ($scale === null) {
                return $item;
            }

            $product = Product::where('is_active', true)
                ->where(fn ($query) => $query
                    ->where('sku_code', $scale['plu'])
                    ->orWhereHas('barcodes', fn ($barcode) => $barcode
                        ->where('barcode', $scale['plu'])->where('is_active', true)))
                ->first();
            if (! $product) {
                throw new RuntimeException('ไม่พบสินค้าสำหรับรหัสชั่ง '.$scale['plu'].' กรุณาตรวจแฟ้มสินค้า');
            }
            if ((int) $product->id !== (int) $item['product_id']) {
                throw new RuntimeException('ป้ายชั่งไม่ตรงกับสินค้าที่เลือก กรุณาสแกนใหม่');
            }
            if ($scale['price'] <= 0) {
                throw new RuntimeException('ป้ายชั่งไม่มีราคา กรุณาชั่งและพิมพ์ป้ายใหม่');
            }

            $perUnit = $this->campaignPrice(
                (int) $product->id,
                $this->basePrices([(int) $product->id])[(int) $product->id] ?? 0.0,
                $branchId,
            );
            if ($perUnit <= 0) {
                throw new RuntimeException('สินค้าชั่ง '.$scale['plu'].' ยังไม่ได้ตั้งราคาขายต่อหน่วย');
            }

            $item['qty'] = (float) DecimalMath::divide($scale['price'], $perUnit, DecimalMath::QUANTITY_SCALE);
            $item['unit_price'] = $perUnit;
            unset($item['barcode']);

            return $item;
        })->all();
    }

    /**
     * Recalculate the payable total from server-owned masters. Client prices are
     * accepted only when they match, except an explicitly authorised manual discount.
     *
     * @param  array<string,mixed>  $data
     */
    public function validate(array $data, User $user): float
    {
        $items = collect($data['items']);
        $products = Product::whereIn('id', $items->pluck('product_id')->unique())
            ->where('is_active', true)->get()->keyBy('id');
        if ($products->count() !== $items->pluck('product_id')->unique()->count()) {
            throw new RuntimeException('พบสินค้าที่ปิดใช้งานหรือไม่มีในแฟ้มสินค้า กรุณาโหลดข้อมูลสินค้าใหม่');
        }

        $prices = $this->basePrices($products->keys()->all());
        $barcodeValues = $items->pluck('barcode')->filter()->unique();
        $barcodes = ProductBarcode::whereIn('barcode', $barcodeValues)->where('is_active', true)
            ->get()->keyBy('barcode');
        if ($barcodes->count() !== $barcodeValues->count()) {
            throw new RuntimeException('พบบาร์โค้ดที่ปิดใช้งานหรือไม่มีในแฟ้มสินค้า กรุณาสแกนใหม่');
        }
        $defaultTableId = PriceTable::where('is_default', true)->value('id');
        $serverLines = $items->map(function ($item) use ($prices, $data, $barcodes, $defaultTableId, $products) {
            $productId = (int) $item['product_id'];
            $qty = (float) $item['qty'];
            $barcode = filled($item['barcode'] ?? null) ? $barcodes->get($item['barcode']) : null;
            if ($barcode && (int) $barcode->product_id !== $productId) {
                throw new RuntimeException('บาร์โค้ดไม่ตรงกับสินค้า กรุณาสแกนใหม่');
            }
            $basePrice = $prices[$productId] ?? 0;
            if ($barcode && (float) $barcode->price > 0) {
                $basePrice = (float) $barcode->price;
            } elseif ($barcode && $defaultTableId) {
                $basePrice = (float) (ProductPrice::where('product_id', $productId)
                    ->where('price_table_id', $defaultTableId)->where('unit_id', $barcode->unit_id)
                    ->where('is_active', true)->value('price') ?? $basePrice);
            }
            $unitPrice = $this->campaignPrice($productId, $basePrice, (int) $data['branch_id']);
            $factor = $barcode && DecimalMath::compare($barcode->unit_factor, '0.00000001') >= 0
                ? $barcode->unit_factor
                : 1;
            $baseUnitPrice = DecimalMath::divide($unitPrice, $factor, DecimalMath::COST_SCALE);
            $maximum = $products[$productId]->maximum_sale_price;
            if ($maximum !== null && DecimalMath::compare($baseUnitPrice, $maximum) > 0) {
                throw new RuntimeException(
                    "ราคา {$products[$productId]->name_th} เกินราคาขายสูงสุดที่กำหนด "
                    .number_format((float) $maximum, 2).' บาทต่อหน่วยฐาน'
                );
            }

            return [
                'product_id' => $productId,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'unit_factor' => (float) $factor,
            ];
        });

        $expected = DecimalMath::round(DecimalMath::sum($serverLines->map(
            fn ($line) => DecimalMath::multiply($line['qty'], $line['unit_price'])
        )), DecimalMath::DISPLAY_MONEY_SCALE);
        $qtyPromotionDiscount = $this->qtyPromotionDiscount($serverLines, (int) $data['branch_id']);

        $manualDiscount = DecimalMath::round($data['manual_discount_amount'] ?? 0, DecimalMath::DISPLAY_MONEY_SCALE);
        if (DecimalMath::compare($manualDiscount, 0) > 0) {
            if (! $user->hasPermission('pos.discount.override')) {
                throw new RuntimeException('ส่วนลดพิเศษต้องให้ผู้จัดการที่มีสิทธิ์อนุมัติ');
            }
            $expected = DecimalMath::subtract($expected, $manualDiscount, DecimalMath::DISPLAY_MONEY_SCALE);
        }

        if (! empty($data['discount_card_code'])) {
            $card = DiscountCard::where('card_code', $data['discount_card_code'])->first();
            $cardDiscount = $card?->computeDiscount(max(0, (float) $expected));
            if ($cardDiscount === null) {
                throw new RuntimeException('บัตรส่วนลดไม่ผ่านเงื่อนไขเมื่อคำนวณจากเซิร์ฟเวอร์');
            }
            $expected = DecimalMath::subtract($expected, $cardDiscount, DecimalMath::DISPLAY_MONEY_SCALE);
        }

        $redeemPoints = (float) ($data['redeem_points'] ?? 0);
        if ($redeemPoints > 0) {
            $expected = DecimalMath::subtract(
                $expected,
                DecimalMath::multiply($redeemPoints, $this->points->pointValueBaht()),
                DecimalMath::DISPLAY_MONEY_SCALE,
            );
        }
        $expected = DecimalMath::subtract($expected, $qtyPromotionDiscount, DecimalMath::DISPLAY_MONEY_SCALE);

        $expected = DecimalMath::compare($expected, 0) < 0 ? '0.00' : DecimalMath::round($expected, 2);
        if (($data['vat_mode'] ?? 'included') === 'excluded') {
            $vatRate = (float) (DB::table('vat_rates')->where('effective_from', '<=', now()->toDateString())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
                ->orderByDesc('effective_from')->value('rate_percent') ?? 7);
            $expected = DecimalMath::round(
                DecimalMath::divide(DecimalMath::multiply($expected, DecimalMath::add(100, $vatRate)), 100),
                DecimalMath::DISPLAY_MONEY_SCALE,
            );
        }

        $submitted = DecimalMath::round(DecimalMath::sum($items->map(
            fn ($item) => DecimalMath::multiply($item['qty'], $item['unit_price'])
        )), DecimalMath::DISPLAY_MONEY_SCALE);
        if (DecimalMath::compare(DecimalMath::absoluteDifference($submitted, $expected, 2), '0.02') > 0) {
            throw new RuntimeException('ราคาหรือส่วนลดเปลี่ยนจากข้อมูลบนเซิร์ฟเวอร์ กรุณาโหลดสินค้าใหม่แล้วคิดเงินอีกครั้ง');
        }

        $cost = DecimalMath::round(DecimalMath::sum($items->map(
            fn ($item) => DecimalMath::multiply($item['qty'], $products[(int) $item['product_id']]->average_cost)
        )), DecimalMath::DISPLAY_MONEY_SCALE);
        if (DecimalMath::compare($manualDiscount, 0) > 0
            && DecimalMath::compare(DecimalMath::add($submitted, '0.01', 2), $cost) < 0
            && ! $user->hasPermission('pos.sell_below_cost')) {
            throw new RuntimeException('ยอดขายต่ำกว่าทุน ต้องให้ผู้จัดการที่มีสิทธิ์อนุมัติ');
        }

        $vatRate = (float) (DB::table('vat_rates')->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')->value('rate_percent') ?? 7);
        foreach ($items->values() as $index => $item) {
            $product = $products[(int) $item['product_id']];
            if ($product->minimum_margin_percent === null || $product->margin_control_policy !== 'block') {
                continue;
            }
            $factor = $serverLines->values()[$index]['unit_factor'] ?? 1;
            $netUnitRevenue = DecimalMath::divide($item['unit_price'], $factor, DecimalMath::COST_SCALE);
            if ($product->is_vat && $vatRate > 0) {
                $netUnitRevenue = DecimalMath::divide(
                    DecimalMath::multiply($netUnitRevenue, 100),
                    DecimalMath::add(100, $vatRate),
                    DecimalMath::COST_SCALE,
                );
            }
            if (DecimalMath::compare($netUnitRevenue, 0) <= 0) {
                $marginPercent = '-1000';
            } else {
                $marginPercent = DecimalMath::multiply(
                    DecimalMath::divide(
                        DecimalMath::subtract($netUnitRevenue, $product->average_cost),
                        $netUnitRevenue,
                        DecimalMath::COST_SCALE,
                    ),
                    100,
                    DecimalMath::COST_SCALE,
                );
            }
            if (DecimalMath::compare($marginPercent, $product->minimum_margin_percent) < 0
                && ! $user->hasPermission('pos.sell_below_cost')) {
                throw new RuntimeException(
                    "กำไร {$product->name_th} ต่ำกว่าเกณฑ์ "
                    .number_format((float) $product->minimum_margin_percent, 2)
                    .'% ต้องให้ผู้จัดการอนุมัติ'
                );
            }
        }

        return (float) $submitted;
    }

    /**
     * Convert verified selling units to base stock units while preserving line totals.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public function normalizeItems(array $items): array
    {
        $barcodeValues = collect($items)->pluck('barcode')->filter()->unique();
        $barcodes = ProductBarcode::whereIn('barcode', $barcodeValues)->where('is_active', true)
            ->get()->keyBy('barcode');

        return collect($items)->map(function (array $item) use ($barcodes): array {
            $barcode = filled($item['barcode'] ?? null) ? $barcodes->get($item['barcode']) : null;
            if (! $barcode) {
                unset($item['barcode']);

                return $item;
            }
            if ((int) $barcode->product_id !== (int) $item['product_id']) {
                throw new RuntimeException('บาร์โค้ดไม่ตรงกับสินค้า กรุณาสแกนใหม่');
            }
            $factor = DecimalMath::compare($barcode->unit_factor, '0.00000001') >= 0
                ? $barcode->unit_factor
                : '0.00000001';
            $item['qty'] = (float) DecimalMath::multiply($item['qty'], $factor, DecimalMath::QUANTITY_SCALE);
            $item['unit_price'] = (float) DecimalMath::divide($item['unit_price'], $factor, DecimalMath::COST_SCALE);
            unset($item['barcode']);

            return $item;
        })->all();
    }

    /** @param array<int,int> $productIds @return array<int,float> */
    private function basePrices(array $productIds): array
    {
        $products = Product::whereIn('id', $productIds)->get(['id', 'default_price'])->keyBy('id');
        $tableId = PriceTable::where('is_default', true)->value('id');
        $rows = $tableId ? ProductPrice::where('price_table_id', $tableId)
            ->whereIn('product_id', $productIds)->where('is_active', true)
            ->orderByRaw('CASE WHEN unit_id IS NULL THEN 0 ELSE 1 END')->get()->groupBy('product_id') : collect();

        return collect($productIds)->mapWithKeys(function ($id) use ($products, $rows) {
            $row = $rows->get($id)?->first();

            return [$id => (float) ($row?->price ?? $products[$id]?->default_price ?? 0)];
        })->all();
    }

    private function campaignPrice(int $productId, float $basePrice, int $branchId): float
    {
        $today = now()->toDateString();
        $prices = Promotion::where('is_active', true)->where('product_id', $productId)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $today))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $today))
            ->where(fn ($query) => $query->whereNull('min_qty')->orWhere('min_qty', '<=', 1))
            ->whereNull('min_amount')
            ->get()->map(function ($promotion) use ($basePrice) {
                if ((float) $promotion->discount_amount > 0) {
                    return max(0, $basePrice - (float) $promotion->discount_amount);
                }

                return max(0, round($basePrice * (1 - ((float) $promotion->discount_percent / 100)), 2));
            })->push($basePrice);

        $flash = FlashSaleItem::with('flashSale')->where('product_id', $productId)->get()
            ->filter(fn ($item) => $item->flashSale?->isRunningAt(now())
                && ($item->flashSale->branch_id === null || (int) $item->flashSale->branch_id === $branchId))
            ->min(fn ($item) => (float) $item->flash_price);

        return (float) DecimalMath::round(
            min((float) $prices->min(), $flash !== null ? (float) $flash : $basePrice),
            DecimalMath::COST_SCALE,
        );
    }

    private function qtyPromotionDiscount($lines, int $branchId): float
    {
        $discount = 0.0;
        $promotions = QtyPromotion::runningToday()
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))->get();
        foreach ($promotions as $promotion) {
            $trigger = $lines->firstWhere('product_id', (int) $promotion->product_id);
            if (! $trigger || (float) $promotion->min_qty <= 0) {
                continue;
            }
            $sets = floor((float) $trigger['qty'] / (float) $promotion->min_qty);
            if ($sets <= 0) {
                continue;
            }
            if ($promotion->promo_type === 'discount') {
                $discount += $promotion->discount_type === 'percent'
                    ? $sets * (float) $promotion->min_qty * (float) $trigger['unit_price'] * (float) $promotion->discount_value / 100
                    : $sets * (float) $promotion->discount_value;
            } elseif ($promotion->promo_type === 'free_item') {
                $gift = $lines->firstWhere('product_id', (int) $promotion->free_product_id);
                if ($gift) {
                    $freeQty = min((float) $gift['qty'], $sets * (float) $promotion->free_qty);
                    $discount += $freeQty * (float) $gift['unit_price'];
                }
            }
        }

        return round($discount, 2);
    }
}
