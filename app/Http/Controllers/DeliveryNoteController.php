<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\View\View;

/**
 * ใบส่งของ / ใบส่งของชั่วคราว (A5): printable delivery slip for the load-out
 * step of the basic flow จอง -> ขึ้นของ -> คีย์ขาย -> ส่งของ -> ลงของ.
 * Works for any document that carries stock items (booking, cash/credit sale).
 */
class DeliveryNoteController extends Controller
{
    private const TITLES = [
        'BOOKING' => 'ใบส่งของชั่วคราว',
        'CASH_SALE' => 'ใบส่งของ / ใบกำกับสินค้า',
        'CREDIT_SALE' => 'ใบส่งของ / ใบกำกับสินค้า',
    ];

    private const PAYMENT_LABELS = [
        'BOOKING' => 'รอแปลงเป็นใบขาย',
        'CASH_SALE' => 'เงินสด',
        'CREDIT_SALE' => 'เงินเชื่อ',
    ];

    public function show(Document $document): View
    {
        $document->load([
            'documentType', 'branch', 'salesman',
            'customer.addresses',
            'stockDocument.items.product.baseUnit',
            'stockDocument.items.product.barcodes',
        ]);

        abort_unless($document->stockDocument !== null, 404, 'เอกสารนี้ไม่มีรายการสินค้า');

        $typeCode = $document->documentType->code;

        return view('delivery-note', [
            'document' => $document,
            'title' => self::TITLES[$typeCode] ?? 'ใบส่งของ',
            'typeCode' => $typeCode,
            'paymentLabel' => self::PAYMENT_LABELS[$typeCode] ?? '-',
        ]);
    }

    // ใบกำกับภาษีเต็มรูปแบบ A4 (ขายสด/ขายเชื่อ) - ?copy=1 พิมพ์ฉบับสำเนา
    public function taxInvoice(Document $document): View
    {
        $document->load([
            'documentType', 'branch', 'salesman',
            'customer.addresses',
            'stockDocument.items.product.baseUnit',
        ]);

        abort_unless(in_array($document->documentType->code, ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'], true), 404, 'ออกใบกำกับภาษีได้เฉพาะใบขายสด/ขายเชื่อ/รับคืน');
        abort_unless($document->stockDocument !== null, 404, 'เอกสารนี้ไม่มีรายการสินค้า');

        $tax = $document->trade_tax;
        $noVat = $tax ? $tax['vat_mode'] === 'none' : (bool) ($document->documentBook?->no_vat
            || ($document->documentType->code === 'SALE_RETURN' && $document->sourceDocument?->documentBook?->no_vat));
        $vatRate = $noVat ? 0 : (float) ($tax['vat_rate'] ?? \App\Models\TradeAgreement::standardRate($document->doc_date->toDateString()));
        $total = (float) $document->total_amount;
        $base = (float) ($tax['taxable_base'] ?? ($noVat ? 0 : $document->subtotal_amount ?? round($total * 100 / (100 + $vatRate), 2)));
        $exempt = (float) ($tax['exempt_amount'] ?? ($noVat ? $total : 0));
        $vat = $noVat ? 0 : (float) ($document->subtotal_amount !== null ? $document->vat_amount : round($total - $base - $exempt, 2));

        // เครดิต/ครบกำหนด (ขายเชื่อ)
        $openItem = \App\Models\CustomerOpenItem::where('document_id', $document->id)->first();

        return view('documents.tax-invoice', [
            'document' => $document,
            'vatRate' => $vatRate,
            'baseAmount' => $base,
            'vatAmount' => $vat,
            'exemptAmount' => $exempt,
            'total' => $total,
            'totalText' => \App\Support\ThaiBaht::text($total),
            'dueDate' => $openItem?->due_date,
            'isCopy' => request()->boolean('copy'),
            'isCredit' => $document->documentType->code === 'CREDIT_SALE',
            'docTitle' => $document->documentType->code === 'SALE_RETURN' ? 'ใบรับคืนสินค้า / ใบลดหนี้' : ($noVat ? 'ใบแจ้งหนี้ / ใบส่งของ' : 'ใบกำกับภาษี'),
            'docSub' => match ($document->documentType->code) {
                'CREDIT_SALE' => $noVat ? '' : 'ใบส่งของ / ใบแจ้งหนี้',
                'SALE_RETURN' => 'อ้างอิงใบกำกับภาษีเดิม',
                default => 'ใบเสร็จรับเงิน',
            },
        ]);
    }
}
