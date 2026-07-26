<?php

namespace App\Http\Controllers;

use App\Services\DatabaseStructureService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatabaseStructureController extends Controller
{
    public function index(Request $request, DatabaseStructureService $service): View
    {
        $catalog = $service->catalog();
        $requested = (string) $request->query('table', '');
        $selectedName = isset($catalog['tables'][$requested])
            ? $requested
            : (isset($catalog['tables']['products']) ? 'products' : array_key_first($catalog['tables']));
        $selected = $selectedName ? $catalog['tables'][$selectedName] : null;

        return view('database-structure.index', [
            ...$catalog,
            'selected' => $selected,
            'rowEstimate' => $selectedName ? $service->rowEstimate($selectedName) : null,
        ]);
    }
}
