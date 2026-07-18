<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\PosTerminal;
use App\Services\PosImport\PosImportPostingService;
use App\Services\PosImport\PosImportStagingService;
use App\Services\PosImport\PosImportValidationService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use ZipArchive;

/**
 * Review/confirm/post for the POS Import pipeline. The actual pull-from-MSSQL step
 * is `php artisan pos-import:sync {pos_code} {date}` (run on a schedule); the web
 * pages here (page/pageShow) are for reviewing what landed and moving it forward by
 * hand, plus a "sync now" form for ad-hoc/backfill use. index()/show() are a small
 * read-only JSON API over the same data for scripting.
 */
class PosImportController extends Controller
{
    public function page(): View
    {
        $batches = ImportBatch::with('terminal.branch')
            ->withCount([
                'receipts',
                'receipts as valid_count' => fn ($q) => $q->where('status', 'valid'),
                'receipts as error_count' => fn ($q) => $q->where('status', 'error'),
                'receipts as posted_count' => fn ($q) => $q->where('status', 'posted'),
            ])
            ->orderByDesc('sale_date')
            ->paginate(50);

        $terminals = PosTerminal::with('branch')->orderBy('code')->get();

        return view('pos-import.index', compact('batches', 'terminals'));
    }

    public function pageShow(ImportBatch $batch): View
    {
        $batch->load(['terminal.branch', 'confirmedBy']);

        return view('pos-import.show', [
            'batch' => $batch,
            'receipts' => $batch->receipts()->withCount('items', 'payments')->orderBy('receipt_no')->paginate(100),
            'errors' => $batch->errors()->orderByDesc('id')->paginate(100),
        ]);
    }

