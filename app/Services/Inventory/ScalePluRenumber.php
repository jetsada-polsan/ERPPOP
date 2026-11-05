<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScalePluRenumber
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['plan' => $message]);
    }

    public function candidates()
    {
        $rows = app(ScalePluPage::class)->rows()->unique(fn ($r) => $r['product']->id)->values();
        $names = $rows->filter(fn ($r) => $r['product']->is_active)->countBy(fn ($r) => $this->name($r['product']->name_th));

        return $rows->map(function ($r) use ($names) {
            $p = $r['product'];
            $r['warnings'] = [];
            if (ScalePluPage::isPlu($p->sku_code)) {
                $r['warnings'][] = 'ต้องผูกกับ SKU จริงก่อน';
            }
            if ($r['price'] <= 1) {
                $r['warnings'][] = 'ยังไม่ตั้งราคา';
            }
            if (($names[$this->name($p->name_th)] ?? 0) > 1) {
                $r['warnings'][] = 'ชื่อซ้ำ — เลือกสินค้าเดียว';
            }

            return $r;
        })->sortBy(fn ($r) => sprintf('%010d', $r['product']->product_category_id).$r['product']->sku_code)->values();
    }

    private function name(string $name): string
    {
        return str_replace(['**', '(ชั่งน้ำหนัก)', 'ชั่งกก'], '', preg_replace('/\s+/u', '', $name));
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            DB::table('products')->orderBy('id')->get(), DB::table('product_barcodes')->orderBy('id')->get(),
            DB::table('product_prices')->orderBy('id')->get(), DB::table('scale_plu_settings')->orderBy('id')->get(),
            DB::table('price_tables')->orderBy('id')->get(), DB::table('scale_barcode_profiles')->orderBy('id')->get(),
        ]));
    }

    public function preview(array $selected): int
    {
        return DB::transaction(function () use ($selected) {
            $this->lockCatalog();

            return $this->createPreview($selected);
        });
    }

    private function lockCatalog(): void
    {
        DB::table('scale_plu_plan_guard')->where('id', 1)->lockForUpdate()->first();
        DB::table('products')->orderBy('id')->lockForUpdate()->get();
        DB::table('product_barcodes')->orderBy('id')->lockForUpdate()->get();
        DB::table('product_prices')->orderBy('id')->lockForUpdate()->get();
        DB::table('scale_plu_settings')->orderBy('id')->lockForUpdate()->get();
        DB::table('price_tables')->orderBy('id')->lockForUpdate()->get();
        DB::table('scale_barcode_profiles')->orderBy('id')->lockForUpdate()->get();
    }

    private function createPreview(array $selected): int
    {
        $candidates = $this->candidates()->keyBy(fn ($r) => $r['product']->id);
        $rows = [];
        foreach ($selected as $source => $target) {
            if (! $target) {
                continue;
            }
            $r = $candidates->get($source);
            $p = Product::find($target);
            if (! $r || ! $r['product']->is_active || ! $p?->is_active || ScalePluPage::isPlu($p->sku_code)) {
                $this->fail('เลือก SKU จริงที่เปิดใช้งานเท่านั้น');
            }
            if (! ScalePluPage::isPlu($r['product']->sku_code) && (int) $source !== $p->id) {
                $this->fail('SKU จริงต้องใช้สินค้าตัวเดิม');
            }
            if (isset($rows[$p->id])) {
                $this->fail('แต่ละ SKU เลือกได้ครั้งเดียว');
            }
            $price = (float) (DB::table('product_prices')->where('price_table_id', DB::table('price_tables')->where('is_default', true)->value('id'))->where('product_id', $p->id)->whereNull('unit_id')->where('is_active', true)->value('price') ?? $p->default_price ?? 0);
            if ($price > 9999.99) {
                $this->fail('ราคาเกิน 9999.99 บาท ป้าย POPSTAR-801 รองรับไม่พอ');
            }
            $warnings = array_values(array_filter($r['warnings'], fn ($w) => $w !== 'ต้องผูกกับ SKU จริงก่อน'));
            if ($price <= 1 && ! in_array('ยังไม่ตั้งราคา', $warnings)) {
                $warnings[] = 'ยังไม่ตั้งราคา';
            }
            $rows[$p->id] = ['source_id' => (int) $source, 'product_id' => $p->id, 'sku' => $p->sku_code, 'name' => $p->name_th,
                'category' => $p->product_category_id, 'price' => $price, 'warnings' => $warnings,
                'old' => ProductBarcode::whereIn('product_id', [$source, $p->id])->get()->filter(fn ($b) => ScalePluPage::isPlu($b->barcode))->pluck('barcode')->all(), 'locked' => false];
        }
        if (! $rows) {
            $this->fail('เลือกสินค้าอย่างน้อยหนึ่งรายการ');
        }
        $rows = collect($rows)->sortBy(fn ($r) => sprintf('%010d', $r['category']).$r['sku'])->values()->all();
        foreach ($rows as $i => &$row) {
            $row['plu'] = (string) (801001 + $i);
        }
        unset($row);
        $this->warnings($rows);
        $this->validate($rows);

        return DB::table('scale_plu_plans')->insertGetId(['rows' => json_encode($rows), 'user_id' => auth()->id(), 'fingerprint' => $this->fingerprint(), 'status' => 'preview', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function plan(int $id): object
    {
        $plan = DB::table('scale_plu_plans')->find($id);
        abort_unless($plan, 404);
        $plan->rows = json_decode($plan->rows, true);

        return $plan;
    }

    public function edit(int $id, array $numbers, array $locked, bool $reorder): void
    {
        DB::transaction(function () use ($id, $numbers, $locked, $reorder) {
            DB::table('scale_plu_plan_guard')->where('id', 1)->lockForUpdate()->first();
            $plan = $this->plan($id);
            if ($plan->status !== 'preview') {
                $this->fail('แก้ได้เฉพาะแผนที่ยังไม่ใช้');
            }
            $rows = $plan->rows;
            foreach ($rows as &$r) {
                $r['plu'] = (string) ($numbers[$r['product_id']] ?? $r['plu']);
                $r['locked'] = isset($locked[$r['product_id']]);
            }
            unset($r);
            if ($reorder) {
                $used = array_column(array_filter($rows, fn ($r) => $r['locked']), 'plu');
                $next = 801001;
                foreach ($rows as &$r) {
                    if ($r['locked']) {
                        continue;
                    }
                    while (in_array((string) $next, $used)) {
                        $next++;
                    }
                    $r['plu'] = (string) $next++;
                    $used[] = $r['plu'];
                }
                unset($r);
            }
            $this->warnings($rows);
            $this->validate($rows);
            DB::table('scale_plu_plans')->where('id', $id)->update(['rows' => json_encode($rows), 'updated_at' => now()]);
        });
    }

    private function warnings(array &$rows): void
    {
        foreach ($rows as &$r) {
            $r['warnings'] = array_values(array_filter($r['warnings'], fn ($w) => ! str_starts_with($w, 'เลขใหม่เคยใช้')));
            if (ProductBarcode::where('barcode', $r['plu'])->exists() || Product::where('sku_code', $r['plu'])->exists()) {
                $r['warnings'][] = 'เลขใหม่เคยใช้ — เก็บป้ายเก่าออกก่อนขาย';
            }
        }
        unset($r);
    }

    private function validate(array $rows): void
    {
        $codes = array_column($rows, 'plu');
        if (count($codes) !== count(array_unique($codes))) {
            $this->fail('เลข PLU ซ้ำ กรุณาแก้เลข');
        }
        if (count(array_unique(array_column($rows, 'product_id'))) !== count($rows)) {
            $this->fail('หนึ่ง SKU ต้องมีเลขเดียว');
        }
        foreach ($codes as $code) {
            if (! preg_match('/^801[0-9]{3}$/D', $code)) {
                $this->fail('ใช้เลข 801000–801999 เท่านั้น');
            }
        }
    }

    public function apply(int $id, bool $accepted, string $revision): void
    {
        DB::transaction(function () use ($id, $accepted, $revision) {
            DB::table('scale_plu_plan_guard')->where('id', 1)->lockForUpdate()->first();
            $plan = $this->plan($id);
            if (! hash_equals(hash('sha256', json_encode($plan->rows)), $revision)) {
                $this->fail('ตัวอย่างเลขเปลี่ยนแล้ว กรุณาโหลดและตรวจแผนอีกครั้ง');
            }
            $this->lockCatalog();
            if ($plan->status !== 'preview' || $plan->fingerprint !== $this->fingerprint()) {
                $this->fail('ข้อมูลเปลี่ยนแล้ว กรุณาสร้างแผนใหม่');
            }
            $this->validate($plan->rows);
            if (! $accepted && collect($plan->rows)->contains(fn ($r) => count($r['warnings']) > 0)) {
                $this->fail('แก้ปัญหาหรือติ๊กยอมรับคำเตือนก่อน');
            }
            $ids = array_unique(array_merge(array_column($plan->rows, 'source_id'), array_column($plan->rows, 'product_id')));
            $before = ProductBarcode::whereIn('product_id', $ids)->lockForUpdate()->get()->filter(fn ($b) => ScalePluPage::isPlu($b->barcode));
            foreach ($plan->rows as $r) {
                if (ProductBarcode::where('barcode', $r['plu'])->whereNotIn('product_id', $ids)->exists()) {
                    $this->fail('เลขถูกใช้โดยสินค้าอื่น กรุณาแก้เลข');
                }
                $barcodeService = app(ScaleBarcodeService::class);
                $decoded = $barcodeService->decode($barcodeService->fromTotalPrice($r['plu'], (float) $r['price']));
                if (! $decoded || $decoded['profile'] !== 'POPSTAR-801' || $decoded['value_type'] !== 'price' || abs($decoded['price'] - $r['price']) > 0.001) {
                    $this->fail('เปิด profile POPSTAR-801 แบบราคาและตรวจ check digit ก่อนใช้แผน');
                }
                $p = Product::findOrFail($r['product_id']);
                if (! $p->is_active || ScalePluPage::isPlu($p->sku_code)) {
                    $this->fail('ต้องเป็น SKU จริงที่เปิดใช้งาน');
                }
            }
            $old = $before->map->getAttributes()->values()->all();
            $proxyIds = array_values(array_diff(array_column($plan->rows, 'source_id'), array_column($plan->rows, 'product_id')));
            $productsBefore = Product::whereIn('id', $proxyIds)->get()->map->getAttributes()->all();
            // Retire PLU-as-SKU placeholders so POS cannot still find old labels through SKU fallback.
            Product::whereIn('id', $proxyIds)->update(['is_active' => false]);
            $productsAfter = Product::whereIn('id', $proxyIds)->get()->map->getAttributes()->all();
            $settingsBefore = DB::table('scale_plu_settings')->whereIn('barcode', array_column($plan->rows, 'plu'))->get()->map(fn ($r) => (array) $r)->all();
            foreach ($before as $b) {
                $b->update(['is_active' => false]);
            }
            $created = [];
            foreach ($plan->rows as $r) {
                $p = Product::findOrFail($r['product_id']);
                $b = ProductBarcode::firstOrNew(['barcode' => $r['plu']]);
                $b->fill(['product_id' => $p->id, 'barcode_type' => 'SCALE_PLU', 'unit_id' => $p->base_unit_id, 'unit_factor' => 1, 'price' => null, 'is_active' => true]);
                $b->save();
                $created[] = $b->id;
                DB::table('scale_plu_settings')->updateOrInsert(['barcode' => $r['plu']], ['scale_price' => $r['price'], 'updated_by' => auth()->id(), 'updated_at' => now()]);
            }
            $after = ProductBarcode::whereIn('id', array_unique(array_merge(array_column($old, 'id'), $created)))->orderBy('id')->get()->map->getAttributes()->all();
            $settingsAfter = DB::table('scale_plu_settings')->whereIn('barcode', array_column($plan->rows, 'plu'))->orderBy('barcode')->get()->map(fn ($r) => (array) $r)->all();
            $changes = ['products_before' => $productsBefore, 'products_after' => $productsAfter, 'settings_before' => $settingsBefore, 'settings_after' => $settingsAfter, 'before' => $old, 'after' => $after, 'created' => array_values(array_diff($created, array_column($old, 'id')))];
            DB::table('scale_plu_plans')->where('id', $id)->update(['status' => 'applied', 'changes' => json_encode($changes), 'updated_at' => now()]);
            $this->audit('scale_plu_plan_applied', $id, $old, $after);
        });
    }

    public function rollback(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('scale_plu_plan_guard')->where('id', 1)->lockForUpdate()->first();
            $plan = $this->plan($id);
            if ($plan->status !== 'applied') {
                $this->fail('ยกเลิกได้เฉพาะแผนที่ใช้แล้ว');
            }
            $changes = json_decode($plan->changes, true);
            $current = ProductBarcode::whereIn('id', array_column($changes['after'], 'id'))->orderBy('id')->lockForUpdate()->get()->map->getAttributes()->all();
            if ($current != $changes['after']) {
                $this->fail('บาร์โค้ดเปลี่ยนหลังใช้แผน กรุณาตรวจสอบก่อนยกเลิก');
            }
            $productsCurrent = Product::whereIn('id', array_column($changes['products_after'], 'id'))->lockForUpdate()->get()->map->getAttributes()->all();
            $settingsCurrent = DB::table('scale_plu_settings')->whereIn('barcode', array_column($plan->rows, 'plu'))->orderBy('barcode')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
            if ($productsCurrent != $changes['products_after'] || $settingsCurrent != $changes['settings_after']) {
                $this->fail('สินค้า/ราคาเครื่องชั่งเปลี่ยนหลังใช้แผน กรุณาตรวจสอบก่อนยกเลิก');
            }
            foreach ($changes['products_before'] as $row) {
                DB::table('products')->where('id', $row['id'])->update(['is_active' => $row['is_active'], 'updated_at' => now()]);
            }
            DB::table('scale_plu_settings')->whereIn('barcode', array_column($plan->rows, 'plu'))->delete();
            foreach ($changes['settings_before'] as $row) {
                DB::table('scale_plu_settings')->insert($row);
            }
            foreach ($changes['before'] as $row) {
                DB::table('product_barcodes')->where('id', $row['id'])->update($row);
            }
            ProductBarcode::whereIn('id', $changes['created'])->update(['is_active' => false]);
            DB::table('scale_plu_plans')->where('id', $id)->update(['status' => 'rolled_back', 'updated_at' => now()]);
            $this->audit('scale_plu_plan_rolled_back', $id, $changes['after'], $changes['before']);
        });
    }

    private function audit(string $action, int $id, array $old, array $new): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'branch_id' => auth()->user()?->branch_id, 'action' => $action, 'table_name' => 'scale_plu_plans', 'record_id' => $id, 'old_values' => $old, 'new_values' => $new]);
    }
}
