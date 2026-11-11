<?php

namespace App\Services\Pricing;

use App\Models\AuditLog;
use App\Models\PosPriceSchedule;
use App\Models\PriceChange;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\ScheduledPriceChange;
use App\Services\Sales\PosPriceScheduleService;
use App\Support\DecimalMath;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ScheduledPriceChangeService
{
    public function __construct(
        private readonly PosPriceScheduleService $posPriceScheduleService
    ) {}

    /**
     * Schedule a batch of price changes for one or multiple products.
     *
     * @param array<int, array{product_id: int, new_price: numeric, price_table_id?: ?int, unit_id?: ?int}> $items
     * @return array<int, ScheduledPriceChange>
     */
    public function schedule(array $items, string|CarbonInterface $effectiveAt, ?string $note = null, ?int $userId = null): array
    {
        if (empty($items)) {
            throw new InvalidArgumentException('ต้องระบุสินค้าอย่างน้อย 1 รายการ');
        }

        $effectiveCarbon = $effectiveAt instanceof CarbonInterface
            ? $effectiveAt->copy()
            : Carbon::parse($effectiveAt);

        if ($effectiveCarbon->isPast()) {
            throw new InvalidArgumentException('วันและเวลาที่มีผลต้องอยู่ในอนาคต');
        }

        $batchRef = 'SPC-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(4));

        return DB::transaction(function () use ($items, $effectiveCarbon, $batchRef, $note, $userId) {
            $created = [];

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $product = Product::findOrFail($productId);
                $newPrice = (float) ($item['new_price'] ?? 0);

                if ($newPrice <= 0) {
                    throw new InvalidArgumentException("ราคาใหม่ของสินค้า {$product->sku_code} ({$product->name_th}) ต้องมากกว่า 0");
                }

                $priceTableId = !empty($item['price_table_id']) ? (int) $item['price_table_id'] : null;
                $unitId = !empty($item['unit_id']) ? (int) $item['unit_id'] : null;
                if ($priceTableId === null && $unitId !== null) {
                    // ราคาหลัก (default_price) เป็นราคาของหน่วยฐานเท่านั้น ราคาหน่วยอื่นต้องระบุตารางราคา
                    throw new InvalidArgumentException("ราคาหลักของสินค้า {$product->sku_code} ตั้งได้เฉพาะหน่วยฐาน");
                }

                // สร้างช่วงราคาขาย POS ล่วงหน้า (เฉพาะราคาหลัก default_price หรือราคาขายปลีก)
                // เพื่อให้ POS ได้รับแถวราคาล่วงหน้าตอนซิงก์รอบ 5 นาที และขายได้ทันทีแม้เน็ตหลุด
                $posSchedule = null;
                if ($priceTableId === null) {
                    $posSchedule = $this->posPriceScheduleService->publish($product, [
                        'branch_id' => null, // ทุกสาขา
                        'unit_id' => $unitId,
                        'price' => $newPrice,
                        'effective_from' => $effectiveCarbon,
                        'effective_to' => null,
                        'note' => $note,
                    ], $userId);
                }

                $record = ScheduledPriceChange::create([
                    'product_id' => $product->id,
                    'price_table_id' => $priceTableId,
                    'unit_id' => $unitId,
                    'new_price' => $newPrice,
                    'old_price' => null,
                    'effective_at' => $effectiveCarbon,
                    'status' => 'pending',
                    'pos_price_schedule_id' => $posSchedule?->id,
                    'batch_ref' => $batchRef,
                    'note' => $note,
                    'created_by' => $userId,
                ]);

                $created[] = $record;
            }

            return $created;
        });
    }

    /**
     * Cancel a pending scheduled price change.
     */
    public function cancel(ScheduledPriceChange $change, ?int $userId = null): void
    {
        if ($change->status !== 'pending') {
            throw new DomainException('สามารถยกเลิกได้เฉพาะรายการที่อยู่ในสถานะรอมีผลเท่านั้น');
        }

        DB::transaction(function () use ($change) {
            $change->update(['status' => 'cancelled']);

            if ($change->pos_price_schedule_id) {
                $posSchedule = PosPriceSchedule::find($change->pos_price_schedule_id);
                if ($posSchedule && $posSchedule->status === 'published') {
                    $this->posPriceScheduleService->cancel($posSchedule);
                }
            }
        });
    }

    /**
     * Apply a single scheduled price change if due.
     */
    public function apply(ScheduledPriceChange $change): bool
    {
        if ($change->status !== 'pending') {
            return false;
        }

        if ($change->effective_at->isFuture()) {
            return false;
        }

        try {
            DB::transaction(function () use ($change) {
                /** @var ScheduledPriceChange|null $locked */
                $locked = ScheduledPriceChange::whereKey($change->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'pending') {
                    return;
                }

                /** @var Product|null $product */
                $product = Product::whereKey($locked->product_id)->lockForUpdate()->first();
                if (! $product) {
                    throw new RuntimeException("ไม่พบสินค้า ID {$locked->product_id}");
                }

                $oldPrice = null;

                if ($locked->price_table_id === null) {
                    // ปรับราคาหลัก default_price ของสินค้า
                    $oldPrice = $product->default_price;
                    $product->update([
                        'default_price' => $locked->new_price,
                    ]);

                    PriceChange::create([
                        'product_id' => $product->id,
                        'old_price' => $oldPrice,
                        'new_price' => $locked->new_price,
                        'effective_date' => now()->toDateString(),
                        'changed_by' => $locked->created_by,
                    ]);

                    $tableRowOld = $this->syncPosBasePriceRow($product, $locked->new_price);

                    AuditLog::create([
                        'user_id' => $locked->created_by,
                        'action' => 'price_updated',
                        'table_name' => 'products',
                        'record_id' => $product->id,
                        'old_values' => ['default_price' => (string) $oldPrice, 'price_table_row' => $tableRowOld],
                        'new_values' => ['default_price' => (string) $locked->new_price, 'price_table_row' => $tableRowOld === null ? null : (string) $locked->new_price],
                    ]);
                } else {
                    // ปรับราคาในตารางราคา product_prices
                    $identity = [
                        'product_id' => $product->id,
                        'price_table_id' => $locked->price_table_id,
                        'unit_id' => $locked->unit_id,
                    ];
                    $oldPrice = ProductPrice::where($identity)->value('price');
                    ProductPrice::updateOrCreate(
                        $identity,
                        [
                            'price' => $locked->new_price,
                            'is_active' => true,
                        ]
                    );

                    if ($oldPrice === null || DecimalMath::compare($oldPrice, $locked->new_price) !== 0) {
                        PriceChange::create([
                            'product_id' => $product->id,
                            'old_price' => $oldPrice,
                            'new_price' => $locked->new_price,
                            'effective_date' => now()->toDateString(),
                            'changed_by' => $locked->created_by,
                        ]);
                    }

                    AuditLog::create([
                        'user_id' => $locked->created_by,
                        'action' => 'price_updated',
                        'table_name' => 'product_prices',
                        'record_id' => $product->id,
                        'old_values' => ['price' => (string) $oldPrice],
                        'new_values' => ['price' => (string) $locked->new_price],
                    ]);
                }

                // ปิดแถว PosPriceSchedule ที่ผูกไว้หลังราคาหลักมีผลแล้ว
                // เพื่อไม่ให้ PosPriceScheduleService::active() เลือกแถวนี้ค้างทับราคาที่อาจถูกแก้ภายหลัง
                if ($locked->pos_price_schedule_id) {
                    $posSchedule = PosPriceSchedule::find($locked->pos_price_schedule_id);
                    if ($posSchedule && $posSchedule->status === 'published') {
                        $this->posPriceScheduleService->cancel($posSchedule);
                    }
                }

                $locked->update([
                    'old_price' => $oldPrice,
                    'status' => 'applied',
                    'applied_at' => now(),
                    'error' => null,
                ]);
            });

            return true;
        } catch (Throwable $e) {
            ScheduledPriceChange::whereKey($change->id)->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return false;
        }
    }

    /**
     * เครื่อง POS และตัวตรวจราคาตอนรับบิลอ่านราคาจากตารางราคาหลักก่อน default_price
     * (PosController::products และ PosPricingGuard::basePrices: แถวหน่วยฐาน -> ไม่มีก็แถวหน่วยเล็กสุด)
     * ถ้าแก้แค่ default_price แล้วปิดช่วงราคา POS ราคาที่เครื่องขายจะเด้งกลับเป็นราคาเดิมในตาราง
     * จึงแก้แถวเดียวกับที่ POS ใช้เป็นราคาฐานด้วย แถวหน่วยอื่น (แพ็ค/ลัง) ไม่ถูกแตะ
     *
     * @return string|null ราคาเดิมของแถวที่แก้ หรือ null ถ้าสินค้าไม่มีแถวในตารางราคาหลัก
     */
    private function syncPosBasePriceRow(Product $product, mixed $newPrice): ?string
    {
        $tableId = PriceTable::where('is_default', true)->value('id');
        if (! $tableId) {
            return null;
        }
        $rows = ProductPrice::where('product_id', $product->id)->where('price_table_id', $tableId)
            ->where('is_active', true)->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $unitFactors = ProductUnit::pluck('qty_per_base_unit', 'id');
        $row = $rows->firstWhere('unit_id', null)
            ?? $rows->sortBy(fn ($r) => (float) ($unitFactors[$r->unit_id] ?? 1))->first();
        $old = (string) $row->price;
        if (DecimalMath::compare($row->price, $newPrice) !== 0) {
            $row->update(['price' => $newPrice]);
        }

        return $old;
    }

    /**
     * Apply all pending price changes that have reached their effective_at time.
     *
     * @return array{applied: int, failed: int, total: int}
     */
    public function applyDue(?CarbonInterface $at = null): array
    {
        $due = ScheduledPriceChange::due($at)
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get();

        $applied = 0;
        $failed = 0;

        foreach ($due as $change) {
            $ok = $this->apply($change);
            if ($ok) {
                $applied++;
            } else {
                $failed++;
            }
        }

        return [
            'applied' => $applied,
            'failed' => $failed,
            'total' => $due->count(),
        ];
    }

    /**
     * Parse multi-line paste input formatted as "code<tab/comma>price".
     *
     * @return array<int, array{product_id: int, sku_code: string, name_th: string, current_price: float, new_price: float}>
     */
    public function parseQuickPaste(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[\t,]+/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $code = trim($parts[0]);
            $price = (float) trim($parts[1]);
            if ($price <= 0 || $code === '') {
                continue;
            }

            $product = $this->findProductByCode($code);
            if (! $product) {
                continue;
            }

            $rows[] = [
                'product_id' => $product->id,
                'sku_code' => $product->sku_code,
                'name_th' => $product->name_th,
                'current_price' => (float) ($product->default_price ?? 0),
                'new_price' => $price,
            ];
        }

        return $rows;
    }

    private function findProductByCode(string $code): ?Product
    {
        $clean = trim($code);

        return Product::where('is_active', true)
            ->where(function ($w) use ($clean) {
                $w->where('sku_code', $clean)
                    ->orWhere('name_th', $clean)
                    ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $clean));
            })
            ->first()
            ?? Product::where('is_active', true)
                ->where(function ($w) use ($clean) {
                    $w->where('sku_code', 'like', "%{$clean}%")
                        ->orWhere('name_th', 'like', "%{$clean}%");
                })
                ->first();
    }
}
