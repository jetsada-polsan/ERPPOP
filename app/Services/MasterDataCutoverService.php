<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MasterCutoverRun;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * เปลี่ยนรหัสสาขาและรหัสสินค้าเป็นชุดใหม่ตอนเริ่มใช้ระบบ
 *
 * กติกาที่ยึด
 *   - รหัสใหม่เรียงตาม id เดิม ผลลัพธ์จึงเหมือนเดิมทุกครั้งที่คำนวณ ตรวจทานได้
 *   - รหัสเดิมย้ายไปอยู่ legacy_sku / legacy_branch_code ไว้เทียบรายงานเก่าเท่านั้น
 *   - ห้ามใช้รหัสซ้ำ ต่อให้สินค้าเดิมถูกลบไปแล้ว เลขเดิมก็ไม่เอากลับมาใช้
 *   - บาร์โค้ดไม่ถูกแตะ ของที่พิมพ์ติดสินค้าไปแล้วเปลี่ยนตามไม่ได้
 *   - ตรวจให้ครบก่อน ถ้ามีอะไรค้างแม้แต่อย่างเดียวจะไม่เขียนอะไรเลย
 */
class MasterDataCutoverService
{
    public const BRANCH_PREFIX = 'B';
    public const PRODUCT_PREFIX = 'P';

    /**
     * แผนที่รหัสเดิม -> รหัสใหม่ โดยไม่เขียนอะไรลงฐาน
     *
     * เรียงตามรหัสเดิม ไม่ใช่ตาม id เพราะคนที่ตรวจแผนถือรายการรหัสเก่าอยู่ในมือ
     * เรียงตาม id แล้ว 0001 จะไปโผล่เป็น B004 ซึ่งเป็นชนิดของความสับสน
     * ที่ทำให้คนหยิบรหัสผิดตอนตั้งค่าเครื่อง POS
     *
     * @return array<int, array{id:int, legacy:string, new:string, name:string}>
     */
    public function planBranches(): array
    {
        $plan = [];
        $sequence = 0;
        foreach ($this->sortedByLegacyCode(Branch::all(), 'code') as $branch) {
            $plan[] = [
                'id' => $branch->id,
                'legacy' => (string) $branch->code,
                'new' => self::BRANCH_PREFIX.str_pad((string) ++$sequence, 3, '0', STR_PAD_LEFT),
                'name' => (string) $branch->name_th,
            ];
        }

        return $plan;
    }

