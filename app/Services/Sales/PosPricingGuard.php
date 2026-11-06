<?php

namespace App\Services\Sales;

use App\Models\DiscountCard;
use App\Models\FlashSaleItem;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\QtyPromotion;
use App\Models\User;
use App\Services\Inventory\ScaleBarcodeService;
use App\Support\DecimalMath;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosPricingGuard
{
    public function __construct(
        private readonly MemberPointService $points,
        private readonly ScaleBarcodeService $scaleBarcodes,
        private readonly PosPriceScheduleService $priceSchedules,
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
            $weightLabel = ($scale['value_type'] ?? 'price') === 'weight';
            if ($weightLabel ? (float) ($scale['weight_kg'] ?? 0) <= 0 : $scale['price'] <= 0) {
                throw new RuntimeException('ป้ายชั่งไม่มีน้ำหนักหรือราคา กรุณาชั่งและพิมพ์ป้ายใหม่');
            }

            $perUnit = $this->campaignPrice(
                (int) $product->id,
                $this->basePrices([(int) $product->id], $branchId)[(int) $product->id] ?? 0.0,
                $branchId,
            );
            if ($perUnit <= 0) {
                throw new RuntimeException('สินค้าชั่ง '.$scale['plu'].' ยังไม่ได้ตั้งราคาขายต่อหน่วย');
            }

            $item['qty'] = $weightLabel
                ? (float) $scale['weight_kg']
                : (float) DecimalMath::divide($scale['price'], $perUnit, DecimalMath::QUANTITY_SCALE);
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
    /**
     * @param  string|null  $offlineApprovedBy  ผู้อนุมัติส่วนลดที่หน้าร้าน (เฉพาะเครื่อง POS ที่ผูกแล้ว)
     *                                          เงินถูกเก็บจากลูกค้าไปแล้วตอนบิลมาถึง การปฏิเสธจะทำให้บิลค้างในเครื่อง
     *                                          ตลอดไป จึงบันทึกตามพร้อมชื่อผู้อนุมัติแทนการปฏิเสธ
     */
    public function validate(array $data, User $user, ?string $offlineApprovedBy = null, ?array &$summary = null): float
    {
        $approvedAtCounter = filled($offlineApprovedBy);
        $items = collect($data['items']);
        $products = Product::whereIn('id', $items->pluck('product_id')->unique())
            ->where('is_active', true)->get()->keyBy('id');
        if ($products->count() !== $items->pluck('product_id')->unique()->count()) {
            throw new RuntimeException('พบสินค้าที่ปิดใช้งานหรือไม่มีในแฟ้มสินค้า กรุณาโหลดข้อมูลสินค้าใหม่');
        }

        $prices = $this->basePrices($products->keys()->all(), (int) $data['branch_id']);
        $barcodeValues = $items->pluck('barcode')->filter()->unique();
        $barcodes = ProductBarcode::whereIn('barcode', $barcodeValues)->where('is_active', true)
            ->get()->keyBy('barcode');
        if ($barcodes->count() !== $barcodeValues->count()) {
            throw new RuntimeException('พบบาร์โค้ดที่ปิดใช้งานหรือไม่มีในแฟ้มสินค้า กรุณาสแกนใหม่');
        }
        $defaultTableId = PriceTable::where('is_default', true)->value('id');
        $scheduledUnitPrices = $this->priceSchedules
            ->active($products->keys()->all(), (int) $data['branch_id'], now())
            ->keyBy(fn ($schedule) => $schedule->product_id.':'.($schedule->unit_id ?? 'base'));
        $serverLines = $items->map(function ($item) use ($prices, $data, $barcodes, $defaultTableId, $products, $scheduledUnitPrices) {
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
            if ($barcode) {
                $unitSchedule = $scheduledUnitPrices->get($productId.':'.$barcode->unit_id);
                if ($unitSchedule) {
                    $basePrice = (float) $unitSchedule->price;
                }
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

        $gross = DecimalMath::round(DecimalMath::sum($serverLines->map(
            fn ($line) => DecimalMath::multiply($line['qty'], $line['unit_price'])
        )), DecimalMath::DISPLAY_MONEY_SCALE);
        $expected = $gross;
        $qtyPromotionDiscount = $this->qtyPromotionDiscount($serverLines, (int) $data['branch_id'], (bool) ($data['bill_promotions'] ?? false), (bool) ($data['mix_promotions'] ?? false));

        $legacyPromotionDiscount = $this->qtyPromotionDiscount($serverLines, (int) $data['branch_id'], (bool) ($data['bill_promotions'] ?? false), (bool) ($data['mix_promotions'] ?? false), false);
        $legacyExtra = DecimalMath::subtract($qtyPromotionDiscount, $legacyPromotionDiscount, 2);
        if (DecimalMath::compare($legacyExtra, 0) < 0) {
            $legacyExtra = '0.00';
        }

        $manualDiscount = DecimalMath::round($data['manual_discount_amount'] ?? 0, DecimalMath::DISPLAY_MONEY_SCALE);
        if (DecimalMath::compare($manualDiscount, 0) > 0) {
            if (! $approvedAtCounter && ! $user->hasPermission('pos.discount.override')) {
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
            $legacyExtra = DecimalMath::divide(DecimalMath::multiply($legacyExtra, DecimalMath::add(100, $vatRate)), 100, 2);
            $gross = DecimalMath::round(
                DecimalMath::divide(DecimalMath::multiply($gross, DecimalMath::add(100, $vatRate)), 100),
                DecimalMath::DISPLAY_MONEY_SCALE,
            );
            $expected = DecimalMath::round(
                DecimalMath::divide(DecimalMath::multiply($expected, DecimalMath::add(100, $vatRate)), 100),
                DecimalMath::DISPLAY_MONEY_SCALE,
            );
        }

        $submitted = DecimalMath::round(DecimalMath::sum($items->map(
            fn ($item) => DecimalMath::multiply($item['qty'], $item['unit_price'])
        )), DecimalMath::DISPLAY_MONEY_SCALE);
        $legacyUpper = DecimalMath::add($expected, $legacyExtra, 2);
        if (DecimalMath::compare(DecimalMath::absoluteDifference($submitted, $expected, 2), '0.02') > 0
            && ! (DecimalMath::compare($legacyExtra, 0) > 0
                && DecimalMath::compare($submitted, $expected) >= 0
                && DecimalMath::compare($submitted, $legacyUpper) <= 0
                && DecimalMath::compare($submitted, $gross) <= 0)) {
            throw new RuntimeException('ราคาหรือส่วนลดเปลี่ยนจากข้อมูลบนเซิร์ฟเวอร์ กรุณาโหลดสินค้าใหม่แล้วคิดเงินอีกครั้ง');
        }

        $discount = DecimalMath::subtract($gross, $submitted, DecimalMath::DISPLAY_MONEY_SCALE);
        if (DecimalMath::compare($discount, 0) < 0) {
            $discount = '0.00';
        }
        $summary = [
            'gross_sales' => (float) $gross,
            'discount_amount' => (float) $discount,
            'net_sales' => (float) $submitted,
        ];

        $cost = DecimalMath::round(DecimalMath::sum($items->map(
            fn ($item) => DecimalMath::multiply($item['qty'], $products[(int) $item['product_id']]->average_cost)
        )), DecimalMath::DISPLAY_MONEY_SCALE);
        if (DecimalMath::compare($manualDiscount, 0) > 0
            && DecimalMath::compare(DecimalMath::add($submitted, '0.01', 2), $cost) < 0
            && ! $approvedAtCounter
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
                && ! ($approvedAtCounter && DecimalMath::compare($manualDiscount, 0) > 0)
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
    private function basePrices(array $productIds, int $branchId): array
    {
        $products = Product::whereIn('id', $productIds)->get(['id', 'default_price'])->keyBy('id');
        $tableId = PriceTable::where('is_default', true)->value('id');
        $rows = $tableId ? ProductPrice::where('price_table_id', $tableId)
            ->whereIn('product_id', $productIds)->where('is_active', true)
            ->get()->groupBy('product_id') : collect();
        $unitFactors = ProductUnit::pluck('qty_per_base_unit', 'id');

        // กติกาเดียวกับรายการสินค้าที่ส่งให้เครื่อง POS (PosController::products):
        // ราคาหน่วยฐาน (unit_id ว่าง) -> ไม่มีก็ใช้ราคาหน่วยเล็กสุด -> ไม่มีก็ default_price
        // เดิมหยิบแถวแรกที่เจอ สินค้าที่มีแต่ราคาหลายหน่วย (เช่น ตับหมู 79 กับ 10 บาท)
        // จึงได้ราคาไม่ตรงกับที่เครื่องขาย บิลถูกปฏิเสธทุกใบ
        $basePrices = collect($productIds)->mapWithKeys(function ($id) use ($products, $rows, $unitFactors) {
            $forProduct = $rows->get($id, collect());
            $row = $forProduct->firstWhere('unit_id', null)
                ?? $forProduct->sortBy(fn ($r) => (float) ($unitFactors[$r->unit_id] ?? 1))->first();

            return [$id => (float) ($row?->price ?? $products[$id]?->default_price ?? 0)];
        });
        $overrides = $this->priceSchedules->active($productIds, $branchId, now())
            ->keyBy(fn ($schedule) => $schedule->product_id.':'.($schedule->unit_id ?? 'base'));

        return $basePrices->mapWithKeys(function ($price, $productId) use ($overrides) {
            $schedule = $overrides->get($productId.':base');

            return [$productId => (float) ($schedule?->price ?? $price)];
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

    private function qtyPromotionDiscount($lines, int $branchId, bool $billPromotions = false, bool $mixPromotions = false, bool $aggregate = true): float
    {
        if ($aggregate) {
            // Prices originate from server masters, never submitted cashier prices.
            $lines = $lines->groupBy('product_id')->map(function ($rows) {
                $first = $rows->first();
                $first['qty'] = DecimalMath::sum($rows->pluck('qty'));

                return $first;
            })->values();
        }
        $min = fn ($a, $b) => DecimalMath::compare($a, $b) <= 0 ? $a : $b;
        $positive = fn ($value) => DecimalMath::compare($value, 0) > 0 ? $value : '0';
        $floor = fn ($qty, $need) => (int) (string) DecimalMath::of($qty)->dividedBy(DecimalMath::of($need), 0, RoundingMode::Down);
        $discount = '0';
        $promotions = QtyPromotion::runningToday()
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->when(! $billPromotions, fn ($query) => $query->whereNotIn('promo_type', QtyPromotion::BILL_TYPES))
            ->when(! $mixPromotions, fn ($query) => $query->whereNotIn('promo_type', [...QtyPromotion::MIX_TYPES, 'bill_addon']))
            ->with('groupProducts:products.id')->get();
        $billPromotionList = $promotions->filter(fn (QtyPromotion $promotion) => $promotion->isBillLevel());
        foreach ($promotions->whereIn('promo_type', QtyPromotion::MIX_TYPES) as $promotion) {
            $group = $promotion->groupProducts->pluck('id')->map(fn ($id) => (int) $id)->all();
            $units = [];
            foreach ($lines as $line) {
                if (in_array((int) $line['product_id'], $group, true) && DecimalMath::compare($line['unit_price'], 0) > 0) {
                    for ($i = 0; $i < $floor($line['qty'], 1); $i++) {
                        $units[] = $line['unit_price'];
                    }
                }
            }
            usort($units, fn ($a, $b) => DecimalMath::compare($b, $a));
            $need = $floor($promotion->min_qty, 1);
            if ($promotion->promo_type === 'mix_bundle' && $need > 0) {
                $sets = intdiv(count($units), $need);
                $discount = DecimalMath::add($discount, $positive(DecimalMath::subtract(
                    DecimalMath::sum(array_slice($units, 0, $sets * $need)), DecimalMath::multiply($sets, $promotion->bundle_price))));
            } elseif ($promotion->promo_type === 'mix_free' && $need > 0) {
                $free = $floor($promotion->free_qty, 1);
                $sets = intdiv(count($units), $need + $free);
                $discount = DecimalMath::add($discount, DecimalMath::sum(array_slice(array_reverse($units), 0, $sets * $free)));
            }
        }
        foreach ($promotions->reject(fn ($p) => $p->isBillLevel() || in_array($p->promo_type, QtyPromotion::MIX_TYPES, true)) as $promotion) {
            $trigger = $lines->firstWhere('product_id', (int) $promotion->product_id);
            if (! $trigger || DecimalMath::compare($promotion->min_qty, 0) <= 0) {
                continue;
            }
            $sets = $floor($trigger['qty'], $promotion->min_qty);
            if ($sets <= 0) {
                continue;
            }
            if ($promotion->promo_type === 'discount') {
                $amount = $promotion->discount_type === 'percent'
                    ? DecimalMath::divide(DecimalMath::multiply(DecimalMath::multiply(DecimalMath::multiply($sets, $promotion->min_qty), $trigger['unit_price']), $promotion->discount_value), 100)
                    : DecimalMath::multiply($sets, $promotion->discount_value);
                $discount = DecimalMath::add($discount, $amount);
            } elseif ($promotion->promo_type === 'bundle_price') {
                $normal = DecimalMath::multiply(DecimalMath::multiply($sets, $promotion->min_qty), $trigger['unit_price']);
                $discount = DecimalMath::add($discount, $positive(DecimalMath::subtract($normal, DecimalMath::multiply($sets, $promotion->bundle_price))));
            } elseif ($promotion->promo_type === 'free_item') {
                $gift = $lines->firstWhere('product_id', (int) $promotion->free_product_id);
                if ($gift) {
                    $freeQty = $min($gift['qty'], DecimalMath::multiply($sets, $promotion->free_qty));
                    $discount = DecimalMath::add($discount, DecimalMath::multiply($freeQty, $gift['unit_price']));
                }
            }
        }
        $giftValue = '0';
        foreach ($billPromotionList->where('promo_type', 'bill_free_item') as $promotion) {
            $gift = $lines->firstWhere('product_id', (int) $promotion->free_product_id);
            if ($gift) {
                $giftValue = DecimalMath::add($giftValue, DecimalMath::multiply($min($gift['qty'], $promotion->free_qty), $gift['unit_price']));
            }
        }
        $paid = DecimalMath::subtract(DecimalMath::subtract(DecimalMath::sum($lines->map(fn ($line) => DecimalMath::multiply($line['qty'], $line['unit_price']))), $giftValue), $discount);
        foreach ($billPromotionList as $promotion) {
            if ($promotion->promo_type === 'bill_addon') {
                $addon = $lines->firstWhere('product_id', (int) $promotion->product_id);
                if (! $addon) {
                    continue;
                }
                $addonValue = DecimalMath::multiply($addon['qty'], $addon['unit_price']);
                if (DecimalMath::compare(DecimalMath::subtract($paid, $addonValue), $promotion->min_amount) < 0) {
                    continue;
                }
                $qty = $min($addon['qty'], $promotion->free_qty ?? 1);
                $discount = DecimalMath::add($discount, $positive(DecimalMath::multiply($qty, DecimalMath::subtract($addon['unit_price'], $promotion->bundle_price))));

                continue;
            }
            if (DecimalMath::compare($paid, $promotion->min_amount) < 0) {
                continue;
            }
            if ($promotion->promo_type === 'bill_discount') {
                $amount = $promotion->discount_type === 'percent'
                    ? DecimalMath::divide(DecimalMath::multiply($paid, $promotion->discount_value), 100)
                    : $promotion->discount_value;
                if ($promotion->max_discount !== null) {
                    $amount = $min($amount, $promotion->max_discount);
                }
                $discount = DecimalMath::add($discount, $min($paid, $amount));
            } else {
                $gift = $lines->firstWhere('product_id', (int) $promotion->free_product_id);
                if ($gift) {
                    $discount = DecimalMath::add($discount, DecimalMath::multiply($min($gift['qty'], $promotion->free_qty), $gift['unit_price']));
                }
            }
        }

        return (float) DecimalMath::round($discount, 2);
    }
}
