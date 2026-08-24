<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Services\ProductSkuAllocator;
use App\Support\BarcodePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterDataSetupController extends Controller
{
    private const TYPES = ['categories', 'products', 'employees'];

    public function index(): \Illuminate\View\View
    {
        return view('master-data-setup.index', [
            'counts' => [
                'categories' => ProductCategory::count(),
                'products' => Product::count(),
                'employees' => Employee::count(),
            ],
            'pending' => session('master_data_setup_preview'),
        ]);
    }

    public function template(string $type): StreamedResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $headers = match ($type) {
            'categories' => ['category_code', 'category_name_th', 'category_name_en'],
            'products' => ['legacy_sku', 'name_th', 'category_code', 'unit_code', 'default_price', 'average_cost', 'is_vat', 'is_active', 'barcode', 'barcode_type'],
            'employees' => ['source_employee_code', 'full_name', 'nickname', 'phone', 'branch_code', 'department', 'position', 'status'],
        };

        $examples = match ($type) {
            'categories' => [['101', 'หมูหมัก/ไก่หมัก', 'Marinated meat']],
            'products' => [['OLD-0001', 'สินค้าใหม่ตัวอย่าง', '101', 'KG', '120.00', '80.00', '1', '1', '', '']],
            'employees' => [['OLD-001', 'พนักงานตัวอย่าง', 'นิด', '0800000000', 'B001', 'ขาย', 'พนักงานขาย', 'Active']],
        };

        return response()->streamDownload(function () use ($headers, $examples): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($examples as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "jet-erp-{$type}-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        $rows = $this->readCsv($request->file('file')->getRealPath());
        if (count($rows) < 2) {
            return back()->withErrors(['file' => 'ไฟล์ไม่มีรายการข้อมูล']);
        }

        $headers = $this->headers(array_shift($rows));
        $required = match ($type) {
            'categories' => ['category_code', 'category_name_th'],
            'products' => ['legacy_sku', 'name_th', 'category_code', 'unit_code'],
            'employees' => ['source_employee_code', 'full_name'],
        };
        foreach ($required as $column) {
            if (!array_key_exists($column, $headers)) {
                return back()->withErrors(['file' => "ไม่พบหัวคอลัมน์ {$column} กรุณาใช้ template ของระบบ"]);
            }
        }

        $plan = [];
        foreach ($rows as $index => $row) {
            $data = [];
            foreach ($headers as $column => $position) {
                $data[$column] = trim((string) ($row[$position] ?? ''));
            }
            if (implode('', $data) === '') {
                continue;
            }
            $plan[] = ['line' => $index + 2, 'data' => $data];
        }

        $validated = $this->validatePlan($type, $plan, app(BarcodePolicy::class));
        $token = (string) Str::uuid();
        Storage::put("master-data-setup/{$token}.json", json_encode([
            'type' => $type,
            'rows' => $validated['rows'],
            'summary' => $validated['summary'],
        ], JSON_UNESCAPED_UNICODE));
        session(['master_data_setup_preview' => ['token' => $token, 'type' => $type] + $validated['summary']]);

        return redirect()->route('master-data-setup.index')->with('success', 'ตรวจไฟล์แล้ว โปรดตรวจผลและกดยืนยันเพิ่มรายการใหม่');
    }

    public function apply(Request $request, string $type, ProductSkuAllocator $skuAllocator, BarcodePolicy $barcodePolicy): RedirectResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $pending = session('master_data_setup_preview');
        abort_unless(is_array($pending) && $pending['type'] === $type && $request->string('token')->toString() === $pending['token'], 422, 'ผลตรวจหมดอายุ กรุณาอัปโหลดตรวจใหม่');

        $saved = json_decode((string) Storage::get("master-data-setup/{$pending['token']}.json"), true);
        abort_unless(is_array($saved) && ($saved['type'] ?? null) === $type, 422, 'ไม่พบผลตรวจไฟล์');
        $errors = collect($saved['rows'])->where('action', 'error');
        if ($errors->isNotEmpty()) {
            return back()->withErrors(['file' => 'ไฟล์ยังมีข้อผิดพลาด '.count($errors).' รายการ จึงไม่สามารถนำเข้าได้']);
        }

        $created = DB::transaction(function () use ($type, $saved, $skuAllocator, $barcodePolicy): int {
            return match ($type) {
                'categories' => $this->applyCategories($saved['rows']),
                'products' => $this->applyProducts($saved['rows'], $skuAllocator, $barcodePolicy),
                'employees' => $this->applyEmployees($saved['rows']),
            };
        });

        Storage::delete("master-data-setup/{$pending['token']}.json");
        session()->forget('master_data_setup_preview');

        return redirect()->route('master-data-setup.index')->with('success', "นำเข้าข้อมูล {$created} รายการแล้ว รายการเดิมถูกข้ามโดยไม่แก้ไข");
    }

    private function validatePlan(string $type, array $rows, BarcodePolicy $barcodePolicy): array
    {
        $summary = ['new' => 0, 'skip' => 0, 'error' => 0, 'examples' => []];
        $categoryCodes = ProductCategory::pluck('id', 'code')->all();
        $unitCodes = ProductUnit::pluck('id', 'code')->all();
        $branchCodes = Branch::pluck('id', 'code')->all();
        $seen = [];

        foreach ($rows as &$row) {
            $data = $row['data'];
            $key = match ($type) {
                'categories' => $data['category_code'],
                'products' => $data['legacy_sku'],
                'employees' => $data['source_employee_code'],
            };
            $row['message'] = '';
            $missingRequired = match ($type) {
                'categories' => $data['category_code'] === '' || $data['category_name_th'] === '',
                'products' => $data['legacy_sku'] === '' || $data['name_th'] === '' || $data['category_code'] === '' || $data['unit_code'] === '',
                'employees' => $data['source_employee_code'] === '' || $data['full_name'] === '',
            };
            if ($missingRequired || isset($seen[$key])) {
                $row['action'] = 'error';
                $row['message'] = $missingRequired ? 'ข้อมูลบังคับไม่ครบ' : 'คีย์อ้างอิงซ้ำในไฟล์';
            } elseif ($type === 'categories' && isset($categoryCodes[$key])) {
                $row['action'] = 'skip'; $row['message'] = 'มีประเภทนี้แล้ว ไม่แก้ไขของเดิม';
            } elseif ($type === 'products' && (Product::withTrashed()->where('legacy_sku', $key)->exists() || Product::withTrashed()->where('sku_code', $key)->exists())) {
                $row['action'] = 'skip'; $row['message'] = 'มีสินค้าอ้างอิงนี้แล้ว ไม่แก้ไขของเดิม';
            } elseif ($type === 'employees' && Employee::where('source_section', 'excel:'.$key)->exists()) {
                $row['action'] = 'skip'; $row['message'] = 'มีพนักงานอ้างอิงนี้แล้ว ไม่แก้ไขของเดิม';
            } elseif ($type === 'products' && !isset($categoryCodes[$data['category_code']])) {
                $row['action'] = 'error'; $row['message'] = 'ไม่พบ category_code';
            } elseif ($type === 'products' && !isset($unitCodes[$data['unit_code']])) {
                $row['action'] = 'error'; $row['message'] = 'ไม่พบ unit_code';
            } elseif ($type === 'products' && $data['barcode'] !== '' && ProductBarcode::where('barcode', $data['barcode'])->exists()) {
                $row['action'] = 'skip'; $row['message'] = 'บาร์โค้ดมีในระบบแล้ว ไม่แก้ไขของเดิม';
            } elseif ($type === 'products' && $data['barcode'] !== '' && ! $barcodePolicy->check($data['barcode_type'] ?: BarcodePolicy::CUSTOM, $data['barcode'])['ok']) {
                $row['action'] = 'error'; $row['message'] = 'ประเภทหรือรูปแบบบาร์โค้ดไม่ถูกต้อง';
            } elseif ($type === 'employees' && $data['branch_code'] !== '' && !isset($branchCodes[$data['branch_code']])) {
                $row['action'] = 'error'; $row['message'] = 'ไม่พบ branch_code';
            } else {
                $row['action'] = 'new'; $row['message'] = 'พร้อมเพิ่มใหม่';
            }
            $seen[$key] = true;
            $summary[$row['action']]++;
            if (count($summary['examples']) < 12) $summary['examples'][] = $row;
        }
        unset($row);

        return compact('rows', 'summary');
    }

    private function applyCategories(array $rows): int
    {
        $created = 0;
        foreach ($rows as $row) {
            if ($row['action'] !== 'new') continue;
            ProductCategory::firstOrCreate(['code' => $row['data']['category_code']], [
                'name_th' => $row['data']['category_name_th'], 'name_en' => $row['data']['category_name_en'] ?: null,
            ]);
            $created++;
        }
        return $created;
    }

    private function applyProducts(array $rows, ProductSkuAllocator $allocator, BarcodePolicy $policy): int
    {
        $created = 0;
        foreach ($rows as $row) {
            if ($row['action'] !== 'new') continue;
            $data = $row['data'];
            if (Product::withTrashed()->where('legacy_sku', $data['legacy_sku'])->exists()) continue;
            $category = ProductCategory::where('code', $data['category_code'])->lockForUpdate()->firstOrFail();
            $unit = ProductUnit::where('code', $data['unit_code'])->firstOrFail();
            $product = Product::create([
                'sku_code' => $allocator->nextForCategory($category->id), 'legacy_sku' => $data['legacy_sku'],
                'name_th' => $data['name_th'], 'product_category_id' => $category->id, 'base_unit_id' => $unit->id,
                'default_price' => $this->decimal($data['default_price']), 'average_cost' => $this->decimal($data['average_cost']),
                'is_vat' => $this->bool($data['is_vat'], true), 'is_active' => $this->bool($data['is_active'], true),
            ]);
            if ($data['barcode'] !== '') {
                $type = $data['barcode_type'] ?: BarcodePolicy::CUSTOM;
                $check = $policy->check($type, $data['barcode']);
                if (!$check['ok']) throw new \RuntimeException('บาร์โค้ดบรรทัด '.$row['line'].' ไม่ถูกต้อง: '.implode(', ', $check['errors']));
                ProductBarcode::create(['product_id' => $product->id, 'barcode' => $data['barcode'], 'barcode_type' => $type, 'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true]);
            }
            $created++;
        }
        return $created;
    }

    private function applyEmployees(array $rows): int
    {
        $created = 0;
        foreach ($rows as $row) {
            if ($row['action'] !== 'new') continue;
            $data = $row['data'];
            if (Employee::where('source_section', 'excel:'.$data['source_employee_code'])->exists()) continue;
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
            Employee::create([
                'employee_code' => $this->nextEmployeeCode(), 'full_name' => $data['full_name'], 'nickname' => $data['nickname'] ?: null,
                'phone' => $data['phone'] ?: null, 'branch_id' => $data['branch_code'] ? Branch::where('code', $data['branch_code'])->value('id') : null,
                'department' => $data['department'] ?: null, 'position' => $data['position'] ?: null,
                'status' => $data['status'] ?: 'Active', 'source_section' => 'excel:'.$data['source_employee_code'],
            ]);
            $created++;
        }
        return $created;
    }

    private function nextEmployeeCode(): string
    {
        $max = DB::table('employees')->where('employee_code', 'like', 'POP%')->pluck('employee_code')
            ->reduce(fn(int $max, string $code) => preg_match('/^POP(\d+)$/', $code, $m) ? max($max, (int) $m[1]) : $max, 0);
        return 'POP'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function readCsv(string $path): array
    {
        $rows = []; $handle = fopen($path, 'r');
        while (($row = fgetcsv($handle)) !== false) $rows[] = $row;
        fclose($handle); return $rows;
    }
    private function headers(array $row): array
    {
        $headers = [];
        foreach ($row as $i => $value) { $headers[trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))] = $i; }
        return $headers;
    }
    private function bool(string $value, bool $default): bool { return $value === '' ? $default : in_array(strtolower($value), ['1','true','yes','y'], true); }
    private function decimal(string $value): float { return (float) str_replace(',', '', $value ?: '0'); }
}
