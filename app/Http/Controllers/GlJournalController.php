<?php

namespace App\Http\Controllers;

use App\Models\GlJournal;
use Illuminate\View\View;

class GlJournalController extends Controller
{
    public function index(): View
    {
        $journals = GlJournal::with(['account', 'paymentDocument.document'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(50);

        $totalDebit = (float) GlJournal::sum('debit');
        $totalCredit = (float) GlJournal::sum('credit');

        return view('gl-journals.index', compact('journals', 'totalDebit', 'totalCredit'));
    }
}
