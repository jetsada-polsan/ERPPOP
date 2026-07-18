<?php

namespace App\Http\Controllers;

use App\Models\ProductUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductUnitController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $units = ProductUnit::withCount('products')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
            ))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('product-units.index', compact('units', 'q'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:product_units,code'],
            'name' => ['required', 'string', 'max:100'],
            'qty_per_base_unit' => ['required', 'numeric', 'min:0.0001'],
        ]);

        ProductUnit::create($data);

        return redirect()->route('product-units.index')->with('success', "เพิ่มหน่วยนับ {$data['name']} แล้ว");
    }

    public function update(Request $request, ProductUnit $productUnit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:product_units,code,'.$productUnit->id],
            'name' => ['required', 'string', 'max:100'],
            'qty_per_base_unit' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $productUnit->update($data);

        return redirect()->route('product-units.index')->with('success', 'บันทึกหน่วยนับแล้ว');
    }
}
