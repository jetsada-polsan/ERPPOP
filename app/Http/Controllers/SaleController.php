<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\SaleBooking;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function show(Document $sale): View
    {
        $sale->load([
            'customer', 'branch', 'salesman', 'documentType',
            'stockDocument.items.product', 'openItem',
        ]);

        $booking = SaleBooking::where('confirmed_document_id', $sale->id)->first();
        $branches = Branch::orderBy('code')->get();

        return view('sales.show', ['sale' => $sale, 'booking' => $booking, 'branches' => $branches]);
    }

    public function print(Document $sale): View
    {
        $sale->load([
            'customer', 'branch', 'salesman', 'documentType',
            'stockDocument.items.product', 'openItem',
        ]);

        return view('sales.print', compact('sale'));
    }
}
