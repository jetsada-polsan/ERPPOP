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
            'stockDocument.items.product.baseUnit', 'stockDocument.items.product.barcodes', 'openItem', 'posReceipt.payments',
        ]);

        $booking = SaleBooking::where('confirmed_document_id', $sale->id)
            ->when($sale->source_document_id, fn ($query, $sourceId) => $query->orWhere('document_id', $sourceId))
            ->first();
        $branches = Branch::orderBy('code')->get();

        return view('sales.show', ['sale' => $sale, 'booking' => $booking, 'branches' => $branches]);
    }

    public function print(Document $sale): View
    {
        $sale->load([
            'customer', 'branch', 'salesman', 'documentType',
            'stockDocument.items.product.baseUnit', 'stockDocument.items.product.barcodes', 'openItem', 'posReceipt.payments',
        ]);

        return view('sales.print', compact('sale'));
    }
}
