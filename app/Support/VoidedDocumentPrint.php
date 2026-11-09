<?php

namespace App\Support;

use App\Models\Document;
use App\Models\SaleBooking;
use Symfony\Component\HttpFoundation\Response;

/**
 * เอกสารที่ยกเลิกแล้วห้ามพิมพ์ใบเสร็จ/ใบกำกับ/ใบส่งของซ้ำ
 *
 * ซ่อนปุ่มอย่างเดียวไม่พอ เพราะ URL พิมพ์เปิดตรงได้ จึงกันที่ controller ทุกจุดที่เปิดหน้าพิมพ์
 * สถานะยกเลิกใช้ชุดเดียวกับ DocumentVoidService::blockers() (cancelled / void)
 * ใบจองที่ยกเลิกถูกตั้ง documents.status = cancelled โดย BookingService::cancel() จึงครอบคลุมด้วย
 */
final class VoidedDocumentPrint
{
    public const STATUSES = ['cancelled', 'void'];

    public static function isVoided(?Document $document): bool
    {
        if ($document === null) {
            return false;
        }
        if (in_array($document->status, self::STATUSES, true)) {
            return true;
        }
        if ($document->paymentDocument && in_array($document->paymentDocument->status, self::STATUSES, true)) {
            return true;
        }
        if ($document->documentType?->code === 'BOOKING' || $document->booking) {
            $booking = $document->booking ?? SaleBooking::where('document_id', $document->id)->first();
            if ($booking && in_array($booking->status, ['cancelled', 'void'], true)) {
                return true;
            }
        }

        return false;
    }

    /** หน้าแจ้ง "ถูกยกเลิกแล้ว พิมพ์ไม่ได้" (HTTP 410) พร้อมลิงก์กลับไปหน้าเอกสาร */
    public static function deny(Document $document): Response
    {
        return response()->view('documents.voided-print', [
            'docNumber' => $document->doc_number,
            'backUrl' => self::documentUrl($document),
        ], 410);
    }

    private static function documentUrl(Document $document): string
    {
        $document->loadMissing('documentType');

        return match ($document->documentType?->code) {
            'RECEIPT', 'PAYMENT_VOUCHER' => route('payments.show', $document),
            'CREDIT_SALE' => route('sales.show', $document),
            'CASH_SALE' => route('cash-sales.show', $document),
            'SALE_RETURN' => route('sale-returns.show', $document),
            'CREDIT_NOTE', 'DEBIT_NOTE' => route('credit-debit-notes.index'),
            'BOOKING' => ($booking = SaleBooking::where('document_id', $document->id)->first())
                ? route('bookings.show', $booking)
                : route('bookings.index'),
            default => url()->previous(),
        };
    }
}
