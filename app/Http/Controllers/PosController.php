<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\DiscountCard;
use App\Models\Document;
use App\Models\FlashSaleItem;
use App\Models\Member;
use App\Models\PosPayment;
use App\Models\PosReceipt;
use App\Models\PosReceiptItem;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\QrPaymentConfig;
use App\Models\QtyPromotion;
use App\Models\Salesman;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Services\Accounting\GlPostingService;
use App\Services\Sales\CashSaleService;
use App\Services\Sales\MemberPointService;
use App\Services\Sales\PosPaymentValidator;
use App\Services\Sales\PosPricingGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class PosController extends Controller
{
    public function index(MemberPointService $points): View
    {
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);
        $categories = ProductCategory::orderBy('name_th')->get(['id', 'code', 'name_th']);
        $cashiers = Salesman::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $defaultBranchId = $branches->first()?->id;
        $qrConfig = QrPaymentConfig::where('is_active', true)->with('bankAccount')->first();
        $pointValueBaht = $points->pointValueBaht();

        // แคชเชียร์เท่านั้นที่เปิดกะ/คิดเงินได้ - ผจก.สาขา/ผู้บริหารเปิดดูได้อย่างเดียว
        $authUser = auth()->user();
        $canSell = (bool) $authUser?->hasPermission('pos.sell');
        $canVoid = (bool) $authUser?->hasPermission('pos.void');

        // บังคับใช้ตัวเอง: ล็อกสาขา+คนขายเป็นของ user ที่ login (แคชเชียร์เลือกเองไม่ได้)
        $authUser?->loadMissing(['branch', 'salesman']);
        $lockedBranch = $authUser?->branch;           // สาขาที่ user สังกัด (null = ยังไม่กำหนด)
        $lockedCashier = $authUser?->salesman;         // รหัสพนักงานขายของ user
        if ($lockedBranch) {
            $defaultBranchId = $lockedBranch->id;
        }

        // ข้อมูลออกใบกำกับภาษีอย่างย่อ (มาตรา 86/6): ชื่อ+เลขภาษีผู้ขาย + อัตรา VAT
        $company = [
            'name' => AppSetting::company('name_th'),
            'tax_id' => AppSetting::company('tax_id'),
            'address' => AppSetting::company('address'),
            'phone' => AppSetting::company('phone'),
        ];
        $vatRate = (float) (DB::table('vat_rates')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')->value('rate_percent') ?? 7.0);

        // NOTE: หน้า POS บนเว็บใช้ Alpine ('pos.index'); ตัวแคชเชียร์จริงคือแอป desktop (Tauri+Vue) ใน pos-desktop/.
        return view('pos.index', compact('branches', 'categories', 'cashiers', 'defaultBranchId', 'qrConfig', 'pointValueBaht', 'canSell', 'canVoid', 'company', 'vatRate', 'lockedBranch', 'lockedCashier'));
    }

    // สาขา+คนขายที่บังคับใช้สำหรับ user ที่ login (ถ้ากำหนดไว้) ใช้ override ค่าที่ client ส่งมา
    private function enforcedBranchId(?int $requested): int
    {
        $device = request()->attributes->get('pos_device');
        if ($device) {
            return (int) ($device->branch_id ?: auth()->user()?->branch_id ?: $requested);
        }

        return (int) (auth()->user()?->branch_id ?: $requested);
    }

    private function enforcedCashierId(?int $requested): ?int
    {
        if (request()->attributes->get('pos_device')) {
            if (! $requested) {
                return null;
            }

            $cashier = Salesman::whereKey($requested)->where('is_active', true)->first();
            if (! $cashier) {
                return null;
            }

            $branchId = $this->enforcedBranchId(null);
            if ($cashier->branch_id && $branchId && (int) $cashier->branch_id !== (int) $branchId) {
                return null;
            }

            return (int) $cashier->id;
        }

        return auth()->user()?->salesman_id ?: $requested;
    }

    // Active buy-N campaigns (ซื้อครบแถม/ลด) for this branch; the POS cart
    // auto-adds gift lines and set discounts from this list.
    public function promotions(Request $request): JsonResponse
    {
        $branchId = (int) $request->query('branch_id', 0);

        $promotions = QtyPromotion::with('freeProduct:id,sku_code,name_th')
            ->runningToday()
            ->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get(['id', 'code', 'name', 'promo_type', 'product_id', 'min_qty',
                'free_product_id', 'free_qty', 'discount_type', 'discount_value']);

        return response()->json($promotions);
    }

    // Member lookup for attaching a member to the bill (สะสม/แลกแต้ม)
    public function members(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $members = Member::where('is_active', true)
            ->where(fn ($w) => $w
                ->where('member_code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
                ->orWhere('phone', 'ilike', "%{$q}%")
            )
            ->orderBy('member_code')
            ->limit(20)
            ->get(['id', 'member_code', 'name', 'phone', 'points']);

        return response()->json($members);
    }

    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category_id');
        $branchId = (int) $request->query('branch_id', 0);
        $exact = $request->boolean('exact');
        $all = $request->boolean('all'); // POS desktop ดึงแคตตาล็อกทั้งหมดเก็บ offline

        // ตารางราคาแบบชั้นซ้อน: ตารางสาขา (override) -> ตารางหลัก (default) -> default_price
        // สาขาที่ตารางตัวเองมีสินค้าไม่ครบ (ดอนกลาง/วาริน/ปลาดุก) จะได้ราคาจากตารางหลัก
        // ไม่ตกไป 1.00 บาท. ป้ายเครื่องชั่ง = ราคาขายปลีกตารางนี้เช่นกัน.
        $branch = $branchId ? Branch::find($branchId) : null;
        $defaultTableId = PriceTable::where('is_default', true)->value('id');
        // ราคาปกติกลางใช้ร่วมกันทุก POS ทุกสาขา ราคาที่ต่างตามสาขาต้องทำผ่าน
        // โปรโมชั่น/นาทีทองที่มีช่วงเวลาเท่านั้น เพื่อไม่ให้ราคาปกติแต่ละสาขาสับสนกัน.
        $branchTableId = null;
        $priceTableIds = array_values(array_filter([$defaultTableId]));

        $products = Product::where('is_active', true)
            ->with(['barcodes' => fn ($query) => $query->where('is_active', true)->with('unit')])
            ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId))
            ->when($q !== '' && $exact, fn ($query) => $query->where(fn ($w) => $w
                ->where('sku_code', $q)
                ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery->where('is_active', true)->where('barcode', $q))
            ))
            ->when($q !== '' && ! $exact, fn ($query) => $query->where(fn ($w) => $w
                ->where('sku_code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
                ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery->where('is_active', true)->where('barcode', 'ilike', "%{$q}%"))
            ))
            ->orderBy('name_th')
            ->when(! $all, fn ($query) => $query->limit(100))
            ->get(['id', 'sku_code', 'name_th', 'default_price', 'product_category_id']);

        $priceRows = $priceTableIds === []
            ? collect()
            : ProductPrice::whereIn('price_table_id', $priceTableIds)
                ->whereIn('product_id', $products->pluck('id'))
                ->where('is_active', true)
                ->get(['product_id', 'price_table_id', 'unit_id', 'price'])
                ->groupBy('product_id');

        // ตัวคูณหน่วย (id -> qty_per_base_unit) ใช้เลือก "ราคาหน่วยฐาน" เมื่อไม่มีราคา unit_id=null
        $unitFactors = ProductUnit::pluck('qty_per_base_unit', 'id');

        $products = $products->map(function ($p) use ($priceRows, $q, $exact, $branchTableId, $defaultTableId, $unitFactors) {
            $matchedBarcode = $exact && $q !== ''
                ? $p->barcodes->first(fn ($barcode) => $barcode->barcode === $q)
                : null;
            $scalePlu = $p->barcodes->first(fn ($barcode) => $this->isScalePlu($barcode->barcode))?->barcode
                ?? ($this->isScalePlu($p->sku_code) ? $p->sku_code : null);

            $p->scale_plu = $scalePlu;
            $p->is_scale = (bool) ($scalePlu || $this->productHasScaleName($p->name_th));

            $rows = $priceRows->get($p->id, collect());

            // ราคาจากตารางที่ระบุ: ยิงบาร์โค้ดมีหน่วย -> ราคาหน่วยนั้น, ไม่งั้น -> ราคาฐาน (unit_id null)
            $priceFromTable = function ($tableId) use ($rows, $matchedBarcode, $unitFactors) {
                if (! $tableId) {
                    return null;
                }
                $forTable = $rows->where('price_table_id', (int) $tableId);
                if ($forTable->isEmpty()) {
                    return null;
                }
                if ($matchedBarcode) {
                    $unitPrice = $forTable->firstWhere('unit_id', $matchedBarcode->unit_id);
                    if ($unitPrice) {
                        return ['price' => (float) $unitPrice->price, 'source' => 'unit_price_table'];
                    }
                }
                $basePrice = $forTable->firstWhere('unit_id', null);
                if ($basePrice) {
                    return ['price' => (float) $basePrice->price, 'source' => 'price_table'];
                }
                // ไม่มีราคาฐาน (unit_id null) -> ใช้ราคาหน่วยเล็กสุด (หน่วยฐาน เช่น สินค้าชั่ง = กก.)
                $smallest = $forTable->sortBy(fn ($r) => (float) ($unitFactors[$r->unit_id] ?? 1))->first();

                return $smallest ? ['price' => (float) $smallest->price, 'source' => 'unit_price_table'] : null;
            };

            if ($matchedBarcode && $matchedBarcode->price !== null && (float) $matchedBarcode->price > 0) {
                $p->pos_price = (float) $matchedBarcode->price;
                $p->price_source = 'barcode';
            } else {
                // ตารางสาขา (override) ก่อน แล้ว fallback ตารางหลัก แล้วค่อย default_price
                $hit = $priceFromTable($branchTableId) ?? $priceFromTable($defaultTableId);
                if ($hit) {
                    $p->pos_price = $hit['price'];
                    $p->price_source = $hit['source'];
                } else {
                    $p->pos_price = (float) $p->default_price;
                    $p->price_source = 'default';
                }
            }

            if ($matchedBarcode) {
                $p->matched_barcode = [
                    'barcode' => $matchedBarcode->barcode,
                    'unit_id' => $matchedBarcode->unit_id,
                    'unit_name' => $matchedBarcode->unit?->cleanName(),
                    'unit_factor' => (float) $matchedBarcode->unit_factor,
                ];
            }

            $p->normal_price = (float) $p->pos_price;

            return $p;
        });

        // ราคาลดตามช่วงวันที่: ใช้ก่อนราคาปกติ เฉพาะโปรโมชันรายสินค้าที่ไม่ต้องรอ
        // เงื่อนไขยอดบิล/จำนวนหลายชิ้น (เงื่อนไขเหล่านั้นคำนวณในตะกร้าแยกต่างหาก).
        $today = now()->toDateString();
        $discountsByProduct = Promotion::where('is_active', true)
            ->whereIn('product_id', $products->pluck('id'))
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $today))
            ->where(fn ($w) => $w->whereNull('min_qty')->orWhere('min_qty', '<=', 1))
            ->whereNull('min_amount')
            ->get()
            ->groupBy('product_id');

        $products = $products->map(function ($p) use ($discountsByProduct) {
            $base = (float) $p->pos_price;
            $best = $discountsByProduct->get($p->id, collect())
                ->map(function ($promotion) use ($base) {
                    if ((float) $promotion->discount_amount > 0) {
                        return max(0, $base - (float) $promotion->discount_amount);
                    }
                    if ((float) $promotion->discount_percent > 0) {
                        return max(0, round($base * (1 - ((float) $promotion->discount_percent / 100)), 2));
                    }

                    return $base;
                })->min();

            if ($best !== null && $best < $base) {
                $p->original_price = $base;
                $p->pos_price = $best;
                $p->is_promotion = true;
                $p->price_source = 'promotion';
            } else {
                $p->is_promotion = false;
            }

            return $p;
        });

        // ราคานาทีทอง: override with the active flash-sale price, if any, for
        // this branch and current date/time/day-of-week window.
        $now = now();
        $flashItemsByProduct = FlashSaleItem::with('flashSale')
            ->whereIn('product_id', $products->pluck('id'))
            ->whereHas('flashSale', function ($query) use ($branchId, $now) {
                $query->where('is_active', true)
                    ->where('starts_date', '<=', $now->toDateString())
                    ->where(fn ($w) => $w->whereNull('ends_date')->orWhere('ends_date', '>=', $now->toDateString()))
                    ->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId));
            })
            ->get()
            ->filter(fn ($item) => $item->flashSale->isRunningAt($now))
            ->groupBy('product_id')
            ->map(fn ($items) => $items->sortBy('flash_price')->first());

        $products = $products->map(function ($p) use ($flashItemsByProduct) {
            $flashItem = $flashItemsByProduct->get($p->id);
            if ($flashItem && (float) $flashItem->flash_price < (float) $p->pos_price) {
                $p->original_price ??= $p->pos_price;
                $p->pos_price = (float) $flashItem->flash_price;
                $p->is_flash_sale = true;
                $p->price_source = 'flash_sale';
            } else {
                $p->is_flash_sale = false;
            }

            return $p;
        });

        // สต๊อกคงเหลือ "ของสาขานี้" (คลังใครคลังมัน) - ดึงจากคลังเริ่มต้นของสาขา
        $locationId = $branchId ? Branch::whereKey($branchId)->value('default_warehouse_location_id') : null;
        if ($locationId) {
            $stockByProduct = StockBalance::where('warehouse_location_id', $locationId)
                ->whereIn('product_id', $products->pluck('id'))
                ->pluck('on_hand_qty', 'product_id');
            $products->each(function ($p) use ($stockByProduct) {
                $p->stock_qty = (float) ($stockByProduct[$p->id] ?? 0);
            });
        } else {
            $products->each(fn ($p) => $p->stock_qty = null);
        }

        return response()->json($products);
    }

    private function isScalePlu(?string $value): bool
    {
        return is_string($value) && preg_match('/^80[01][0-9]{3}$/', $value) === 1;
    }

    private function productHasScaleName(?string $name): bool
    {
        return is_string($name) && preg_match('/\x{0E0A}\x{0E31}\x{0E48}\x{0E07}|\x{0E0B}\x{0E31}\x{0E48}\x{0E07}/u', $name) === 1;
    }

    public function activeShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'cashier_id' => ['nullable', 'integer', 'exists:salesmen,id'],
        ]);

        $shift = $this->findOpenShift((int) $data['branch_id'], isset($data['cashier_id']) ? (int) $data['cashier_id'] : null);

        return response()->json(['shift' => $shift ? $this->shiftPayload($shift) : null]);
    }

    public function openShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'cashier_id' => ['required', 'integer', 'exists:salesmen,id'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:500'],
        ]);

        // บังคับใช้ตัวเอง: เปิดกะในชื่อ+สาขาของ user ที่ login เท่านั้น
        $data['branch_id'] = $this->enforcedBranchId((int) $data['branch_id']);
        $data['cashier_id'] = $this->enforcedCashierId((int) $data['cashier_id']);
        if (! $data['cashier_id']) {
            return response()->json(['success' => false, 'message' => 'บัญชีนี้ยังไม่ได้กำหนดรหัสพนักงานขาย ติดต่อผู้ดูแลระบบ'], 422);
        }

        $existing = $this->findOpenShift((int) $data['branch_id'], (int) $data['cashier_id']);
        if ($existing) {
            return response()->json(['success' => true, 'shift' => $this->shiftPayload($existing), 'message' => 'มีกะเปิดอยู่แล้ว']);
        }

        $terminal = $this->terminalForBranch((int) $data['branch_id']);

        $shift = PosShift::create([
            'branch_id' => $data['branch_id'],
            'pos_terminal_id' => $terminal->id,
            'cashier_id' => $data['cashier_id'],
            'shift_no' => $this->nextShiftNo((int) $data['branch_id']),
            'opened_at' => now(),
            'opening_cash' => $data['opening_cash'],
            'expected_cash' => $data['opening_cash'],
            'status' => 'open',
            'opening_note' => $data['opening_note'] ?? null,
        ]);

        return response()->json(['success' => true, 'shift' => $this->shiftPayload($shift)]);
    }

    public function closeShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:pos_shifts,id'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = PosShift::where('id', $data['shift_id'])->where('status', 'open')->first();
        if (! $shift) {
            return response()->json(['success' => false, 'message' => 'ไม่พบกะที่เปิดอยู่'], 422);
        }

        $totals = $this->calculateShiftTotals($shift);
        $expectedCash = round((float) $shift->opening_cash + $totals['cash'], 2);
        $countedCash = round((float) $data['counted_cash'], 2);

        $shift->update([
            'closed_at' => now(),
            'cash_sales' => $totals['cash'],
            'transfer_sales' => $totals['transfer'],
            'card_sales' => $totals['credit_card'],
            'cheque_sales' => $totals['cheque'],
            'expected_cash' => $expectedCash,
            'counted_cash' => $countedCash,
            'cash_difference' => round($countedCash - $expectedCash, 2),
            'receipt_count' => $totals['receipt_count'],
            'status' => 'closed',
            'closing_note' => $data['closing_note'] ?? null,
        ]);

        return response()->json(['success' => true, 'shift' => $this->shiftPayload($shift->fresh())]);
    }

    public function checkout(
        Request $request,
        CashSaleService $service,
        MemberPointService $points,
        PosPaymentValidator $paymentValidator,
        PosPricingGuard $pricingGuard,
    ): JsonResponse {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'shift_id' => ['required', 'integer', 'exists:pos_shifts,id'],
            'cashier_id' => ['required', 'integer', 'exists:salesmen,id'],
            'redeem_points' => ['nullable', 'numeric', 'min:0'],
            'method' => ['required', 'string', 'in:cash,transfer,credit_card,cheque,mixed'],
            'payment_ref' => ['nullable', 'string', 'max:80'],
            'payment_confirmed' => ['nullable', 'boolean'],
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'change_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'transfer_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'manual_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_card_code' => ['nullable', 'string', 'max:30'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_mode' => ['nullable', 'string', 'in:included,excluded'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.barcode' => ['nullable', 'string', 'max:50'],
            'allow_negative_stock' => ['nullable', 'boolean'],
        ]);

        // บังคับใช้ตัวเอง: ตัดสต๊อก+ลงคนขายตามสาขา/รหัสพนักงานของ user ที่ login
        // (client ส่งค่าอะไรมาก็ override) - สต๊อกจึงตัดจากคลังสาขาตัวเองเสมอ
        $data['branch_id'] = $this->enforcedBranchId((int) $data['branch_id']);
        $data['cashier_id'] = $this->enforcedCashierId((int) $data['cashier_id']);
        if (! $data['cashier_id']) {
            return response()->json(['success' => false, 'message' => 'บัญชีนี้ยังไม่ได้กำหนดรหัสพนักงานขาย ติดต่อผู้ดูแลระบบ'], 422);
        }

        try {
            // ป้ายชั่งฝังราคารวมไว้ในบาร์โค้ด ต้องถอดเป็นน้ำหนัก+ราคาต่อหน่วยฝั่ง server ก่อนตรวจราคา
            $data['items'] = $pricingGuard->resolveScaleLines($data['items'], (int) $data['branch_id']);
            $pricingGuard->validate($data, auth()->user());
            $data['items'] = $pricingGuard->normalizeItems($data['items']);
            $paymentValidator->validate($data);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $shift = PosShift::where('id', $data['shift_id'])
            ->where('branch_id', $data['branch_id'])
            ->where('cashier_id', $data['cashier_id'])
            ->where('status', 'open')
            ->first();
        if (! $shift) {
            return response()->json(['success' => false, 'message' => 'กรุณาเปิดกะแคชเชียร์ก่อนขาย'], 422);
        }

        $discountCard = null;
        if (! empty($data['discount_card_code'])) {
            $discountCard = DiscountCard::where('card_code', $data['discount_card_code'])->first();
            if (! $discountCard || ! $discountCard->isValidAt(now())) {
                return response()->json(['success' => false, 'message' => 'บัตรส่วนลดหมดอายุหรือใช้ครบจำนวนแล้ว กรุณานำบัตรออกแล้วลองใหม่'], 422);
            }
        }

        $member = null;
        $redeemPoints = (float) ($data['redeem_points'] ?? 0);
        if (! empty($data['member_id'])) {
            $member = Member::find($data['member_id']);
        }
        if ($redeemPoints > 0) {
            if (! $member) {
                return response()->json(['success' => false, 'message' => 'ต้องเลือกสมาชิกก่อนจึงจะแลกแต้มได้'], 422);
            }
            if ((float) $member->points < $redeemPoints) {
                return response()->json(['success' => false, 'message' => 'แต้มสะสมไม่พอ (คงเหลือ '.number_format((float) $member->points, 2).' แต้ม)'], 422);
            }
            if ($points->pointValueBaht() <= 0) {
                return response()->json(['success' => false, 'message' => 'ระบบยังไม่เปิดให้แลกแต้มเป็นส่วนลด'], 422);
            }
        }

        $methodText = match ($data['method']) {
            'cash' => 'เงินสด',
            'transfer' => 'โอนเงิน/QR',
            'credit_card' => 'บัตรเครดิต',
            'mixed' => 'เงินสด+โอน',
            default => 'เช็ค',
        };
        $remarkParts = ['POS: '.$methodText];
        if ($data['method'] === 'mixed') {
            $remarkParts[] = 'เงินสด: '.number_format((float) ($data['cash_amount'] ?? 0), 2)
                .' | โอน: '.number_format((float) ($data['transfer_amount'] ?? 0), 2);
        }
        if (in_array($data['method'], ['transfer', 'mixed'], true)) {
            $remarkParts[] = 'ตรวจเงินเข้าแล้ว';
        }
        if (! empty($data['payment_ref'])) {
            $remarkParts[] = 'อ้างอิง: '.$data['payment_ref'];
        }
        if (! empty($data['discount_amount'])) {
            $remarkParts[] = 'ส่วนลด: '.number_format((float) $data['discount_amount'], 2);
        }
        if ($discountCard) {
            $remarkParts[] = 'บัตรส่วนลด: '.$discountCard->card_code;
        }
        if ($member) {
            $remarkParts[] = 'สมาชิก: '.$member->member_code;
        }
        if ($redeemPoints > 0) {
            $remarkParts[] = 'แลกแต้ม: '.number_format($redeemPoints, 2);
        }
        if (! empty($data['vat_amount'])) {
            $vatMode = ($data['vat_mode'] ?? 'included') === 'excluded' ? 'แยก VAT' : 'รวม VAT';
            $remarkParts[] = $vatMode.': '.number_format((float) $data['vat_amount'], 2);
        }

        try {
            [$document, $receipt, $earnedPoints] = DB::transaction(function () use (
                $service,
                $data,
                $remarkParts,
                $shift,
                $discountCard,
                $member,
                $points,
                $redeemPoints,
            ) {
                $document = $service->create([
                    'branch_id' => $data['branch_id'],
                    'customer_id' => $data['customer_id'] ?? null,
                    'remark' => implode(' | ', $remarkParts),
                    'items' => $data['items'],
                    'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false),
                ]);

                $receipt = $this->recordPosReceipt($shift, $this->nextPosReceiptNo($shift), $data, $document->id);

                if ($discountCard) {
                    $lockedCard = DiscountCard::whereKey($discountCard->id)->lockForUpdate()->first();
                    if (! $lockedCard || ! $lockedCard->isValidAt(now())) {
                        throw new RuntimeException('บัตรส่วนลดหมดอายุหรือใช้ครบจำนวนแล้ว กรุณานำบัตรออกแล้วลองใหม่');
                    }
                    $lockedCard->increment('used_count');
                }

                $earnedPoints = $member ? $points->settle($member, $document, $redeemPoints) : 0.0;

                return [$document, $receipt, $earnedPoints];
            });

            return response()->json([
                'success' => true,
                'doc_number' => $receipt->receipt_no,
                'receipt_id' => $receipt->id,
                'receipt_no' => $receipt->receipt_no,
                'source_doc_number' => $document->doc_number,
                'total_amount' => (float) $document->total_amount,
                'earned_points' => $earnedPoints,
                'member_points' => $member ? (float) $member->fresh()->points : null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function voidReceipt(Request $request, PosReceipt $receipt, GlPostingService $glPosting): JsonResponse
    {
        if (! auth()->user()?->hasPermission('pos.void')) {
            return response()->json(['success' => false, 'message' => 'เฉพาะผู้จัดการหรือ IT เท่านั้นที่ยกเลิกบิลได้'], 403);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'shift_id' => ['nullable', 'integer', 'exists:pos_shifts,id'],
        ]);

        if ($receipt->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'บิลนี้ถูกยกเลิกไปแล้วหรือไม่อยู่ในสถานะที่ยกเลิกได้'], 422);
        }

        $receipt->loadMissing(['shift', 'terminal']);
        if (! $receipt->shift || $receipt->shift->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'ยกเลิกบิลได้เฉพาะบิลในกะที่ยังเปิดอยู่ หากปิดกะแล้วให้ใช้รับคืนสินค้า'], 422);
        }
        if (! empty($data['shift_id']) && (int) $receipt->shift->id !== (int) $data['shift_id']) {
            return response()->json(['success' => false, 'message' => 'ยกเลิกได้เฉพาะบิลในกะปัจจุบันของเครื่องนี้'], 422);
        }
        if ($receipt->receipt_date?->toDateString() !== now()->toDateString()) {
            return response()->json(['success' => false, 'message' => 'ยกเลิกบิลข้ามวันไม่ได้ ให้ใช้รับคืนสินค้าแทน'], 422);
        }
        $hasReturn = DB::table('pos_receipt_returns')
            ->where('pos_receipt_id', $receipt->id)
            ->where('status', 'completed')
            ->exists();
        if ($hasReturn) {
            return response()->json(['success' => false, 'message' => 'บิลนี้มีการรับคืนสินค้าแล้ว ยกเลิกทั้งบิลไม่ได้'], 422);
        }

        try {
            DB::transaction(function () use ($receipt, $data, $glPosting) {
                $receipt = PosReceipt::with(['terminal', 'shift'])
                    ->whereKey($receipt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($receipt->status !== 'completed') {
                    throw new RuntimeException('บิลนี้ถูกยกเลิกไปแล้ว');
                }
                if (! $receipt->shift || $receipt->shift->status !== 'open') {
                    throw new RuntimeException('ยกเลิกบิลได้เฉพาะบิลในกะที่ยังเปิดอยู่ หากปิดกะแล้วให้ใช้รับคืนสินค้า');
                }
                if (! empty($data['shift_id']) && (int) $receipt->shift->id !== (int) $data['shift_id']) {
                    throw new RuntimeException('ยกเลิกได้เฉพาะบิลในกะปัจจุบันของเครื่องนี้');
                }
                if ($receipt->receipt_date?->toDateString() !== now()->toDateString()) {
                    throw new RuntimeException('ยกเลิกบิลข้ามวันไม่ได้ ให้ใช้รับคืนสินค้าแทน');
                }
                $hasReturn = DB::table('pos_receipt_returns')
                    ->where('pos_receipt_id', $receipt->id)
                    ->where('status', 'completed')
                    ->exists();
                if ($hasReturn) {
                    throw new RuntimeException('บิลนี้มีการรับคืนสินค้าแล้ว ยกเลิกทั้งบิลไม่ได้');
                }

                $oldValues = [
                    'status' => $receipt->status,
                    'net_sales' => (float) $receipt->net_sales,
                    'receipt_no' => $receipt->receipt_no,
                    'document_id' => $receipt->document_id,
                ];

                $receipt->update([
                    'status' => 'void',
                    'voided_at' => now(),
                    'voided_by' => auth()->id(),
                    'void_reason' => $data['reason'],
                ]);

                if ($receipt->document_id) {
                    $document = Document::whereKey($receipt->document_id)->lockForUpdate()->first();
                    if ($document && $document->status !== 'cancelled') {
                        $document->forceFill([
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                            'remark' => trim(($document->remark ? $document->remark.' | ' : '').'POS void: '.$data['reason']),
                        ])->save();

                        $this->restoreStockForVoidedDocument($document);
                        $glPosting->reverseDocument($document, 'POS void '.$receipt->receipt_no.' - '.$data['reason']);
                    }
                }

                DB::table('audit_logs')->insert([
                    'user_id' => auth()->id(),
                    'branch_id' => $receipt->terminal?->branch_id ?? $receipt->shift?->branch_id,
                    'action' => 'void',
                    'table_name' => 'pos_receipts',
                    'record_id' => $receipt->id,
                    'old_values' => json_encode($oldValues, JSON_UNESCAPED_UNICODE),
                    'new_values' => json_encode([
                        'status' => 'void',
                        'voided_at' => now()->toDateTimeString(),
                        'voided_by' => auth()->id(),
                        'void_reason' => $data['reason'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);

                if ($receipt->shift) {
                    $totals = $this->calculateShiftTotals($receipt->shift);
                    $receipt->shift->update([
                        'cash_sales' => $totals['cash'],
                        'transfer_sales' => $totals['transfer'],
                        'card_sales' => $totals['credit_card'],
                        'cheque_sales' => $totals['cheque'],
                        'expected_cash' => round((float) $receipt->shift->opening_cash + $totals['cash'], 2),
                        'receipt_count' => $totals['receipt_count'],
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'ยกเลิกบิลเรียบร้อย',
                'receipt_no' => $receipt->receipt_no,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function findOpenShift(int $branchId, ?int $cashierId = null): ?PosShift
    {
        return PosShift::with(['branch', 'terminal', 'cashier'])
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->when($cashierId, fn ($query) => $query->where('cashier_id', $cashierId))
            ->orderByDesc('opened_at')
            ->first();
    }

    private function terminalForBranch(int $branchId): PosTerminal
    {
        $branch = Branch::findOrFail($branchId);

        return PosTerminal::firstOrCreate(
            ['code' => 'WEB-'.$branch->code],
            ['branch_id' => $branchId, 'name' => 'JET POS '.$branch->code]
        );
    }

    private function nextShiftNo(int $branchId): string
    {
        $branchCode = Branch::find($branchId)?->code ?? sprintf('%04d', $branchId);

        return 'SHIFT-'.$branchCode.'-'.now()->format('Ymd-His');
    }

    private function nextPosReceiptNo(PosShift $shift): string
    {
        $rawBranchCode = $shift->branch?->code ?? Branch::find($shift->branch_id)?->code ?? sprintf('%04d', $shift->branch_id);
        $branchCode = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $rawBranchCode) ?: sprintf('%04d', $shift->branch_id), 0, 8);
        $prefix = 'CS'.$branchCode.now()->format('Ymd');
        $lastReceiptNo = PosReceipt::where('pos_terminal_id', $shift->pos_terminal_id)
            ->where('receipt_no', 'like', $prefix.'%')
            ->orderByDesc('receipt_no')
            ->lockForUpdate()
            ->value('receipt_no');
        $sequence = $lastReceiptNo ? ((int) substr($lastReceiptNo, -3)) + 1 : 1;

        return $prefix.sprintf('%03d', $sequence);
    }

    private function recordPosReceipt(PosShift $shift, string $receiptNo, array $data, int $documentId): PosReceipt
    {
        $items = collect($data['items']);
        $gross = round($items->sum(fn ($item) => (float) $item['qty'] * (float) $item['unit_price']), 2);
        $discount = round((float) ($data['discount_amount'] ?? 0), 2);
        $vat = round((float) ($data['vat_amount'] ?? 0), 2);
        $net = round($gross, 2);

        $receipt = PosReceipt::create([
            'pos_terminal_id' => $shift->pos_terminal_id,
            'pos_shift_id' => $shift->id,
            'document_id' => $documentId,
            'receipt_no' => $receiptNo,
            'receipt_date' => now(),
            'cashier_id' => auth()->id(),
            'cashier_salesman_id' => $data['cashier_id'],
            'member_id' => $data['member_id'] ?? null,
            'gross_sales' => $gross,
            'discount_amount' => $discount,
            'vat_amount' => $vat,
            'net_sales' => $net,
            'status' => 'completed',
        ]);

        foreach ($items->values() as $index => $item) {
            $lineNet = round((float) $item['qty'] * (float) $item['unit_price'], 2);
            PosReceiptItem::create([
                'pos_receipt_id' => $receipt->id,
                'seq' => $index + 1,
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'unit_price' => $item['unit_price'],
                'discount_amount' => 0,
                'vat_amount' => 0,
                'net_amount' => $lineNet,
            ]);
        }

        if ($data['method'] === 'mixed') {
            // จ่ายผสม: แยก 2 แถวตามช่องทาง — ยอดกะ/รายงานรวมจาก pos_payments จึงเข้าช่องถูกเอง
            $cashPart = round((float) ($data['cash_amount'] ?? 0), 2);
            $transferPart = round($net - $cashPart, 2); // ผูกกับยอดบิลจริง กันเศษปัดไม่ลงตัว
            PosPayment::create([
                'pos_receipt_id' => $receipt->id,
                'method' => 'cash',
                'amount' => $cashPart,
                'cash_received' => $data['cash_received'] ?? $cashPart,
                'change_amount' => $data['change_amount'] ?? 0,
            ]);
            PosPayment::create([
                'pos_receipt_id' => $receipt->id,
                'method' => 'transfer',
                'amount' => $transferPart,
            ]);
        } else {
            PosPayment::create([
                'pos_receipt_id' => $receipt->id,
                'method' => $data['method'],
                'amount' => $net,
                'cash_received' => $data['method'] === 'cash' ? ($data['cash_received'] ?? $net) : null,
                'change_amount' => $data['method'] === 'cash' ? ($data['change_amount'] ?? 0) : null,
                'card_no' => $data['method'] === 'credit_card' ? ($data['payment_ref'] ?? null) : null,
                'cheque_no' => $data['method'] === 'cheque' ? ($data['payment_ref'] ?? null) : null,
            ]);
        }

        $totals = $this->calculateShiftTotals($shift);
        $shift->update([
            'cash_sales' => $totals['cash'],
            'transfer_sales' => $totals['transfer'],
            'card_sales' => $totals['credit_card'],
            'cheque_sales' => $totals['cheque'],
            'expected_cash' => round((float) $shift->opening_cash + $totals['cash'], 2),
            'receipt_count' => $totals['receipt_count'],
        ]);

        return $receipt;
    }

    private function restoreStockForVoidedDocument(Document $document): void
    {
        $stockDocument = StockDocument::with('items')->where('document_id', $document->id)->first();
        if (! $stockDocument) {
            return;
        }

        foreach ($stockDocument->items as $item) {
            $qty = (float) $item->qty;
            if ($qty <= 0) {
                continue;
            }

            $balance = StockBalance::firstOrCreate(
                [
                    'product_id' => $item->product_id,
                    'warehouse_location_id' => $item->warehouse_location_id,
                ],
                [
                    'on_hand_qty' => 0,
                    'reserved_qty' => 0,
                ]
            );
            $balance->increment('on_hand_qty', $qty);

            StockMovement::create([
                'product_id' => $item->product_id,
                'warehouse_location_id' => $item->warehouse_location_id,
                'document_id' => $document->id,
                'movement_type' => 'void_in',
                'qty' => $qty,
                'movement_date' => now()->toDateString(),
            ]);
        }
    }

    private function calculateShiftTotals(PosShift $shift): array
    {
        $payments = PosPayment::query()
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_payments.pos_receipt_id')
            ->where('pos_receipts.pos_shift_id', $shift->id)
            ->where('pos_receipts.status', 'completed')
            ->selectRaw('pos_payments.method, sum(pos_payments.amount) as total')
            ->groupBy('pos_payments.method')
            ->pluck('total', 'method');

        $returns = DB::table('pos_receipt_returns')
            ->where('pos_shift_id', $shift->id)
            ->where('status', 'completed')
            ->selectRaw('refund_method, sum(total_amount) as total')
            ->groupBy('refund_method')
            ->pluck('total', 'refund_method');

        return [
            'cash' => round((float) ($payments['cash'] ?? 0) - (float) ($returns['cash'] ?? 0), 2),
            'transfer' => round((float) ($payments['transfer'] ?? 0) - (float) ($returns['transfer'] ?? 0), 2),
            'credit_card' => round((float) ($payments['credit_card'] ?? 0), 2),
            'cheque' => round((float) ($payments['cheque'] ?? 0), 2),
            'receipt_count' => PosReceipt::where('pos_shift_id', $shift->id)->where('status', 'completed')->count(),
        ];
    }

    private function shiftPayload(PosShift $shift): array
    {
        $shift->loadMissing(['branch', 'cashier', 'terminal']);

        return [
            'id' => $shift->id,
            'shift_no' => $shift->shift_no,
            'status' => $shift->status,
            'branch_id' => $shift->branch_id,
            'branch_name' => $shift->branch?->name_th,
            'cashier_id' => $shift->cashier_id,
            'cashier_name' => $shift->cashier?->name,
            'opened_at' => $shift->opened_at?->format('Y-m-d H:i:s'),
            'closed_at' => $shift->closed_at?->format('Y-m-d H:i:s'),
            'opening_cash' => (float) $shift->opening_cash,
            'cash_sales' => (float) $shift->cash_sales,
            'transfer_sales' => (float) $shift->transfer_sales,
            'card_sales' => (float) $shift->card_sales,
            'cheque_sales' => (float) $shift->cheque_sales,
            'expected_cash' => (float) $shift->expected_cash,
            'counted_cash' => $shift->counted_cash !== null ? (float) $shift->counted_cash : null,
            'cash_difference' => $shift->cash_difference !== null ? (float) $shift->cash_difference : null,
            'receipt_count' => (int) $shift->receipt_count,
        ];
    }
}
