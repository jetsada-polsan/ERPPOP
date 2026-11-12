<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\SaleBooking;
use App\Support\VoidedDocumentPrint;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SaleController extends Controller
{
    public function show(Document $sale): View
    {
        $sale->load([
            'customer', 'branch', 'salesman', 'documentType',
            'stockDocument.items.product.baseUnit', 'stockDocument.items.product.barcodes.unit', 'stockDocument.items.unit', 'openItem', 'posReceipt.payments',
        ]);

        $booking = SaleBooking::where('confirmed_document_id', $sale->id)->orWhere('document_id', $sale->source_document_id ?? 0)->first();
        $branches = Branch::orderBy('code')->get();

        return view('sales.show', ['sale' => $sale, 'booking' => $booking, 'branches' => $branches]);
    }

    public function print(Document $sale): Response|View
    {
        if (VoidedDocumentPrint::isVoided($sale)) {
            return VoidedDocumentPrint::deny($sale);
        }

        $sale->load([
            'customer', 'branch', 'salesman', 'documentType',
            'stockDocument.items.product.baseUnit', 'stockDocument.items.product.barcodes.unit', 'stockDocument.items.unit', 'openItem', 'posReceipt.payments',
        ]);

        return view('sales.print', compact('sale'));
    }
}