    public function index(): JsonResponse
    {
        $batches = ImportBatch::withCount([
            'receipts',
            'receipts as valid_count' => fn ($q) => $q->where('status', 'valid'),
            'receipts as error_count' => fn ($q) => $q->where('status', 'error'),
            'receipts as voided_count' => fn ($q) => $q->where('status', 'voided'),
            'receipts as posted_count' => fn ($q) => $q->where('status', 'posted'),
            'errors',
        ])
            ->orderByDesc('sale_date')
            ->paginate(50);

        return response()->json($batches);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        $batch->load(['terminal.branch', 'uploadedBy', 'confirmedBy']);

        return response()->json([
            'batch' => $batch,
            'receipts' => $batch->receipts()
                ->withCount('items', 'payments')
                ->paginate(100),
            'errors' => $batch->errors()->orderByDesc('id')->paginate(100),
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pos_code' => ['required', 'string', 'max:20'],
            'sale_date' => ['required', 'date'],
        ]);

        $batch = app(PosImportStagingService::class)->stage($data['pos_code'], Carbon::parse($data['sale_date']));
        $batch = app(PosImportValidationService::class)->validate($batch);

        return redirect()->route('pos-import.batches.page-show', $batch)
            ->with('success', "ดึงข้อมูล POS {$data['pos_code']} วันที่ {$data['sale_date']} แล้ว ({$batch->record_count} ใบเสร็จ)")
            ->with('success_popup', true);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sale_date' => ['nullable', 'date'],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'mimes:zip', 'max:20480'],
        ]);

        $created = 0;
        $skipped = 0;
        $lastBatch = null;

        foreach ($request->file('files') as $file) {
            $originalName = $file->getClientOriginalName();
            [$posCode, $saleDate] = $this->resolveUploadMeta($originalName, $data['sale_date'] ?? null);
            $fileHash = hash_file('sha256', $file->getRealPath());

            $existing = ImportBatch::where('file_hash', $fileHash)->first();
            if ($existing) {
                $skipped++;
                $lastBatch = $existing;
                continue;
            }

            $existing = ImportBatch::where('pos_code', $posCode)
                ->whereDate('sale_date', $saleDate)
                ->first();
            if ($existing) {
                $skipped++;
                $lastBatch = $existing;
                continue;
            }

            $cdsName = $this->firstCdsName($file->getRealPath());
            $terminalId = PosTerminal::where('code', $posCode)->value('id');
            $storedPath = $file->storeAs(
                "pos-import/{$saleDate}/{$posCode}",
                now()->format('His').'-'.$originalName
            );

            try {
                $lastBatch = DB::transaction(function () use ($posCode, $saleDate, $originalName, $cdsName, $fileHash, $terminalId, $storedPath, $file) {
                    $batch = ImportBatch::create([
                        'pos_code' => $posCode,
                        'pos_terminal_id' => $terminalId,
                        'source_system' => 'zip_cds',
                        'sale_date' => $saleDate,
                        'source_zip_name' => $originalName,
                        'source_cds_name' => $cdsName,
                        'file_hash' => $fileHash,
                        'record_count' => 0,
                        'status' => ImportBatch::STATUS_UPLOADED,
                        'uploaded_by' => request()->user()?->id,
                        'uploaded_at' => now(),
                    ]);

                    $batch->files()->create([
                        'file_name' => $originalName,
                        'file_size' => $file->getSize(),
                        'file_hash' => $fileHash,
                        'raw_path' => $storedPath,
                        'uploaded_at' => now(),
                    ]);

                    return $batch;
                });
            } catch (UniqueConstraintViolationException $e) {
                $skipped++;
                $lastBatch = ImportBatch::where('pos_code', $posCode)
                    ->whereDate('sale_date', $saleDate)
                    ->first();
                continue;
            }

            $created++;
        }

        $message = "รับไฟล์ POS แล้ว {$created} ไฟล์";
        if ($skipped > 0) {
            $message .= " / ข้ามไฟล์ซ้ำ {$skipped} ไฟล์";
        }
        $message .= ' จากนั้นให้ Sync จาก MSSQL เพื่อแปลงเป็นใบเสร็จและตรวจสอบ';
        $popupParams = [
            'import_popup' => 1,
            'created' => $created,
            'skipped' => $skipped,
        ];

        if ($created === 1 && $lastBatch) {
            return redirect()->route('pos-import.batches.page-show', ['batch' => $lastBatch->id] + $popupParams)
                ->with('success', $message)
                ->with('success_popup', true);
        }

        return redirect()->route('pos-import.page', $popupParams)
            ->with('success', $message)
            ->with('success_popup', true);
    }

    /** @return array{0:string,1:string} */
    private function resolveUploadMeta(string $fileName, ?string $fallbackDate): array
    {
        if (preg_match('/^(\d{4})(\d{4})(\d{2})(\d{2})/i', $fileName, $m)) {
            return [$m[1], "{$m[2]}-{$m[3]}-{$m[4]}"];
        }

        if (! $fallbackDate) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sale_date' => 'กรุณาเลือกวันที่ขาย หรือใช้ชื่อไฟล์แบบ 000120260630.Zip',
            ]);
        }

        return ['0000', Carbon::parse($fallbackDate)->toDateString()];
    }

    private function firstCdsName(string $zipPath): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name && str_ends_with(strtolower($name), '.cds')) {
                    return $name;
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    public function revalidate(ImportBatch $batch): RedirectResponse
    {
        app(PosImportValidationService::class)->validate($batch);

        return redirect()->route('pos-import.batches.page-show', $batch)
            ->with('success', 'ตรวจสอบข้อมูลใหม่แล้ว')
            ->with('success_popup', true);
    }

    public function confirm(Request $request, ImportBatch $batch): RedirectResponse
    {
        if ($batch->status !== ImportBatch::STATUS_VALIDATED) {
            return redirect()->route('pos-import.batches.page-show', $batch)
                ->with('error', "ยืนยันไม่ได้ ต้องไม่มี error ค้างก่อน (สถานะปัจจุบัน: {$batch->status})");
        }

        $batch->update([
            'status' => ImportBatch::STATUS_CONFIRMED,
            'confirmed_by' => $request->user()?->id,
            'confirmed_at' => now(),
        ]);

        return redirect()->route('pos-import.batches.page-show', $batch)
            ->with('success', 'ยืนยันข้อมูลแล้ว พร้อม Post')
            ->with('success_popup', true);
    }

    public function post(ImportBatch $batch): RedirectResponse
    {
        try {
            app(PosImportPostingService::class)->post($batch);
        } catch (RuntimeException $e) {
            return redirect()->route('pos-import.batches.page-show', $batch)->with('error', $e->getMessage());
        }

        return redirect()->route('pos-import.batches.page-show', $batch)
            ->with('success', 'Post เข้าระบบเรียบร้อยแล้ว')
            ->with('success_popup', true);
    }
}
