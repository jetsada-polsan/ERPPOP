<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Support\VoidedDocumentPrint;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

    public function show(Document $document): Response|View
    {
        if (VoidedDocumentPrint::isVoided($document)) {
            return VoidedDocumentPrint::deny($document);
        }

        $document->load([
            'documentType', 'branch', 'salesman',
            'customer.addresses',
            'stockDocument.items.product.baseUnit',
            'stockDocument.items.product.barcodes.unit',
            'stockDocument.items.unit',
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

    public function batch(Request $request): Response|View
    {
        $user = $request->user();

        // 1. สาขาที่ผู้ใช้เข้าถึงได้เท่านั้น
        $allowedBranchIds = null;
        if ($user) {
            if ($user->branchRoles()->exists()) {
                $allowedBranchIds = $user->branchRoles()
                    ->wherePivot('is_active', true)
                    ->where(fn ($roles) => $roles
                        ->whereNull('user_branch_roles.effective_from')
                        ->orWhere('user_branch_roles.effective_from', '<=', now()))
                    ->where(fn ($roles) => $roles
                        ->whereNull('user_branch_roles.effective_to')
                        ->orWhere('user_branch_roles.effective_to', '>', now()))
                    ->pluck('user_branch_roles.branch_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            } elseif ($user->branch_id !== null) {
                $allowedBranchIds = [(int) $user->branch_id];
            }
        }

        $rawIds = $request->input('ids');
        if (is_string($rawIds)) {
            $rawIds = explode(',', $rawIds);
        }
        $ids = is_array($rawIds) ? array_values(array_filter(array_map('trim', $rawIds))) : [];

        $query = Document::query();

        // กรองสิทธิ์สาขา
        if ($allowedBranchIds !== null) {
            $query->whereIn('branch_id', $allowedBranchIds);
        }

        if (! empty($ids)) {
            // ใบส่งของมีเฉพาะใบจองและใบขาย เลขเอกสารชนิดอื่น (ใบซื้อ ใบเสร็จ) ต้องไม่หลุดมาพิมพ์
            $query->whereHas('documentType', fn ($q) => $q->whereIn('code', array_keys(self::TITLES)));
            $query->where(function ($q) use ($ids) {
                $numericIds = array_filter($ids, fn ($v) => ctype_digit((string) $v));
                if (! empty($numericIds)) {
                    $q->whereIn('id', $numericIds)->orWhereIn('doc_number', $ids);
                } else {
                    $q->whereIn('doc_number', $ids);
                }
            });
        } else {
            $requestedBranchId = $request->integer('branch_id');
            if ($requestedBranchId) {
                if ($allowedBranchIds !== null && ! in_array($requestedBranchId, $allowedBranchIds, true)) {
                    abort(403, 'ไม่มีสิทธิ์เข้าถึงสาขานี้');
                }
                $query->where('branch_id', $requestedBranchId);
            }

            $date = $request->query('date') ?: now()->toDateString();
            $query->whereDate('doc_date', $date);

            $salesAreaId = $request->integer('sales_area_id');
            $type = strtolower((string) $request->query('type', ''));
            if ($type === 'booking') {
                // เกณฑ์เดียวกับหน้าใบรวมจองและใบปะหน้า: ใช้ BookingController::pendingBookingsQuery() ร่วมกัน
                // เอกสารที่ถูกยกเลิก/voided จะไม่ถูกนำมาพิมพ์
                $bookingDocIds = BookingController::pendingBookingsQuery(
                    $date,
                    $requestedBranchId ?: null,
                    $salesAreaId ?: null
                )->select('sale_bookings.document_id');

                $query->whereIn('id', $bookingDocIds);
                $salesAreaId = 0;
            }
            if ($salesAreaId) {
                $query->where('sales_area_id', $salesAreaId);
            }

            if ($type === 'booking') {
                // กรองแล้วด้านบน
            } elseif ($type === 'sale') {
                $query->whereHas('documentType', fn ($q) => $q->whereIn('code', ['CASH_SALE', 'CREDIT_SALE']));
            } else {
                $query->whereHas('documentType', fn ($q) => $q->whereIn('code', ['BOOKING', 'CASH_SALE', 'CREDIT_SALE']));
            }
        }

        if (count($ids) > 150) {
            return response()->view('documents.batch-limit-exceeded', [
                'count' => count($ids),
                'limit' => 150,
                'message' => 'จำกัดการพิมพ์ไม่เกิน 150 ใบต่อครั้ง (พบ '.count($ids).' ใบ) กรุณาแบ่งตัวกรองใหม่เพื่อลดจำนวนเอกสาร เช่น กรองตามสายการขายหรือช่วงวันที่สั้นลง',
            ], 422);
        }

        // จำกัดไม่เกิน 150 ใบต่อครั้ง
        $count = (clone $query)->count();
        if ($count > 150) {
            return response()->view('documents.batch-limit-exceeded', [
                'count' => $count,
                'limit' => 150,
                'message' => "จำกัดการพิมพ์ไม่เกิน 150 ใบต่อครั้ง (พบ {$count} ใบ) กรุณาแบ่งตัวกรองใหม่เพื่อลดจำนวนเอกสาร เช่น กรองตามสายการขายหรือช่วงวันที่สั้นลง",
            ], 422);
        }

        // โหลดข้อมูลครั้งเดียวแบบ eager load (relations เดียวกับ show()) ห้าม query ต่อใบ
        $documents = $query->with([
            'documentType',
            'branch',
            'salesman',
            'salesArea',
            'customer.addresses',
            'stockDocument.items.product.baseUnit',
            'stockDocument.items.product.barcodes.unit',
            'stockDocument.items.unit',
            'saleBooking.salesArea',
            'paymentDocument',
        ])->get();

        // ป้องกัน N+1 ใน VoidedDocumentPrint::isVoided()
        foreach ($documents as $doc) {
            if ($doc->relationLoaded('saleBooking')) {
                $doc->setRelation('booking', $doc->saleBooking);
            }
        }

        // เรียงตามสายขาย → ชื่อลูกค้า → เลขที่เอกสาร
        $collator = extension_loaded('intl') ? new \Collator('th_TH') : null;
        $documents = $documents->sort(function (Document $a, Document $b) use ($collator) {
            $areaA = $a->salesArea ? ($a->salesArea->code ?: $a->salesArea->name) : ($a->saleBooking?->salesArea ? ($a->saleBooking->salesArea->code ?: $a->saleBooking->salesArea->name) : 'ZZZ');
            $areaB = $b->salesArea ? ($b->salesArea->code ?: $b->salesArea->name) : ($b->saleBooking?->salesArea ? ($b->saleBooking->salesArea->code ?: $b->saleBooking->salesArea->name) : 'ZZZ');
            $cmpArea = strcmp((string) $areaA, (string) $areaB);
            if ($cmpArea !== 0) {
                return $cmpArea;
            }

            $custA = $a->customer?->name_th ?? '';
            $custB = $b->customer?->name_th ?? '';
            $cmpCust = $collator ? $collator->compare($custA, $custB) : strcmp($custA, $custB);
            if ($cmpCust !== 0) {
                return $cmpCust;
            }

            return strcmp((string) $a->doc_number, (string) $b->doc_number);
        })->values();

        // เอกสารที่ยกเลิกแล้ว/ไม่มีรายการสินค้า ให้ข้ามและแสดงสรุปด้านบนหน้า "ข้าม N ใบ: เลขที่…"
        $printable = [];
        $skipped = [];

        foreach ($documents as $doc) {
            if (VoidedDocumentPrint::isVoided($doc)) {
                $skipped[] = [
                    'doc_number' => $doc->doc_number,
                    'reason' => 'ยกเลิกแล้ว',
                ];
                continue;
            }

            if ($doc->stockDocument === null || $doc->stockDocument->items->isEmpty()) {
                $skipped[] = [
                    'doc_number' => $doc->doc_number,
                    'reason' => 'ไม่มีรายการสินค้า',
                ];
                continue;
            }

            $typeCode = $doc->documentType?->code ?? 'OTHER';
            $title = self::TITLES[$typeCode] ?? 'ใบส่งของ';
            $paymentLabel = self::PAYMENT_LABELS[$typeCode] ?? '-';
            $copies = ($typeCode === 'BOOKING') ? ['สำหรับพนักงาน (นำกลับ)', 'สำหรับลูกค้า'] : [null];

            $printable[] = [
                'document' => $doc,
                'title' => $title,
                'typeCode' => $typeCode,
                'paymentLabel' => $paymentLabel,
                'copies' => $copies,
            ];
        }

        $totalPages = array_sum(array_map(fn ($p) => count($p['copies']), $printable));

        return view('documents.batch-delivery-notes', [
            'printable' => $printable,
            'skipped' => $skipped,
            'totalPages' => $totalPages,
        ]);
    }

    // ใบกำกับภาษีเต็มรูปแบบ A4 (ขายสด/ขายเชื่อ) - ?copy=1 พิมพ์ฉบับสำเนา
    public function taxInvoice(Document $document): Response|View
    {
        if (VoidedDocumentPrint::isVoided($document)) {
            return VoidedDocumentPrint::deny($document);
        }

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
