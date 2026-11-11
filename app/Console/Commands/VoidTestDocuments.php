<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\SaleBooking;
use App\Models\User;
use App\Services\Accounting\DocumentVoidService;
use App\Services\Sales\BookingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ยกเลิกเอกสารทดสอบฝั่งขายและใบซื้อในช่วงวันที่ที่กำหนด โดยใช้ขั้นตอนยกเลิกของระบบเอง
 * (ย้อนสต็อก ลูกหนี้/เจ้าหนี้ และรายการบัญชีให้ตรง เก็บประวัติไว้ ไม่ลบแถวออกจากฐานข้อมูล)
 *
 * - ค่าเริ่มต้นคือ "ลองทำจริงแล้วย้อนกลับทั้งหมด" เพื่อรายงานว่าจะเกิดอะไรขึ้น ไม่เปลี่ยนข้อมูล
 * - ต้องใส่ --apply และ --user จึงจะบันทึกจริง
 * - ไม่แตะใบปรับสต็อก/ใบโอนสต็อก (สต็อกตั้งต้น) และบิลที่มาจากเครื่อง POS (ระบบกำหนดให้ยกเลิกที่เครื่อง POS)
 */
class VoidTestDocuments extends Command
{
    protected $signature = 'erp:void-test-documents
        {--from= : วันที่เริ่ม (YYYY-MM-DD) ตามวันที่สร้างเอกสาร}
        {--to= : วันที่สิ้นสุด (YYYY-MM-DD) รวมวันนั้น}
        {--user= : รหัสผู้ใช้ที่จะบันทึกเป็นผู้ยกเลิก (ใช้ตอน --apply)}
        {--apply : บันทึกจริง ไม่ใส่ = ลองแล้วย้อนกลับ}';

    protected $description = 'ยกเลิกเอกสารขาย/ใบซื้อที่เป็นของทดสอบในช่วงวันที่ (ค่าเริ่มต้นเป็นโหมดดูผลก่อน ไม่เปลี่ยนข้อมูล)';

    private const REASON = 'ล้างข้อมูลทดสอบก่อนเปิดใช้งานจริง';

    /** @var array<int, array{doc: string, type: string, total: float, result: string}> */
    private array $rows = [];

