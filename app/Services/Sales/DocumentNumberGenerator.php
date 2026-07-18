<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentBook;

/**
 * Generates a human-readable doc_number per document type + branch + day, e.g.
 * "BK000120260630001" for the 1st booking at branch 0001 today. Not a legacy
 * BPlus numbering scheme - this is new, chosen to be simple and collision-free
 * (documents.doc_number is unique per branch_id).
 */
class DocumentNumberGenerator
{
    private const PREFIXES = [
        'BOOKING' => 'BK',
        'CREDIT_SALE' => 'DS',
        'CASH_SALE' => 'CS',
        'SALE_RETURN' => 'CN',
        'CREDIT_NOTE' => 'RN',
        'DEBIT_NOTE' => 'DN',
        'PURCHASE' => 'PO',
        'STOCK_TRANSFER' => 'TF',
        'STOCK_REQUISITION' => 'DR',
        'STOCK_REQUISITION_RETURN' => 'IR',
        'STOCK_DAMAGE' => 'DD',
        'STOCK_TRANSFORM' => 'DT',
        'PRODUCTION_RECEIPT' => 'IP',
        'STOCK_ADJUSTMENT' => 'AJ',
        'PAYMENT_VOUCHER' => 'PV',
        'RECEIPT' => 'RR',
        'QUOTATION' => 'QT',
    ];

    // เลขที่ตามสมุดเอกสาร (BPlus): ใช้ prefix ของเล่ม + นับเฉพาะเอกสารในเล่มนั้น
    public function nextInBook(DocumentBook $book, int $branchId): string
    {
        $book = DocumentBook::whereKey($book->id)->lockForUpdate()->firstOrFail();
        $branchCode = Branch::whereKey($branchId)->value('code') ?? (string) $branchId;
        $today = now()->format('Ymd');

        $countToday = Document::where('document_book_id', $book->id)
            ->where('branch_id', $branchId)
            ->whereDate('doc_date', now())
            ->count();

        return sprintf('%s%s%s%03d', $book->prefix, $branchCode, $today, $countToday + 1);
    }

    public function next(string $documentTypeCode, int $branchId): string
    {
        $prefix = self::PREFIXES[$documentTypeCode] ?? 'DC';
        $branchCode = Branch::whereKey($branchId)->value('code') ?? (string) $branchId;
        $today = now()->format('Ymd');

        $countToday = Document::whereHas('documentType', fn ($q) => $q->where('code', $documentTypeCode))
            ->where('branch_id', $branchId)
            ->whereDate('doc_date', now())
            ->count();

        return sprintf('%s%s%s%03d', $prefix, $branchCode, $today, $countToday + 1);
    }

    // Stock-count sheets (ใบเตรียมตรวจนับ) live in their own table, not
    // documents, so they get their own daily running number: SC<branch><date><seq>.
    public function nextStockCount(int $branchId): string
    {
        $branchCode = Branch::whereKey($branchId)->value('code') ?? (string) $branchId;
        $today = now()->format('Ymd');

        $countToday = \App\Models\StockCount::where('branch_id', $branchId)
            ->whereDate('created_at', now())
            ->count();

        return sprintf('SC%s%s%03d', $branchCode, $today, $countToday + 1);
    }

    // ใบวางบิล (Billing Note) เก็บในตารางของตัวเอง: BL<branch><date><seq>.
    public function nextBillingNote(int $branchId): string
    {
        $branchCode = Branch::whereKey($branchId)->value('code') ?? (string) $branchId;
        $today = now()->format('Ymd');

        $countToday = \App\Models\BillingNote::where('branch_id', $branchId)
            ->whereDate('created_at', now())
            ->count();

        return sprintf('BL%s%s%03d', $branchCode, $today, $countToday + 1);
    }

    // ใบขอซื้อ/ใบสั่งซื้อ (Purchase Order) เก็บในตารางของตัวเอง: PR<branch><date><seq>.
    public function nextPurchaseOrder(int $branchId): string
    {
        $branchCode = Branch::whereKey($branchId)->value('code') ?? (string) $branchId;
        $today = now()->format('Ymd');

        $countToday = \App\Models\PurchaseOrder::where('branch_id', $branchId)
            ->whereDate('created_at', now())
            ->count();

        return sprintf('PR%s%s%03d', $branchCode, $today, $countToday + 1);
    }

    // ใบเสนอราคา (Quotation) เก็บในตารางของตัวเอง: QT<branch><date><seq>.
    public function nextQuotation(int $branchId): string
    {
        $branchCode = Branch::whereKey($branchId)->value('code') ?? (string) $branchId;
        $today = now()->format('Ymd');

        $countToday = \App\Models\Quotation::where('branch_id', $branchId)
            ->whereDate('created_at', now())
            ->count();

        return sprintf('QT%s%s%03d', $branchCode, $today, $countToday + 1);
    }
}
