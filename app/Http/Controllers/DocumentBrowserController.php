<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ศูนย์เอกสารย้อนหลัง (ตามหน้าจอ BPlus): ต้นไม้ ประเภทเอกสาร -> ปี -> เดือน
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
        $treeRows = Document::selectRaw(
            'document_type_id,
             EXTRACT(YEAR FROM doc_date)::int AS y,
             EXTRACT(MONTH FROM doc_date)::int AS m,
             COUNT(*) AS c'
        )->groupBy('document_type_id', 'y', 'm')->get();

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
        $legacyType = trim((string) $request->query('legacy_type', ''));

        // ไม่ระบุตัวกรองเลย = เช็ครายวันของวันนี้ (use case หลัก)
        if (! $typeCode && ! $bookId && ! $year && ! $from && ! $to && ! $day && $q === '' && $legacyType === '') {
            $day = now()->toDateString();
        }

        $base = Document::query()
            ->when($bookId, fn ($query) => $query->where('document_book_id', $bookId))
            ->when($typeCode, fn ($query) => $query->whereHas('documentType', fn ($t) => $t->where('code', $typeCode)))
            ->when($day, fn ($query) => $query->whereDate('doc_date', $day))
            ->when(! $day && $year, fn ($query) => $query->whereRaw('EXTRACT(YEAR FROM doc_date) = ?', [$year]))
            ->when(! $day && $year && $month, fn ($query) => $query->whereRaw('EXTRACT(MONTH FROM doc_date) = ?', [$month]))
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
        $booksByType = \App\Models\DocumentBook::where('is_active', true)
            ->orderByDesc('is_default')->orderBy('code')->get()->groupBy('document_type_id');

        $legacyDocuments = null;
        $legacySummary = ['count' => 0, 'amount' => 0.0];
        if ($this->legacyDocumentsReady()) {
            $legacyBase = $this->legacyDocumentQuery($day, $year, $month, $from, $to, $q, $legacyType);
            $legacySummary = [
                'count' => (clone $legacyBase)->count(),
                'amount' => (float) (clone $legacyBase)->sum(DB::raw('NULLIF(di."DI_AMOUNT", \'\')::numeric')),
            ];
            $legacyDocuments = $legacyBase
                ->orderByRaw('di."DI_DATE" desc')
                ->orderByRaw('di."DI_KEY" desc')
                ->paginate(50, ['*'], 'legacy_page')
                ->withQueryString();
        }

        return view('documents.browser', [
            'tree' => $tree,
            'types' => $types,
            'documents' => $documents,
            'summary' => $summary,
            'legacyDocuments' => $legacyDocuments,
            'legacySummary' => $legacySummary,
            'booksByType' => $booksByType,
            'filters' => [
                'type' => $typeCode, 'book' => $bookId, 'year' => $year, 'month' => $month,
                'from' => $from, 'to' => $to, 'day' => $day, 'q' => $q, 'legacy_type' => $legacyType,
            ],
        ]);
    }

    public function legacyShow(string $diKey): View
    {
        abort_unless($this->legacyDocumentsReady(), 404);

        $header = $this->legacyDocumentQuery(null, null, null, null, null, '')
            ->whereRaw('di."DI_KEY" = ?', [$diKey])
            ->first();

        abort_if($header === null, 404);

        $items = DB::table(DB::raw('legacy.dbo__transtkd as trd'))
            ->join(DB::raw('legacy.dbo__transtkh as trh'), DB::raw('trh."TRH_KEY"'), '=', DB::raw('trd."TRD_TRH"'))
            ->leftJoin(DB::raw('legacy.dbo__skumaster as sm'), DB::raw('sm."SKU_KEY"'), '=', DB::raw('trd."TRD_SKU"'))
            ->whereRaw('trh."TRH_DI" = ?', [$diKey])
            ->selectRaw('
                trd."TRD_SEQ"::int as seq,
                trd."TRD_KEYIN" as barcode,
                trd."TRD_SKU" as legacy_sku_key,
                sm."SKU_CODE" as sku_code,
                sm."SKU_NAME" as sku_name,
                trd."TRD_UTQNAME" as unit_name,
                NULLIF(trd."TRD_QTY", \'\')::numeric as qty,
                NULLIF(trd."TRD_U_PRC", \'\')::numeric as unit_price,
                NULLIF(trd."TRD_G_KEYIN", \'\')::numeric as line_total
            ')
            ->orderByRaw('trd."TRD_SEQ"::int')
            ->get();

        return view('documents.legacy-show', compact('header', 'items'));
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

    private function legacyDocumentsReady(): bool
    {
        foreach (['dbo__docinfo', 'dbo__doctype', 'dbo__transtkh', 'dbo__aroe', 'dbo__arfile'] as $table) {
            $exists = DB::selectOne('select to_regclass(?) as r', ['legacy.'.$table])->r ?? null;
            if ($exists === null) {
                return false;
            }
        }

        return true;
    }

    private function legacyDocumentQuery($day, $year, $month, $from, $to, string $q, string $legacyType = '')
    {
        $query = DB::table(DB::raw('legacy.dbo__docinfo as di'))
            ->join(DB::raw('legacy.dbo__doctype as dt'), DB::raw('dt."DT_KEY"'), '=', DB::raw('di."DI_DT"'))
            ->leftJoin(DB::raw('legacy.dbo__transtkh as trh'), DB::raw('trh."TRH_DI"'), '=', DB::raw('di."DI_KEY"'))
            ->leftJoin(DB::raw('legacy.dbo__aroe as aroe'), DB::raw('aroe."AROE_DI"'), '=', DB::raw('di."DI_KEY"'))
            ->leftJoin(DB::raw('legacy.dbo__arfile as ar'), DB::raw('ar."AR_KEY"'), '=', DB::raw('aroe."AROE_AR"'))
            ->selectRaw('
                di."DI_KEY" as di_key,
                di."DI_REF" as doc_number,
                di."DI_DATE"::date as doc_date,
                NULLIF(di."DI_AMOUNT", \'\')::numeric as total_amount,
                dt."DT_DOCCODE" as legacy_type_code,
                dt."DT_THAIDESC" as legacy_type_name,
                trh."TRH_KEY" as trh_key,
                NULLIF(trh."TRH_N_ITEMS", \'\')::numeric as item_count,
                NULLIF(trh."TRH_N_QTY", \'\')::numeric as total_qty,
                ar."AR_CODE" as customer_code,
                ar."AR_NAME" as customer_name
            ');

        if ($day) {
            [$start, $end] = $this->legacyDateTimeRange($day, '+1 day');
            $query->whereRaw('di."DI_DATE" >= ? and di."DI_DATE" < ?', [$start, $end]);
        } elseif ($year) {
            if ($month) {
                $startDate = sprintf('%04d-%02d-01', (int) $year, (int) $month);
                [$start, $end] = $this->legacyDateTimeRange($startDate, '+1 month');
            } else {
                $startDate = sprintf('%04d-01-01', (int) $year);
                [$start, $end] = $this->legacyDateTimeRange($startDate, '+1 year');
            }
            $query->whereRaw('di."DI_DATE" >= ? and di."DI_DATE" < ?', [$start, $end]);
        } else {
            if ($from) {
                [$start] = $this->legacyDateTimeRange($from, '+1 day');
                $query->whereRaw('di."DI_DATE" >= ?', [$start]);
            }
            if ($to) {
                [, $end] = $this->legacyDateTimeRange($to, '+1 day');
                $query->whereRaw('di."DI_DATE" < ?', [$end]);
            }
        }

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($where) use ($like) {
                $where->whereRaw('di."DI_REF" ilike ?', [$like])
                    ->orWhereRaw('dt."DT_DOCCODE" ilike ?', [$like])
                    ->orWhereRaw('dt."DT_THAIDESC" ilike ?', [$like])
                    ->orWhereRaw('ar."AR_CODE" ilike ?', [$like])
                    ->orWhereRaw('ar."AR_NAME" ilike ?', [$like]);
            });
        }

        if ($legacyType !== '') {
            $query->whereRaw('dt."DT_DOCCODE" = ?', [$legacyType]);
        }

        return $query;
    }

    private function legacyDateTimeRange(string $date, string $modify): array
    {
        $start = new \DateTimeImmutable($date);
        $end = $start->modify($modify);

        return [$start->format('Y-m-d 00:00:00.000'), $end->format('Y-m-d 00:00:00.000')];
    }
}
