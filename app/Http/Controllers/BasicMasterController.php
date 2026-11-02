<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ทะเบียนข้อมูลหลักแบบง่าย: หมวดสินค้า สาขา และคลังสินค้า
 * รหัสแก้ไม่ได้หลังสร้าง เพราะเลขเอกสารและการนำเข้าข้อมูลอ้างรหัสอยู่
 * ลบผ่าน master-data.destroy ซึ่งไม่ยอมลบถ้ามีประวัติ สาขาที่มีประวัติให้ปิดใช้งานแทน
 */
class BasicMasterController extends Controller
{
    private const TYPES = [
        'product-categories' => [ProductCategory::class, 'หมวดสินค้า', 'name_th'],
        'branches' => [Branch::class, 'สาขา', 'name_th'],
        'warehouses' => [Warehouse::class, 'คลังสินค้า', 'name'],
    ];

    public function index(Request $request, string $type): View
    {
        [$model, $label, $nameColumn] = $this->type($type);
        $q = trim((string) $request->query('q'));
        $rows = $model::query()
            ->when($type === 'warehouses', fn ($query) => $query->with('branch'))
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('code', 'like', "%{$q}%")->orWhere($nameColumn, 'like', "%{$q}%")))
            ->orderBy('code')->paginate(50)->withQueryString();

        return view('basic-master.index', [
            'type' => $type, 'label' => $label, 'rows' => $rows, 'q' => $q,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'locations' => WarehouseLocation::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        [$model, $label] = $this->type($type);
        $data = $this->validated($request, $type);
        $row = $model::create($data);
        $this->audit($request, $type, 'master_created', $row, [], $data);

        return back()->with('success', "เพิ่ม{$label} {$row->code} แล้ว");
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        [$model, $label] = $this->type($type);
        $row = $model::findOrFail($id);
        $data = $this->validated($request, $type, $row);
        $old = $row->only(array_keys($data));
        $row->update($data);
        $this->audit($request, $type, 'master_updated', $row, $old, $data);

        return back()->with('success', "บันทึก{$label} {$row->code} แล้ว");
    }

    /** @return array{0: class-string<Model>, 1: string, 2: string} */
    private function type(string $type): array
    {
        return self::TYPES[$type] ?? abort(404);
    }

    private function validated(Request $request, string $type, ?Model $row = null): array
    {
        $table = (new (self::TYPES[$type][0]))->getTable();
        $code = $row ? [] : ['code' => ['required', 'string', 'max:'.($type === 'branches' ? 10 : 20), Rule::unique($table, 'code')]];

        $rules = match ($type) {
            'product-categories' => ['name_th' => 'required|string|max:150', 'name_en' => 'nullable|string|max:150'],
            'branches' => [
                'name_th' => 'required|string|max:150', 'name_en' => 'nullable|string|max:150',
                'default_warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
                'negative_stock_policy' => 'required|in:allow,block',
                'is_active' => 'boolean',
            ],
            'warehouses' => ['name' => 'required|string|max:150', 'branch_id' => 'nullable|exists:branches,id'],
        };
        $data = $request->validate($code + $rules);
        if ($type === 'branches') {
            $data['is_active'] = $request->boolean('is_active');
        }

        return $data;
    }

    private function audit(Request $request, string $type, string $action, Model $row, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id, 'action' => $action, 'table_name' => $row->getTable(),
            'record_id' => $row->getKey(), 'old_values' => $old ?: null, 'new_values' => $new,
        ]);
    }
}
