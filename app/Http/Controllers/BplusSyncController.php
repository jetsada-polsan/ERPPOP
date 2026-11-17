<?php

namespace App\Http\Controllers;

use App\Services\Bplus\BplusDocumentMaterializer;
use App\Services\Bplus\BplusSyncImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

/** เอกสารสายขายที่ซิงค์มาจาก BPlus: ดูเอกสาร ยอดขายรายวัน และนำเข้าไฟล์ */
class BplusSyncController extends Controller
{
    private const KIND_LABELS = ['booking' => 'ใบจอง', 'credit_sale' => 'ใบขายเชื่อ', 'opening_ar' => 'ลูกหนี้ยกมา', 'receipt' => 'ใบเสร็จรับเงิน'];

    public function index(Request $request): View
    {
        $from = $request->date('from')?->toDateString() ?? now()->subDays(6)->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();
        $kind = in_array($request->query('kind'), array_keys(self::KIND_LABELS), true) ? $request->query('kind') : null;
        $search = trim((string) $request->query('q', ''));

        $base = DB::table('bplus_sync_documents')->whereBetween('doc_date', [$from, $to]);

        $daily = (clone $base)->where('is_cancelled', false)
            ->selectRaw("doc_date,
                SUM(CASE WHEN kind = 'booking' THEN 1 ELSE 0 END) AS bookings,
                SUM(CASE WHEN kind = 'booking' THEN total_amount ELSE 0 END) AS booking_total,
                SUM(CASE WHEN kind = 'credit_sale' THEN 1 ELSE 0 END) AS sales,
                SUM(CASE WHEN kind = 'credit_sale' THEN vatable_amount ELSE 0 END) AS vatable,
                SUM(CASE WHEN kind = 'credit_sale' THEN exempt_amount ELSE 0 END) AS exempt,
                SUM(CASE WHEN kind = 'credit_sale' THEN vat_amount ELSE 0 END) AS vat,
                SUM(CASE WHEN kind = 'credit_sale' THEN total_amount ELSE 0 END) AS sales_total,
                SUM(CASE WHEN kind = 'receipt' THEN cash_amount ELSE 0 END) AS cash,
                SUM(CASE WHEN kind = 'receipt' THEN transfer_amount ELSE 0 END) AS transfer")
            ->groupBy('doc_date')->orderBy('doc_date')->get();

        $documents = (clone $base)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('ref', 'like', "%{$search}%")
                ->orWhere('booking_ref', 'like', "%{$search}%")
                ->orWhere('customer_code', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")))
            ->orderByDesc('doc_date')->orderByDesc('bplus_key')
            ->paginate(50)->withQueryString();

        return view('bplus-sync.index', [
            'from' => $from, 'to' => $to, 'kind' => $kind, 'search' => $search,
            'daily' => $daily, 'documents' => $documents, 'kindLabels' => self::KIND_LABELS,
            'lastSync' => DB::table('bplus_sync_documents')->max('updated_at'),
            'lastAutoSync' => DB::table('bplus_sync_runs')->latest('id')->first(),
        ]);
    }

    public function show(int $document): View
    {
        $doc = DB::table('bplus_sync_documents')->where('id', $document)->firstOrFail();

        return view('bplus-sync.show', [
            'doc' => $doc,
            'kindLabels' => self::KIND_LABELS,
            'lines' => DB::table('bplus_sync_lines')->where('bplus_sync_document_id', $doc->id)->orderBy('seq')->get(),
            'allocations' => DB::table('bplus_sync_allocations')->where('bplus_sync_document_id', $doc->id)->get(),
            // เอกสารที่เกี่ยวข้อง: ใบจอง -> ใบขาย, ใบขาย -> ใบจองและใบเสร็จ
            'related' => $this->related($doc),
        ]);
    }

    public function preview(Request $request, BplusSyncImporter $importer, BplusDocumentMaterializer $materializer): View|RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);
        $json = (string) file_get_contents($request->file('file')->getRealPath());
        try {
            $parsed = $importer->parse($json);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $token = (string) Str::uuid();
        Storage::disk('local')->put("bplus-sync/{$token}.json", $json);

        return view('bplus-sync.preview', [
            'token' => $token,
            'route' => $parsed['route'],
            'summary' => $importer->preview($parsed),
            'masterSummary' => $importer->previewMaster($parsed),
            'check' => $materializer->check(
                $parsed['route'],
                $parsed['documents'],
                $parsed['customers'] ?? [],
                $parsed['products'] ?? []
            ),
            'kindLabels' => self::KIND_LABELS,
            'range' => [
                collect($parsed['documents'])->min('doc_date'),
                collect($parsed['documents'])->max('doc_date'),
            ],
        ]);
    }

    public function import(Request $request, BplusSyncImporter $importer, BplusDocumentMaterializer $materializer): RedirectResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'uuid']])['token'];
        $path = "bplus-sync/{$token}.json";
        if (! Storage::disk('local')->exists($path)) {
            return redirect()->route('bplus.sync.index')->withErrors(['file' => 'ไม่พบไฟล์ที่ตรวจไว้ กรุณาอัปโหลดใหม่']);
        }
        $parsed = $importer->parse(Storage::disk('local')->get($path));
        $count = $importer->import($parsed);
        Storage::disk('local')->delete($path);
        // สร้างเป็นใบจอง / ใบขายเชื่อ / ใบเสร็จของ ERP ให้เห็นในเมนูและรายงานปกติ (ลง GL ตามยอดเอกสาร ไม่ตัดสต๊อก)
        $message = "บันทึกเอกสารจาก BPlus แล้ว {$count} ใบ";
        try {
            $result = $materializer->run($parsed['route']);
            $message .= " · เป็นเอกสาร ERP {$result['documents']} ใบ";
            if ($result['problems']) {
                $message .= ' · มีปัญหา '.count($result['problems']).' ใบ (ดูคอลัมน์ "ใน ERP")';
            }
        } catch (\RuntimeException $e) {
            $message .= ' · ยังสร้างเป็นเอกสาร ERP ไม่ได้: '.$e->getMessage();
        }

        return redirect()->route('bplus.sync.index')->with('success', $message);
    }

    private function related(object $doc): Collection
    {
        $query = DB::table('bplus_sync_documents')->where('id', '!=', $doc->id);
        if ($doc->kind === 'booking') {
            return $query->where('booking_ref', $doc->ref)->orderBy('ref')->get();
        }
        if ($doc->kind === 'credit_sale') {
            $receiptIds = DB::table('bplus_sync_allocations')->where('sale_ref', $doc->ref)->pluck('bplus_sync_document_id');

            return $query->where(fn ($q) => $q->where('ref', $doc->booking_ref)->orWhereIn('id', $receiptIds))->get();
        }
        $saleRefs = DB::table('bplus_sync_allocations')->where('bplus_sync_document_id', $doc->id)->pluck('sale_ref');

        return $query->whereIn('ref', $saleRefs)->get();
    }
}
