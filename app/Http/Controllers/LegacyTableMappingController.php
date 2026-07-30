<?php

namespace App\Http\Controllers;

use App\Models\LegacyTableMapping;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LegacyTableMappingController extends Controller
{
    public function index(Request $request): View
    {
        $query = LegacyTableMapping::query();
        $status = trim((string) $request->query('status', ''));
        $module = trim((string) $request->query('module', ''));
        $search = trim((string) $request->query('q', ''));

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($module !== '') {
            $query->where('module', $module);
        }
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('legacy_table', 'ilike', "%{$search}%")
                    ->orWhere('target_table', 'ilike', "%{$search}%");
            });
        }

        return view('legacy-mappings.index', [
            'mappings' => $query->orderByRaw("case when status = 'needs_review' then 0 else 1 end")->orderBy('legacy_table')->paginate(50)->withQueryString(),
            'summary' => [
                'total' => LegacyTableMapping::count(),
                'mapped' => LegacyTableMapping::where('status', 'mapped')->count(),
                'needs_review' => LegacyTableMapping::where('status', 'needs_review')->count(),
                'excluded' => LegacyTableMapping::where('status', 'excluded')->count(),
            ],
            'modules' => LegacyTableMapping::query()->select('module')->distinct()->orderBy('module')->pluck('module'),
            'filters' => compact('status', 'module', 'search'),
        ]);
    }
}
