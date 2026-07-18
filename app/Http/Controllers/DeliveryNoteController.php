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

        // ราคาในระบบรวม VAT: ถอดฐานภาษีออกจากยอดรวม
        $vatRate = (float) (\Illuminate\Support\Facades\DB::table('vat_rates')
            ->where('effective_from', '<=', $document->doc_date->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $document->doc_date->toDateString()))
            ->orderByDesc('effective_from')
            ->value('rate_percent') ?? 7.0);

        $total = (float) $document->total_amount;
        $base = round($total * 100 / (100 + $vatRate), 2);

        // เครดิต/ครบกำหนด (ขายเชื่อ)
        $openItem = \App\Models\CustomerOpenItem::where('document_id', $document->id)->first();

        return view('documents.tax-invoice', [
            'document' => $document,
            'vatRate' => $vatRate,
            'baseAmount' => $base,
            'vatAmount' => round($total - $base, 2),
            'total' => $total,
            'totalText' => \App\Support\ThaiBaht::text($total),
            'dueDate' => $openItem?->due_date,
            'isCopy' => request()->boolean('copy'),
            'isCredit' => $document->documentType->code === 'CREDIT_SALE',
            'docTitle' => $document->documentType->code === 'SALE_RETURN' ? 'ใบรับคืนสินค้า / ใบลดหนี้' : 'ใบกำกับภาษี',
            'docSub' => match ($document->documentType->code) {
                'CREDIT_SALE' => 'ใบส่งของ / ใบแจ้งหนี้',
                'SALE_RETURN' => 'อ้างอิงใบกำกับภาษีเดิม',
                default => 'ใบเสร็จรับเงิน',
            },
        ]);
    }
}