    /** @return array<int, array{id:int, legacy:string, new:string, name:string, barcodes:int}> */
    public function planProducts(): array
    {
        $barcodeCounts = DB::table('product_barcodes')
            ->selectRaw('product_id, count(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $plan = [];
        $sequence = 0;
        foreach ($this->sortedByLegacyCode(Product::withTrashed()->get(), 'sku_code') as $product) {
            $plan[] = [
                'id' => $product->id,
                'legacy' => (string) $product->sku_code,
                'new' => self::PRODUCT_PREFIX.str_pad((string) ++$sequence, 6, '0', STR_PAD_LEFT),
                'name' => (string) $product->name_th,
                'barcodes' => (int) ($barcodeCounts[$product->id] ?? 0),
            ];
        }

        return $plan;
    }

    /**
     * เรียงตามรหัสเดิมแบบธรรมชาติ ตัวที่ไม่มีรหัสไปอยู่ท้าย เรียงด้วย id เพื่อให้ผลคงที่
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  \Illuminate\Support\Collection<int, TModel>  $records
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    private function sortedByLegacyCode($records, string $column)
    {
        return $records->sortBy(function ($record) use ($column) {
            $code = trim((string) $record->{$column});

            // คีย์เดียวจบ: ตัวไม่มีรหัสไปท้าย ตัวเลขเทียบด้วยค่าไม่ใช่ตัวอักษร
            // (ไม่งั้น '10' จะมาก่อน '9') แล้วปิดท้ายด้วย id ให้ผลคงที่เสมอ
            return ($code === '' ? '1' : '0')
                .preg_replace_callback('/\d+/', fn ($match) => str_pad($match[0], 12, '0', STR_PAD_LEFT), $code)
                .'#'.str_pad((string) $record->id, 12, '0', STR_PAD_LEFT);
        })->values();
    }

    /**
     * ตรวจทุกอย่างที่ต้องผ่านก่อนเขียนจริง
     *
     * @return array<int, array{level:string, issue:string, detail:string}>
     */
    public function preflight(): array
    {
        $problems = [];

        if (MasterCutoverRun::where('scope', 'branches')->exists()) {
            $problems[] = ['level' => 'หยุด', 'issue' => 'ทำ cutover สาขาไปแล้ว', 'detail' => 'รันซ้ำจะไล่เลขทับของเดิม'];
        }
        if (MasterCutoverRun::where('scope', 'products')->exists()) {
            $problems[] = ['level' => 'หยุด', 'issue' => 'ทำ cutover สินค้าไปแล้ว', 'detail' => 'รันซ้ำจะไล่เลขทับของเดิม'];
        }

        // ฐานมี unique ที่คอลัมน์ barcode อยู่แล้ว ซ้ำข้ามสินค้าจึงเกิดไม่ได้
        // เช็คนี้เก็บไว้เป็นตาข่ายรอง เผื่อวันหนึ่งมีคนถอด constraint ออก
        $duplicateBarcodes = DB::table('product_barcodes')
            ->selectRaw('barcode, count(distinct product_id) as products, count(*) as rows')
            ->whereNotNull('barcode')->where('barcode', '<>', '')
            ->groupBy('barcode')
            ->havingRaw('count(distinct product_id) > 1')
            ->get();
        foreach ($duplicateBarcodes as $row) {
            $problems[] = [
                'level' => 'หยุด',
                'issue' => 'บาร์โค้ดซ้ำข้ามสินค้า',
                'detail' => "{$row->barcode} ผูกอยู่กับสินค้า {$row->products} ตัว",
            ];
        }

        // รหัสใหม่ต้องไม่ชนของที่มีอยู่แล้ว
        $branchTargets = collect($this->planBranches())->pluck('new');
        $collidingBranches = Branch::whereIn('code', $branchTargets)->pluck('code');
        foreach ($collidingBranches as $code) {
            $problems[] = ['level' => 'หยุด', 'issue' => 'รหัสสาขาใหม่ชนของเดิม', 'detail' => $code];
        }

        $productTargets = collect($this->planProducts())->pluck('new');
        $collidingProducts = Product::withTrashed()->whereIn('sku_code', $productTargets)->pluck('sku_code');
        foreach ($collidingProducts as $code) {
            $problems[] = ['level' => 'หยุด', 'issue' => 'รหัสสินค้าใหม่ชนของเดิม', 'detail' => $code];
        }

        // รหัสเดิมซ้ำกันเอง = mapping กลับไประบบเก่าจะกำกวม
        foreach ([['products', 'sku_code'], ['branches', 'code']] as [$table, $column]) {
            $duplicates = DB::table($table)->selectRaw("{$column} as code, count(*) as total")
                ->groupBy($column)->havingRaw('count(*) > 1')->pluck('code');
            foreach ($duplicates as $code) {
                $problems[] = ['level' => 'หยุด', 'issue' => "รหัสเดิมซ้ำใน {$table}", 'detail' => (string) $code];
            }
        }

        // บาร์โค้ดที่ check digit ไม่ถูกจะสแกนไม่ติดกับเครื่องอ่านบางรุ่น
        // รายงานให้รู้อย่างเดียว ไม่แก้และไม่สร้างใหม่ ของที่พิมพ์ติดสินค้าไปแล้วเปลี่ยนตามไม่ได้
        $invalidEan = $this->invalidEan13Count();
        if ($invalidEan > 0) {
            $problems[] = [
                'level' => 'เตือน',
                'issue' => 'EAN-13 check digit ไม่ถูก',
                'detail' => "{$invalidEan} รายการ — ไม่แก้ให้อัตโนมัติ ต้องตัดสินใจเอง",
            ];
        }

        // ไม่ถึงกับหยุด แต่ต้องรู้ก่อนกด
        $withoutBarcode = collect($this->planProducts())->where('barcodes', 0)->count();
        if ($withoutBarcode > 0) {
            $problems[] = [
                'level' => 'เตือน',
                'issue' => 'สินค้าไม่มีบาร์โค้ด',
                'detail' => "{$withoutBarcode} รายการ — สแกนขายที่ POS ไม่ได้ ต้องค้นด้วยรหัสหรือชื่อ",
            ];
        }

        $blankSku = Product::withTrashed()->where(fn ($query) => $query->whereNull('sku_code')->orWhere('sku_code', ''))->count();
        if ($blankSku > 0) {
            $problems[] = ['level' => 'เตือน', 'issue' => 'สินค้าไม่มีรหัสเดิม', 'detail' => "{$blankSku} รายการ — legacy_sku จะว่าง"];
        }

        return $problems;
    }

    /** นับบาร์โค้ด 13 หลักที่ check digit คำนวณไม่ตรง */
    private function invalidEan13Count(): int
    {
        $invalid = 0;
        DB::table('product_barcodes')
            ->whereNotNull('barcode')
            ->whereRaw('length(barcode) = 13')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$invalid) {
                foreach ($rows as $row) {
                    if (ctype_digit((string) $row->barcode) && ! $this->isValidEan13((string) $row->barcode)) {
                        $invalid++;
                    }
                }
            });

