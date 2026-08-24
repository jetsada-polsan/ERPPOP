<?php

namespace App\Services;

use App\Models\MasterCutoverRun;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One-time, reviewable migration from generic Pxxxxxx codes to category-led SKU codes. */
class ProductSkuRecodeService
{
    public const SCOPE = 'product-category-sku';

    public function __construct(private readonly ProductSkuAllocator $allocator) {}

    /** @return array<int, array{id:int,old:string,legacy:string,new:?string,category:string,name:string,barcodes:int,excluded:bool}> */
    public function plan(): array
    {
        $barcodeCounts = DB::table('product_barcodes')->selectRaw('product_id, count(*) as total')
            ->groupBy('product_id')->pluck('total', 'product_id');

        $products = Product::withTrashed()->with('category')->get()->sortBy(function (Product $product): string {
            $category = trim((string) ($product->category?->code ?? ''));
            $legacy = trim((string) ($product->legacy_sku ?: $product->sku_code));

            return $category.'|'.$this->naturalKey($legacy).'|'.str_pad((string) $product->id, 12, '0', STR_PAD_LEFT);
        });

        $sequences = [];
        $plan = [];
        foreach ($products as $product) {
            $category = trim((string) ($product->category?->code ?? ''));
            $target = null;
            $excluded = $category === 'CC';
            try {
                if ($category !== '' && $category !== '0' && ! $excluded) {
                    [$prefix, $digits] = $this->allocator->formatForCategoryCode($category);
                    $sequences[$category] = ($sequences[$category] ?? 0) + 1;
                    if ($sequences[$category] > (10 ** $digits) - 1) {
                        throw new RuntimeException("ประเภท {$category} มีสินค้าเกินช่วงรหัสที่รองรับ");
                    }
                    $target = $prefix.str_pad((string) $sequences[$category], $digits, '0', STR_PAD_LEFT);
                }
            } catch (RuntimeException) {
                $target = null;
            }

            $plan[] = [
                'id' => $product->id,
                'old' => (string) $product->sku_code,
                'legacy' => (string) ($product->legacy_sku ?: $product->sku_code),
                'new' => $target,
                'category' => $category,
                'name' => (string) $product->name_th,
                'barcodes' => (int) ($barcodeCounts[$product->id] ?? 0),
                // Cancelled products must remain traceable by their current SKU,
                // but are deliberately outside the new sellable SKU sequence.
                'excluded' => $excluded,
            ];
        }

        return $plan;
    }

    /** @return array<int, array{level:string,issue:string,detail:string}> */
    public function preflight(array $plan = []): array
    {
        $plan = $plan ?: $this->plan();
        $problems = [];

        if (MasterCutoverRun::where('scope', self::SCOPE)->exists()) {
            $problems[] = ['level' => 'หยุด', 'issue' => 'เปลี่ยนรหัสสินค้าแบบจัดประเภทไปแล้ว', 'detail' => 'ห้ามรันซ้ำเพื่อป้องกันรหัสขยับ'];
        }

        $unclassified = collect($plan)->filter(fn (array $row) => $row['new'] === null && ! $row['excluded']);
        foreach ($unclassified->groupBy('category') as $category => $rows) {
            $problems[] = [
                'level' => 'หยุด',
                'issue' => 'สินค้าไม่มีประเภทที่รัน SKU ได้',
                'detail' => (($category === '' ? '(ว่าง)' : $category).' จำนวน '.$rows->count().' รายการ'),
            ];
        }

        $duplicates = collect($plan)->pluck('new')->filter()->duplicates();
        foreach ($duplicates->unique() as $sku) {
            $problems[] = ['level' => 'หยุด', 'issue' => 'รหัส SKU ใหม่ซ้ำ', 'detail' => (string) $sku];
        }

        // SKU กับ barcode อยู่คนละ namespace แต่แจ้งไว้เพื่อให้หน้าสแกนแยก scanner
        // input ออกจากช่องค้นหา SKU อย่างชัดเจนก่อนเปิดขายจริง.
        $targets = collect($plan)->pluck('new')->filter()->values();
        $barcodeProducts = DB::table('product_barcodes')->whereIn('barcode', $targets)->pluck('product_id', 'barcode');
        foreach ($plan as $row) {
            if ($row['new'] && isset($barcodeProducts[$row['new']]) && (int) $barcodeProducts[$row['new']] !== $row['id']) {
                $problems[] = [
                    'level' => 'เตือน',
                    'issue' => 'SKU ใหม่ตรงกับบาร์โค้ดของสินค้าอื่น',
                    'detail' => "{$row['new']} · {$row['name']} — POS ต้องแยกสแกนบาร์โค้ดออกจากค้นหา SKU",
                ];
            }
        }

        return $problems;
    }

    /** @return array{products:int,first_code:string,last_code:string} */
    public function apply(?int $userId = null): array
    {
        $plan = $this->plan();
        $blocking = collect($this->preflight($plan))->where('level', 'หยุด');
        if ($blocking->isNotEmpty()) {
            throw new RuntimeException('ยังเปลี่ยนรหัสไม่ได้: '.$blocking->pluck('issue')->unique()->implode(' · '));
        }

        $recodeRows = collect($plan)->reject(fn (array $row) => $row['excluded'])->values()->all();

        return DB::transaction(function () use ($recodeRows, $userId): array {
            // sku_code unique: ย้ายทุกแถวไปชื่อชั่วคราวก่อน เพื่อให้สลับ/เรียงใหม่ได้โดยไม่ชน
            foreach ($recodeRows as $row) {
                Product::withTrashed()->whereKey($row['id'])->update([
                    'sku_code' => '__recode_'.$row['id'],
                    'legacy_sku' => $row['legacy'],
                ]);
            }
            foreach ($recodeRows as $row) {
                Product::withTrashed()->whereKey($row['id'])->update(['sku_code' => $row['new']]);
            }

            MasterCutoverRun::create([
                'scope' => self::SCOPE,
                'mapped_count' => count($recodeRows),
                'first_code' => collect($recodeRows)->pluck('new')->filter()->sort()->first(),
                'last_code' => collect($recodeRows)->pluck('new')->filter()->sort()->last(),
                'applied_by' => $userId,
                'applied_at' => now(),
            ]);

            return [
                'products' => count($recodeRows),
                'first_code' => (string) collect($recodeRows)->pluck('new')->filter()->sort()->first(),
                'last_code' => (string) collect($recodeRows)->pluck('new')->filter()->sort()->last(),
            ];
        });
    }

    private function naturalKey(string $value): string
    {
        return preg_replace_callback('/\d+/', fn (array $match) => str_pad($match[0], 20, '0', STR_PAD_LEFT), $value) ?? $value;
    }
}
