<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Services\Inventory\ScaleBarcodeService;
use App\Services\Inventory\ScalePluPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScalePriceController extends Controller
{
    public function index(ScalePluPage $page)
    {
        $rows = $page->rows();

        return view('scale-prices.index', ['rows' => $rows, 'issues' => ScalePluPage::ISSUES,
            'counts' => collect(ScalePluPage::ISSUES)->map(fn ($label, $key) => $rows->filter(fn ($r) => in_array($key, $r['issues']))->count()),
            'overrides' => ProductPrice::join('price_tables', 'price_tables.id', '=', 'product_prices.price_table_id')->where('price_tables.is_default', false)->where('product_prices.is_active', true)->whereNull('unit_id')->get(['product_prices.product_id', 'price_tables.name', 'product_prices.price'])->groupBy('product_id'),
            'branches' => Branch::when(auth()->user()?->branch_id, fn ($q, $id) => $q->whereKey($id))->orderBy('name_th')->get(),
            'preview' => session('scale_import_preview', []), 'labelResult' => session('scale_label_result')]);
    }

    public function attachPlu(Request $request)
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']]);
        $product = Product::findOrFail($data['product_id']);

        return redirect()->route('scale-prices.index')->withErrors(['product_id' => "{$product->name_th}: ห้ามสร้าง PLU อัตโนมัติ — ใช้รหัสจากเครื่องชั่งเดิมเท่านั้น"]);
    }

    public function update(Request $request, ScalePluPage $page)
    {
        $data = $request->validate([
            'prices' => ['sometimes', 'array'], 'prices.*' => ['nullable', 'numeric', 'min:0', 'max:99999', 'decimal:0,2'],
            'scale_prices' => ['sometimes', 'array'], 'scale_prices.*' => ['nullable', 'numeric', 'min:0', 'max:99999', 'decimal:0,2'],
        ]);
        DB::transaction(function () use ($data, $page) {
            $rows = $page->rows();
            $tableId = PriceTable::where('is_default', true)->value('id');
            foreach ($data['prices'] ?? [] as $id => $price) {
                if ($price === null || ! $rows->contains(fn ($r) => $r['product']->id === (int) $id)) {
                    continue;
                }
                if (! $tableId) {
                    throw ValidationException::withMessages(['prices' => 'ยังไม่มีตารางราคาหลัก']);
                }
                $row = ProductPrice::firstOrNew(['product_id' => $id, 'price_table_id' => $tableId, 'unit_id' => null]);
                $old = $row->toArray();
                $row->fill(['price' => $price, 'is_active' => true]);
                if ($row->isDirty()) {
                    $row->save();
                    $this->audit('scale_erp_price', 'product_prices', $row->id, $old, $row->toArray());
                }
            }
            foreach ($data['scale_prices'] ?? [] as $plu => $price) {
                $plu = (string) $plu;
                if (! $rows->contains('plu', $plu)) {
                    throw ValidationException::withMessages(['scale_prices' => 'PLU ไม่ได้ลงทะเบียน']);
                }
                $this->saveScalePrice($plu, $price);
            }
        });

        return redirect()->route('scale-prices.index')->with('success', 'บันทึกราคาแล้ว — ตั้งราคา ERP และเครื่องชั่งให้ตรงกันเสมอ');
    }

    public function move(Request $request)
    {
        $data = $request->validate(['barcode_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'],
            'from_product_id' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($data) {
            $barcode = ProductBarcode::lockForUpdate()->findOrFail($data['barcode_id']);
            $target = Product::lockForUpdate()->findOrFail($data['product_id']);
            if (! ScalePluPage::isPlu($barcode->barcode) || ! $target->is_active || ScalePluPage::isPlu($target->sku_code)) {
                throw ValidationException::withMessages(['product_id' => 'ต้องเลือก SKU จริงที่เปิดใช้งาน และใช้ PLU เดิมเท่านั้น']);
            }
            if ($barcode->product_id !== (int) $data['from_product_id']) {
                throw ValidationException::withMessages(['barcode_id' => 'PLU ถูกย้ายแล้ว กรุณาโหลดหน้าใหม่']);
            }
            $old = $barcode->toArray();
            $barcode->product_id = $target->id;
            $barcode->barcode_type = 'SCALE_PLU';
            // หน่วยชั่งต้องอ้างอิงหน่วยของ SKU ใหม่ ไม่พกหน่วย/ราคาของสินค้าเก่าไปด้วย
            $barcode->unit_id = $target->base_unit_id;
            $barcode->unit_factor = 1;
            $barcode->price = null;
            $barcode->save();
            $this->audit('scale_plu_moved', 'product_barcodes', $barcode->id, $old, $barcode->toArray());
        });

        return redirect()->route('scale-prices.index')->with('success', 'ย้าย PLU เดิมแล้ว กรุณาซิงก์สินค้า POS ใหม่');
    }

    public function importPreview(Request $request)
    {
        $request->session()->forget('scale_import_preview');
        $request->validate(['csv' => ['nullable', 'string', 'max:200000'], 'file' => ['nullable', 'file', 'max:200']]);
        $text = $request->file('file') ? file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('csv');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $text));
        rewind($stream);
        $preview = [];
        $line = 0;
        while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;
            if ($fields === [null]) {
                continue;
            }
            if ($line === 1 && strtoupper(trim($fields[0])) === 'PLU') {
                continue;
            }
            $plu = trim($fields[0] ?? '');
            $price = trim($fields[1] ?? '');
            if (count($fields) !== 2 || ! ScalePluPage::isPlu($plu) || ! preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $price)
                || (float) $price > 99999 || isset($preview[$plu]) || ! ProductBarcode::where('barcode', $plu)->exists()) {
                fclose($stream);
                throw ValidationException::withMessages(['csv' => "แถว {$line}: ต้องเป็น PLU เดิมที่ลงทะเบียน, ราคา 0–99999 ทศนิยมไม่เกิน 2 ตำแหน่ง และไม่ซ้ำ"]);
            }
            $preview[$plu] = ['plu' => $plu, 'price' => $price, 'old' => DB::table('scale_plu_settings')->where('barcode', $plu)->value('scale_price')];
        }
        fclose($stream);
        if (! $preview) {
            throw ValidationException::withMessages(['csv' => 'ไม่พบข้อมูลราคาใน CSV']);
        }
        $request->session()->put('scale_import_preview', $preview);

        return redirect()->route('scale-prices.index');
    }

    public function importSave(Request $request)
    {
        $request->validate(['confirmed' => ['accepted']]);
        $preview = $request->session()->get('scale_import_preview', []);
        if (! $preview) {
            throw ValidationException::withMessages(['csv' => 'กรุณาดูตัวอย่าง CSV ก่อนบันทึก']);
        }
        DB::transaction(function () use ($preview) {
            foreach ($preview as $row) {
                if (! ProductBarcode::where('barcode', $row['plu'])->exists()) {
                    throw ValidationException::withMessages(['csv' => 'PLU เปลี่ยนไป กรุณานำเข้าใหม่']);
                }
                $this->saveScalePrice($row['plu'], $row['price']);
            }
        });
        $request->session()->forget('scale_import_preview');

        return redirect()->route('scale-prices.index')->with('success', 'นำเข้าราคาเครื่องชั่งแล้ว');
    }

    public function testLabel(Request $request, ScaleBarcodeService $decoder)
    {
        $data = $request->validate(['label' => ['required', 'regex:/^\d{13}$/D'], 'branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $decoded = $decoder->decode($data['label']);
        $result = ['error' => 'ไม่ตรง profile ที่เปิดใช้ หรือ check digit ไม่ถูกต้อง'];
        if ($decoded) {
            // ใช้ catalog/ราคาเดียวกับ POS รวมราคาเฉพาะสาขาและโปรโมชั่น โดยไม่สร้าง scan log หรือบิล
            $pos = app(PosController::class);
            $lookup = fn ($kind) => $pos->products(Request::create('/pos/products', 'GET', ['branch_id' => $data['branch_id'], 'q' => $decoded['plu'], 'exact' => 1, 'lookup' => $kind]))->getData(true);
            $product = ($lookup('barcode') ?: $lookup('sku'))[0] ?? null;
            $price = (float) ($product['pos_price'] ?? 0);
            $scale = DB::table('scale_plu_settings')->where('barcode', $decoded['plu'])->value('scale_price');
            $result = $decoded + ['product' => $product, 'erp_price' => $price,
                'qty' => $price > 0 ? ($decoded['weight_kg'] ?? $decoded['price'] / $price) : null,
                'scale_price' => $scale, 'mismatch' => $scale !== null && round((float) $scale * 100) !== round($price * 100),
                'error' => ! $product ? 'ไม่พบสินค้าเปิดใช้งานใน POS' : ($price <= 0 ? 'ยังไม่ได้ตั้งราคาขาย' : null)];
        }

        return redirect()->route('scale-prices.index')->with('scale_label_result', $result);
    }

    public function export(ScalePluPage $page)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['PLU ตั้งเครื่องชั่ง', 'ชื่อสินค้า', 'ราคา/กก.', 'รหัสสินค้าในระบบ', 'ราคาเครื่องชั่ง/กก.', 'ปัญหา'], ',', '"', '');
        foreach ($page->rows() as $r) {
            $fields = [$r['plu'] ?? '-', $r['product']->name_th, $r['price'], $r['product']->sku_code, $r['scale_price'], implode(' / ', array_map(fn ($key) => ScalePluPage::ISSUES[$key], $r['issues']))];
            fputcsv($stream, array_map(fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $fields), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="scale-plu-'.now()->format('Ymd').'.csv"']);
    }

    private function saveScalePrice(string $plu, $price): void
    {
        $old = DB::table('scale_plu_settings')->where('barcode', $plu)->first();
        DB::table('scale_plu_settings')->updateOrInsert(['barcode' => $plu], ['scale_price' => $price, 'updated_by' => auth()->id(), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
        $new = DB::table('scale_plu_settings')->where('barcode', $plu)->first();
        $this->audit('scale_price_saved', 'scale_plu_settings', $new->id, (array) $old, (array) $new);
    }

    private function audit(string $action, string $table, int $id, array $old, array $new): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'branch_id' => auth()->user()?->branch_id, 'action' => $action,
            'table_name' => $table, 'record_id' => $id, 'old_values' => $old, 'new_values' => $new]);
    }
}