        return $invalid;
    }

    public function isValidEan13(string $barcode): bool
    {
        if (strlen($barcode) !== 13 || ! ctype_digit($barcode)) {
            return false;
        }

        $sum = 0;
        for ($position = 0; $position < 12; $position++) {
            $sum += ((int) $barcode[$position]) * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10 === (int) $barcode[12];
    }

    /**
     * เขียนรหัสใหม่ลงฐาน — ทั้งชุดสำเร็จหรือไม่สำเร็จพร้อมกัน
     *
     * @return array{branches:int, products:int}
     */
    public function apply(?int $userId = null): array
    {
        $blocking = collect($this->preflight())->where('level', 'หยุด');
        if ($blocking->isNotEmpty()) {
            throw new RuntimeException('ยังผ่านการตรวจไม่ครบ '.$blocking->count().' ข้อ: '.$blocking->pluck('issue')->unique()->implode(' · '));
        }

        $branchPlan = $this->planBranches();
        $productPlan = $this->planProducts();

        return DB::transaction(function () use ($branchPlan, $productPlan, $userId) {
            $barcodesBefore = DB::table('product_barcodes')->count();

            foreach ($branchPlan as $row) {
                Branch::where('id', $row['id'])->update([
                    'legacy_branch_code' => $row['legacy'],
                    'code' => $row['new'],
                ]);
            }

            foreach ($productPlan as $row) {
                Product::withTrashed()->where('id', $row['id'])->update([
                    'legacy_sku' => $row['legacy'],
                    'sku_code' => $row['new'],
                ]);
            }

            // บาร์โค้ดต้องไม่ขยับแม้แต่แถวเดียว ถ้าขยับแปลว่ามีอะไรผูกไว้ผิด
            if (DB::table('product_barcodes')->count() !== $barcodesBefore) {
                throw new RuntimeException('จำนวนบาร์โค้ดเปลี่ยนระหว่าง cutover — ยกเลิกทั้งหมด');
            }

            foreach ([['branches', $branchPlan], ['products', $productPlan]] as [$scope, $plan]) {
                MasterCutoverRun::create([
                    'scope' => $scope,
                    'mapped_count' => count($plan),
                    'first_code' => $plan[0]['new'] ?? null,
                    'last_code' => $plan === [] ? null : end($plan)['new'],
                    'applied_by' => $userId,
                    'applied_at' => now(),
                ]);
            }

            return ['branches' => count($branchPlan), 'products' => count($productPlan)];
        });
    }

    /**
     * สแกนบาร์โค้ดแล้วยังได้สินค้าตัวเดิมไหม
     *
     * cutover เปลี่ยนแค่ sku_code ส่วนบาร์โค้ดผูกกับ product_id จึงไม่ควรกระทบ
     * แต่ "ไม่ควร" กับ "พิสูจน์แล้ว" คนละเรื่อง โดยเฉพาะกับของที่พิมพ์ติดสินค้าไปแล้ว
     *
     * @param  array<int, string>  $barcodes
     * @return array{checked:int, resolved:int, failed:array<int, string>}
     */
    public function verifyBarcodes(array $barcodes): array
    {
        $failed = [];
        $resolved = 0;

        foreach ($barcodes as $barcode) {
            $product = DB::table('product_barcodes')
                ->join('products', 'products.id', '=', 'product_barcodes.product_id')
                ->where('product_barcodes.barcode', $barcode)
                ->whereNull('products.deleted_at')
                ->first(['products.id', 'products.sku_code', 'products.legacy_sku']);

            if ($product) {
                $resolved++;
            } else {
                $failed[] = $barcode;
            }
        }

        return ['checked' => count($barcodes), 'resolved' => $resolved, 'failed' => $failed];
    }
}
