<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarehouseLocationController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $locations = WarehouseLocation::with('warehouse')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
            ))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        $warehouses = Warehouse::orderBy('code')->get();

        return view('warehouse-locations.index', compact('locations', 'warehouses', 'q'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'code' => ['required', 'string', 'max:20', 'unique:warehouse_locations,code'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        WarehouseLocation::create($data);

        return redirect()->route('warehouse-locations.index')->with('success', "เพิ่มคลัง {$data['name']} แล้ว");
    }

    public function update(Request $request, WarehouseLocation $warehouseLocation): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'code' => ['required', 'string', 'max:20', 'unique:warehouse_locations,code,'.$warehouseLocation->id],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $warehouseLocation->update($data);

        return redirect()->route('warehouse-locations.index')->with('success', 'บันทึกข้อมูลคลังแล้ว');
    }
}
