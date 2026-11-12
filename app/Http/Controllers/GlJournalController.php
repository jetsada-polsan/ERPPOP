<?php

namespace App\Http\Controllers;

use App\Models\GlJournal;
use App\Support\ListDateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GlJournalController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $dateRange = ListDateRange::fromRequest($request, 'entry_date');

        $baseQuery = GlJournal::query()
            ->tap(fn ($query) => $dateRange->apply($query));

        if ($q !== '') {
            $baseQuery->where(fn ($w) => $w
                ->where('remark', 'ilike', "%{$q}%")
                ->orWhereHas('account', fn ($a) => $a->where('code', 'ilike', "%{$q}%")->orWhere('name_th', 'ilike', "%{$q}%"))
            );
        }

        $journals = (clone $baseQuery)
            ->with(['account', 'paymentDocument.document'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $totalDebit = (float) (clone $baseQuery)->sum('debit');
        $totalCredit = (float) (clone $baseQuery)->sum('credit');

        return view('gl-journals.index', compact('journals', 'totalDebit', 'totalCredit', 'dateRange', 'q'));
    }
}
