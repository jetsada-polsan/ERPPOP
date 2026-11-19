<?php

namespace App\Http\Controllers;

use App\Services\Reports\MonthEndReceivables;
use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ZipArchive;

final class AccountingPackageController extends Controller
{
    private const SHEETS = [
        'vat_sales' => '01-ภาษีขาย', 'bplus_credit_sales_by_document' => '02-ขายเชื่อตามเอกสาร',
        'sales_returns_by_document' => '03-ใบลดหนี้รับคืน', 'bplus_receipt_allocations' => '04-ใบเสร็จและการตัดหนี้',
        'ar_aging' => '05-ลูกหนี้คงค้าง-อายุหนี้', 'bplus_daily_sales_summary' => '06-สรุปขายรายวัน', 'gl_journals' => '07-GL',
    ];

    private function areas(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('reports.export'), 403);
        // สาย BPlus เดิมไม่มี branch_id; ตัวซิงค์ใช้สาขาที่เปิดใช้งานลำดับแรก
        $fallback = DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');

        return DB::table('sales_areas')->where('is_active', true)
            ->select('id', 'code', 'name')->selectRaw('coalesce(branch_id, ?) as branch_id', [$fallback])
            ->when(! $user->canSeeAllReportBranches(), fn ($q) => $q->where(fn ($scope) => $scope->whereIn('branch_id', $user->reportBranchIds())
                ->when($user->reportBranchIds()->contains($fallback), fn ($s) => $s->orWhereNull('branch_id'))))
            ->orderBy('code');
    }

    public function index(Request $request)
    {
        return view('reports.accounting-package', ['areas' => $this->areas($request)->get(), 'month' => today()->subMonthNoOverflow()->format('Y-m')]);
    }

    public function download(Request $request, ReportController $reports, MonthEndReceivables $aging)
    {
        $areas = $this->areas($request);
        $data = $request->validate(['month' => 'required|date_format:Y-m', 'sales_area_id' => 'required|integer']);
        $area = $areas->where('id', $data['sales_area_id'])->first();
        abort_unless($area, 403);
        if (! class_exists(ZipArchive::class)) {
            return redirect()->route('accounting-package.index')->withErrors(['zip' => 'ดาวน์โหลดไม่ได้ กรุณาให้ผู้ดูแลเปิดส่วนขยาย ZIP ของ PHP']);
        }
        $from = Carbon::createFromFormat('!Y-m', $data['month']);
        $end = $from->copy()->endOfMonth();
        $summary = ['เดือน '.$data['month'], 'สายขาย '.$area->code.' '.$area->name, 'สร้างเมื่อ '.now()->format('d/m/Y H:i:s')];
        $path = tempnam(sys_get_temp_dir(), 'accounting-');
        $sheet = tempnam(sys_get_temp_dir(), 'sheet-');
        try {
            $zip = new ZipArchive;
            throw_unless($zip->open($path, ZipArchive::OVERWRITE) === true, \RuntimeException::class, 'เปิดไฟล์ ZIP ไม่สำเร็จ');
            foreach (self::SHEETS as $key => $label) {
                $result = $key === 'ar_aging' ? $aging->report($area, $end) : $reports->packageReport($key, $from, $end, $area);
                $reports->writeWorkbook($sheet, $result);
                throw_unless($zip->addFromString($label.'.xlsx', file_get_contents($sheet)), \RuntimeException::class, 'เพิ่มรายงานไม่ได้');
                $rows = collect($result['rows']);
                $line = match ($key) {
                    'bplus_credit_sales_by_document' => 'ขายเชื่อ '.$result['total'].' ใบ '.$result['summary']['net_sales'].' บาท',
                    'sales_returns_by_document' => 'ลดหนี้/รับคืน '.$rows->whereNotNull('doc_number')->filter(fn ($r) => $r->doc_number !== '')->count().' ใบ '.DecimalMath::sum($rows->filter(fn ($r) => $r->doc_number !== '')->pluck('total_amount'), 2).' บาท',
                    'bplus_receipt_allocations' => 'รับชำระ '.$rows->count().' ใบ '.DecimalMath::sum($rows->flatMap(fn ($r) => [$r->cash_amount, $r->transfer_amount, $r->other_amount]), 2).' บาท',
                    'ar_aging' => 'ลูกหนี้คงค้าง '.$rows->count().' ใบ '.DecimalMath::sum($rows->pluck('amount'), 2).' บาท',
                    'gl_journals' => 'GL '.$rows->pluck('document_id')->unique()->count().' เอกสาร เดบิต '.DecimalMath::sum($rows->pluck('debit'), 2).' เครดิต '.DecimalMath::sum($rows->pluck('credit'), 2).' บาท',
                    default => null
                };
                if ($line !== null) {
                    $summary[] = $line;
                }
            }
            throw_unless($zip->addFromString('00-สรุป.txt', implode("\r\n", $summary)."\r\n") && $zip->close(), \RuntimeException::class, 'บันทึก ZIP ไม่สำเร็จ');
            $code = preg_replace('/[^\pL\pN._-]/u', '-', $area->code);

            return response()->download($path, 'สนง-บัญชี-'.$code.'-'.$data['month'].'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
        } catch (\Throwable $error) {
            unset($zip);
            unlink($path);
            throw $error;
        } finally {
            unlink($sheet);
        }
    }
}
