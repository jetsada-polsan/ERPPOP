<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ศูนย์เอกสารย้อนหลังของ ERP: ต้นไม้ ประเภทเอกสาร -> ปี -> เดือน
 * ฝั่งซ้าย + ตารางเอกสารกรองได้รายวัน/ช่วงวันที่/ค้นหา ฝั่งขวา ครอบคลุมเอกสาร
 * ทุกชนิดในตาราง documents และลิงก์ไปหน้ารายละเอียดของแต่ละประเภท
 */
class DocumentBrowserController extends Controller
{
    private const DETAIL_ROUTES = [
        'CREDIT_SALE' => 'sales.show',
        'CASH_SALE' => 'cash-sales.show',
        'SALE_RETURN' => 'sale-returns.show',
        'PURCHASE' => 'purchases.show',
        'STOCK_TRANSFER' => 'stock-transfers.show',
        'STOCK_ADJUSTMENT' => 'stock-adjustments.show',
        'STOCK_REQUISITION' => 'stock-issues.show',
        'STOCK_REQUISITION_RETURN' => 'stock-issues.show',
        'STOCK_DAMAGE' => 'stock-issues.show',
        'STOCK_TRANSFORM' => 'stock-transforms.show',
        'PRODUCTION_RECEIPT' => 'stock-issues.show',
        'PAYMENT_VOUCHER' => 'payments.show',
        'RECEIPT' => 'payments.show',
    ];

    public function index(Request $request): View
    {
        // ต้นไม้: ประเภท -> ปี -> เดือน พร้อมจำนวนใบ (query เดียว)
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $yearExpression = $sqlite
            ? "CAST(strftime('%Y', doc_date) AS INTEGER)"
            : 'EXTRACT(YEAR FROM doc_date)::int';
        $monthExpression = $sqlite
            ? "CAST(strftime('%m', doc_date) AS INTEGER)"
            : 'EXTRACT(MONTH FROM doc_date)::int';
        $treeRows = Document::selectRaw(
            "document_type_id,
             {$yearExpression} AS y,
             {$monthExpression} AS m,
             COUNT(*) AS c"
        )->groupByRaw("document_type_id, {$yearExpression}, {$monthExpression}")->get();

        $tree = [];
        foreach ($treeRows as $row) {
            $tree[$row->document_type_id][$row->y][$row->m] = (int) $row->c;
        }
        foreach ($tree as &$years) {
            krsort($years);
            foreach ($years as &$months) {
                ksort($months);
            }
        }
        unset($years, $months);

        $types = DocumentType::orderBy('id')->get();

        $typeCode = $request->query('type');
        $bookId = $request->integer('book') ?: null;
        $year = $request->integer('year') ?: null;
        $month = $request->integer('month') ?: null;
        $from = $request->query('from');
        $to = $request->query('to');
        $day = $request->query('day');
        $q = trim((string) $request->query('q', ''));
        // ไม่ระบุตัวกรองเลย = เช็ครายวันของวันนี้ (use case หลัก)
        if (! $typeCode && ! $bookId && ! $year && ! $from && ! $to && ! $day && $q === '') {
            $day = now()->toDateString();
        }

        $base = Document::query()
            ->when($bookId, fn ($query) => $query->where('document_book_id', $bookId))
            ->when($typeCode, fn ($query) => $query->whereHas('documentType', fn ($t) => $t->where('code', $typeCode)))
            ->when($day, fn ($query) => $query->whereDate('doc_date', $day))
            ->when(! $day && $year, fn ($query) => $query->whereYear('doc_date', $year))
            ->when(! $day && $year && $month, fn ($query) => $query->whereMonth('doc_date', $month))
            ->when(! $day && ! $year && $from, fn ($query) => $query->whereDate('doc_date', '>=', $from))
            ->when(! $day && ! $year && $to, fn ($query) => $query->whereDate('doc_date', '<=', $to))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhere('reference', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%"))
                ->orWhereHas('supplier', fn ($s) => $s->where('name_th', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%"))
            ));

        $summary = [
            'count' => (clone $base)->count(),
            'amount' => (float) (clone $base)->sum('total_amount'),
        ];

        $documents = $base->with(['documentType', 'documentBook', 'branch', 'customer', 'supplier', 'saleBooking'])
            ->orderByDesc('doc_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        // เล่มเอกสาร (มากกว่า 1 เล่มต่อประเภท) สำหรับกรองในทะเบียน
        $booksByType = DocumentBook::where('is_active', true)
            ->orderByDesc('is_default')->orderBy('code')->get()->groupBy('document_type_id');

        return view('documents.browser', [
            'tree' => $tree,
            'types' => $types,
            'documents' => $documents,
            'summary' => $summary,
            'booksByType' => $booksByType,
            'filters' => [
                'type' => $typeCode, 'book' => $bookId, 'year' => $year, 'month' => $month,
                'from' => $from, 'to' => $to, 'day' => $day, 'q' => $q,
            ],
        ]);
    }

    public static function detailUrl(Document $document): ?string
    {
        $code = $document->documentType->code;

        if ($code === 'BOOKING') {
            return $document->saleBooking ? route('bookings.show', $document->saleBooking->id) : null;
        }

        $routeName = self::DETAIL_ROUTES[$code] ?? null;

        return $routeName ? route($routeName, $document->id) : null;
    }
}
