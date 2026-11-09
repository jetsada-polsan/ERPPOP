<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MemberType;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductDepartment;
use App\Models\ProductUnit;
use App\Models\SalesArea;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * หน้าจัดการค่าคงที่ระบบ (ตั้งค่า -> ค่าคงที่):
 * หมวดสินค้า, แผนกสินค้า, ยี่ห้อสินค้า, หน่วยนับสินค้า, เขต/สายการขาย, ประเภทสมาชิก, สาขา, คลังสินค้า, พื้นที่เก็บสินค้า
 * รหัสแก้ไม่ได้หลังสร้าง เพราะเลขเอกสารและการนำเข้าข้อมูลอ้างรหัสอยู่
 * ลบผ่าน master-data.destroy ซึ่งหากถูกใช้งานอยู่จะเปลี่ยนเป็นปิดใช้งาน (is_active = false) แทนการลบจริง
 */
class BasicMasterController extends Controller
{
    public const TYPES = [
        'product-categories' => [ProductCategory::class, 'หมวดสินค้า', 'name_th'],
        'product-departments' => [ProductDepartment::class, 'แผนกสินค้า', 'name_th'],
        'product-brands' => [ProductBrand::class, 'ยี่ห้อสินค้า', 'name_th'],
        'product-units' => [ProductUnit::class, 'หน่วยนับสินค้า', 'name'],
        'sales-areas' => [SalesArea::class, 'เขต/สายการขาย', 'name'],
        'member-types' => [MemberType::class, 'ประเภทสมาชิก', 'name'],
        'branches' => [Branch::class, 'สาขา', 'name_th'],
        'warehouses' => [Warehouse::class, 'คลังสินค้า', 'name'],
        'warehouse-locations' => [WarehouseLocation::class, 'พื้นที่เก็บสินค้า', 'name'],
    ];

    public function index(Request $request, string $type): View
    {
        [$model, $label, $nameColumn] = $this->type($type);
        $q = trim((string) $request->query('q'));
        $rows = $model::query()
            ->when(in_array($type, ['warehouses', 'sales-areas'], true), fn ($query) => $query->with('branch'))
            ->when($type === 'warehouse-locations', fn ($query) => $query->with('warehouse'))
            ->when(in_array($type, ['product-categories', 'product-departments', 'product-brands', 'product-units'], true), fn ($query) => $query->withCount('products'))
            ->when($type === 'member-types', fn ($query) => $query->withCount('members'))
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('code', 'like', "%{$q}%")->orWhere($nameColumn, 'like', "%{$q}%")))
            ->orderBy('code')->paginate(50)->withQueryString();

        return view('basic-master.index', [
            'type' => $type,
            'label' => $label,
            'nameColumn' => $nameColumn,
            'rows' => $rows,
            'q' => $q,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
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
            'product-categories', 'product-departments', 'product-brands' => [
                'name_th' => 'required|string|max:150',
                'name_en' => 'nullable|string|max:150',
                'is_active' => 'nullable|boolean',
            ],
            'product-units' => [
                'name' => 'required|string|max:100',
                'qty_per_base_unit' => 'nullable|numeric|min:0.0001',
                'is_active' => 'nullable|boolean',
            ],
            'sales-areas' => [
                'name' => 'required|string|max:150',
                'area_type' => 'nullable|string|max:50',
                'branch_id' => 'nullable|exists:branches,id',
                'is_active' => 'nullable|boolean',
            ],
            'member-types' => [
                'name' => 'required|string|max:150',
                'point_rate' => 'nullable|numeric|min:0',
                'is_active' => 'nullable|boolean',
            ],
            'branches' => [
                'name_th' => 'required|string|max:150',
                'name_en' => 'nullable|string|max:150',
                'default_warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
                'negative_stock_policy' => 'required|in:allow,block',
                'is_active' => 'nullable|boolean',
            ],
            'warehouses' => [
                'name' => 'required|string|max:150',
                'branch_id' => 'nullable|exists:branches,id',
                'is_active' => 'nullable|boolean',
            ],
            'warehouse-locations' => [
                'name' => 'required|string|max:150',
                'warehouse_id' => 'required|exists:warehouses,id',
                'is_active' => 'nullable|boolean',
            ],
        };

        $data = $request->validate($code + $rules);

        if ($type === 'product-units') {
            $data['qty_per_base_unit'] = $data['qty_per_base_unit'] ?? ($row?->qty_per_base_unit ?? 1);
        }
        if ($type === 'member-types') {
            $data['point_rate'] = $data['point_rate'] ?? ($row?->point_rate ?? 1);
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        } elseif (! $row) {
            $data['is_active'] = true;
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