    public function handle(DocumentVoidService $voids, BookingService $bookings): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        if (! $from || ! $to || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $this->error('ต้องระบุ --from และ --to เป็น YYYY-MM-DD');

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        if ($apply && ! $userId) {
            $this->error('โหมด --apply ต้องระบุ --user=รหัสผู้ใช้ เพื่อบันทึกว่าใครเป็นคนยกเลิก');

            return self::INVALID;
        }
        $actor = $userId ? User::find($userId) : null;
        if ($userId && ! $actor) {
            $this->error("ไม่พบผู้ใช้รหัส {$userId}");

            return self::INVALID;
        }
        if ($actor) {
            Auth::setUser($actor);
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $this->info($apply ? "บันทึกจริง: ยกเลิกเอกสารที่สร้าง {$from} ถึง {$to}" : "โหมดดูผล (ไม่เปลี่ยนข้อมูล): เอกสารที่สร้าง {$from} ถึง {$to}");

        DB::beginTransaction();
        try {
            $this->phaseReceipts($voids, $start, $end, $userId);
            $this->phaseBookings($bookings, $start, $end, $userId);
            $this->phaseSales($voids, $start, $end, $userId);
            $this->phasePurchases($voids, $start, $end, $userId);
            $apply ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('หยุดกลางทางและย้อนกลับทั้งหมด: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($apply);

        return self::SUCCESS;
    }

    private function candidates(string $typeCode, Carbon $start, Carbon $end)
    {
        return Document::with('documentType')
            ->whereHas('documentType', fn ($q) => $q->where('code', $typeCode))
            ->where('status', 'active')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get();
    }

    private function phaseReceipts(DocumentVoidService $voids, Carbon $start, Carbon $end, ?int $userId): void
    {
        foreach ($this->candidates('RECEIPT', $start, $end) as $doc) {
            $this->voidOne($voids, $doc, $userId);
        }
    }

    private function phaseBookings(BookingService $bookings, Carbon $start, Carbon $end, ?int $userId): void
    {
        foreach ($this->candidates('BOOKING', $start, $end) as $doc) {
            $booking = SaleBooking::where('document_id', $doc->id)->first();
            if (! $booking) {
                $this->rows[] = ['doc' => $doc->doc_number, 'type' => 'BOOKING', 'total' => (float) $doc->total_amount, 'result' => 'ข้าม: ไม่พบใบจอง'];

                continue;
            }
            try {
                DB::transaction(function () use ($bookings, $booking, $userId) {
                    if ($booking->saleDocuments()->where('status', 'active')->exists()) {
                        $bookings->revertConfirmation($booking, self::REASON, $userId);
                    }
                    $bookings->cancel($booking->fresh(), self::REASON, $userId);
                });
                $this->rows[] = ['doc' => $doc->doc_number, 'type' => 'BOOKING', 'total' => (float) $doc->total_amount, 'result' => 'ยกเลิก'];
            } catch (Throwable $e) {
                $this->rows[] = ['doc' => $doc->doc_number, 'type' => 'BOOKING', 'total' => (float) $doc->total_amount, 'result' => 'ข้าม: '.$e->getMessage()];
            }
        }
    }

    private function phaseSales(DocumentVoidService $voids, Carbon $start, Carbon $end, ?int $userId): void
    {
        foreach (['CREDIT_SALE', 'CASH_SALE'] as $type) {
            foreach ($this->candidates($type, $start, $end) as $doc) {
                $this->voidOne($voids, $doc->fresh('documentType'), $userId);
            }
        }
    }

    private function phasePurchases(DocumentVoidService $voids, Carbon $start, Carbon $end, ?int $userId): void
    {
        foreach ($this->candidates('PURCHASE', $start, $end) as $doc) {
            $this->voidOne($voids, $doc, $userId);
        }
    }

    private function voidOne(DocumentVoidService $voids, Document $doc, ?int $userId): void
    {
        $doc = $doc->fresh('documentType');
        if ($doc->status !== 'active') {
            return; // ถูกยกเลิกไปแล้วในขั้นก่อนหน้า (เช่น ใบขายของใบจอง)
        }
        $row = ['doc' => $doc->doc_number, 'type' => (string) $doc->documentType?->code, 'total' => (float) $doc->total_amount];
        try {
            $voids->void($doc, self::REASON, $userId);
            $this->rows[] = $row + ['result' => 'ยกเลิก'];
        } catch (Throwable $e) {
            $this->rows[] = $row + ['result' => 'ข้าม: '.$e->getMessage()];
        }
    }

    private function report(bool $apply): void
    {
        $done = array_filter($this->rows, fn ($r) => $r['result'] === 'ยกเลิก');
        $skipped = array_filter($this->rows, fn ($r) => str_starts_with($r['result'], 'ข้าม'));
        $this->newLine();
        $this->table(['เลขที่', 'ประเภท', 'ยอด', 'ผล'], array_map(fn ($r) => [$r['doc'], $r['type'], number_format($r['total'], 2), $r['result']], $this->rows));
        $this->info(($apply ? 'ยกเลิกแล้ว ' : 'จะยกเลิก ').count($done).' ใบ  | ข้าม '.count($skipped).' ใบ');
        if ($skipped !== []) {
            $this->warn('ใบที่ถูกข้ามต้องจัดการเอง เช่น บิลจากเครื่อง POS ต้องยกเลิกที่เครื่อง POS');
        }
        $this->line($apply ? 'บันทึกลงฐานข้อมูลแล้ว' : 'โหมดดูผล: ย้อนกลับทั้งหมดแล้ว ข้อมูลไม่เปลี่ยน ใส่ --apply --user=รหัส เพื่อทำจริง');
    }
}
