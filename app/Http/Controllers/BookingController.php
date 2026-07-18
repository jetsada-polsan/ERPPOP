<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DocumentBook;
use App\Models\SaleBooking;
use App\Models\Salesman;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status', '');
        $bookId = $request->integer('book') ?: null;
        $legacyType = trim((string) $request->query('legacy_type', ''));

        $bookings = SaleBooking::with(['document.customer', 'document.branch', 'document.documentBook'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($bookId, fn ($query) => $query->whereHas('document', fn ($d) => $d->where('document_book_id', $bookId)))
            ->when($q !== '', fn ($query) => $query->whereHas('document', fn ($d) => $d
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $counts = [
            'all' => SaleBooking::count(),
            'pending' => SaleBooking::where('status', 'pending')->count(),
            'converted_to_sale' => SaleBooking::where('status', 'converted_to_sale')->count(),
            'legacy' => 0,
        ];

        $legacyBookings = null;
        $legacyTypes = collect();
        if (($status === '' || $status === 'pending') && $this->legacyBookingsReady()) {
            $legacyTypes = $this->legacyBookingTypes();

            $legacyQuery = $this->legacyBookingQuery($q, $legacyType);
            $counts['legacy'] = (clone $legacyQuery)->count();
            $counts['all'] += $counts['legacy'];
            if ($status === 'pending') {
                $counts['pending'] += $counts['legacy'];
            }

            $legacyBookings = $legacyQuery
                ->orderByRaw('di."DI_DATE" desc')
                ->orderByRaw('di."DI_KEY" desc')
                ->paginate(50, ['*'], 'legacy_page')
                ->withQueryString();
        }

        $branches = Branch::orderBy('code')->get();
        $salesmen = Salesman::where('is_active', true)->orderBy('name')->get();
        $documentBooks = DocumentBook::whereHas('documentType', fn ($query) => $query->whereIn('code', ['BOOKING', 'CREDIT_SALE']))
            ->where('is_active', true)
            ->orderBy('document_type_id')
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();

        return view('bookings.index', compact('bookings', 'legacyBookings', 'legacyTypes', 'branches', 'salesmen', 'documentBooks', 'q', 'status', 'bookId', 'legacyType', 'counts'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('bookings.index');
    }

    public function store(Request $request, BookingService $service): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'salesman_id' => ['nullable', 'integer', 'exists:salesmen,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bookings.show', $document->saleBooking)
            ->with('success', "สร้างใบจอง {$document->doc_number} แล้ว กันสต๊อกเรียบร้อย");
    }

    public function show(SaleBooking $booking): View
    {
        $booking->load([
            'document.customer', 'document.branch', 'document.salesman',
            'document.stockDocument.items.product', 'confirmedDocument',
        ]);

        $creditSaleBooks = DocumentBook::withCount('documents')
            ->whereHas('documentType', fn ($q) => $q->where('code', 'CREDIT_SALE'))
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get();

        return view('bookings.show', compact('booking', 'creditSaleBooks'));
    }

    public function legacyShow(string $diKey): View
    {
        abort_unless($this->legacyBookingsReady(), 404);

        $header = $this->legacyBookingQuery('')
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

        return view('bookings.legacy-show', compact('header', 'items'));
    }

    public function convert(Request $request, SaleBooking $booking, CreditSaleService $service): RedirectResponse
    {
        $data = $request->validate([
            'document_book_id' => ['nullable', 'integer', 'exists:document_books,id'],
        ]);

        $book = null;
        if (! empty($data['document_book_id'])) {
            $book = DocumentBook::whereHas('documentType', fn ($q) => $q->where('code', 'CREDIT_SALE'))
                ->where('is_active', true)
                ->findOrFail($data['document_book_id']);
        }

        try {
            $saleDocument = $service->convertBookingToCreditSale($booking, $book);
        } catch (RuntimeException $e) {
            return redirect()->route('bookings.show', $booking)->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $saleDocument)
            ->with('success', "แปลงใบจองเป็นใบขายเชื่อ {$saleDocument->doc_number} แล้ว ตัดสต๊อกและตั้งลูกหนี้เรียบร้อย");
    }

    private function legacyBookingsReady(): bool
    {
        foreach (['dbo__docinfo', 'dbo__doctype', 'dbo__transtkh', 'dbo__aroe', 'dbo__arfile'] as $table) {
            $exists = DB::selectOne('select to_regclass(?) as r', ['legacy.'.$table])->r ?? null;
            if ($exists === null) {
                return false;
            }
        }

        return true;
    }

    private function legacyBookingQuery(string $q, string $legacyType = '')
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

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($where) use ($like) {
                $where->whereRaw('di."DI_REF" ilike ?', [$like])
                    ->orWhereRaw('dt."DT_DOCCODE" ilike ?', [$like])
                    ->orWhereRaw('ar."AR_CODE" ilike ?', [$like])
                    ->orWhereRaw('ar."AR_NAME" ilike ?', [$like]);
            });
        }

        if ($legacyType !== '') {
            $query->whereRaw('dt."DT_DOCCODE" = ?', [$legacyType]);
        }

        return $query;
    }

    private function legacyBookingTypes()
    {
        return DB::table(DB::raw('legacy.dbo__docinfo as di'))
            ->join(DB::raw('legacy.dbo__doctype as dt'), DB::raw('dt."DT_KEY"'), '=', DB::raw('di."DI_DT"'))
            ->selectRaw('dt."DT_DOCCODE" as code, dt."DT_THAIDESC" as name, count(*) as count')
            ->groupByRaw('dt."DT_DOCCODE", dt."DT_THAIDESC"')
            ->orderByRaw('dt."DT_DOCCODE"')
            ->get();
    }
}
