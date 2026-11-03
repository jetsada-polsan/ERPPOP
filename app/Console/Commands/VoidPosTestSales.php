<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Document;
use App\Models\PosReceipt;
use App\Models\StockDocument;
use App\Services\Accounting\GlPostingService;
use App\Services\Inventory\FifoStockService;
use App\Services\Sales\MemberPointService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ยกเลิกบิล POS ที่ขายทดสอบก่อนเปิดร้าน แบบเดียวกับปุ่มยกเลิกบิล: คืนสต็อก กลับรายการบัญชีและแต้ม
 * ไม่ลบแถวทิ้ง (ยังตรวจย้อนหลังได้) แต่รายงานยอดขายจะไม่นับ ไม่แตะบิลเก่าที่นำเข้าจาก BPlus
 */
class VoidPosTestSales extends Command
{
    protected $signature = 'erp:void-pos-test-sales
        {--before= : ยกเลิกบิลที่ขายก่อนวันนี้ (YYYY-MM-DD) เช่น วันเปิดร้าน}
        {--branch=* : รหัสสาขา เช่น B001 HQ ไม่ใส่ = ทุกสาขา}
        {--apply : บันทึกจริง ถ้าไม่ใส่จะแสดงรายการอย่างเดียว}';

    protected $description = 'Void pre-launch test POS bills, restoring stock like the void button';

    public function handle(FifoStockService $fifo, GlPostingService $gl, MemberPointService $points): int
    {
        $before = (string) $this->option('before');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            $this->error('ใส่ --before=YYYY-MM-DD');

            return self::FAILURE;
        }
        $branchIds = collect($this->option('branch'))->filter()
            ->map(fn ($code) => Branch::where('code', $code)->value('id') ?? -1);

        $receipts = PosReceipt::query()
            ->with(['shift', 'terminal'])
            ->where('status', 'completed')
            ->where(fn ($query) => $query->whereNull('is_legacy_import')->orWhere('is_legacy_import', false))
            ->where('receipt_date', '<', $before)
            ->when($branchIds->isNotEmpty(), fn ($query) => $query->whereHas('terminal', fn ($terminal) => $terminal->whereIn('branch_id', $branchIds)))
            ->orderBy('receipt_date')
            ->get();

        $this->table(['เลขบิล', 'วันที่', 'เครื่อง', 'ยอด'], $receipts->map(fn ($receipt) => [
            $receipt->receipt_no, $receipt->receipt_date?->format('d/m/Y H:i'), $receipt->terminal?->code, number_format((float) $receipt->net_sales, 2),
        ]));
        $pendingReturns = DB::table('pos_receipt_returns')->whereIn('pos_receipt_id', $receipts->pluck('id'))->where('status', 'pending_approval')->count();
        $this->info("บิลทดสอบ {$receipts->count()} ใบ ยอดรวม ".number_format((float) $receipts->sum('net_sales'), 2)." บาท · ใบรับคืนรออนุมัติ {$pendingReturns} ใบ");

        if (! $this->option('apply')) {
            $this->info('แสดงผลอย่างเดียว ยังไม่ได้บันทึก ใส่ --apply เพื่อยกเลิกจริง');

            return self::SUCCESS;
        }

        $reason = 'ล้างบิลทดสอบก่อนเปิดร้าน';
        foreach ($receipts as $receipt) {
            DB::transaction(function () use ($receipt, $fifo, $gl, $points, $reason): void {
                $receipt = PosReceipt::with('member')->whereKey($receipt->id)->lockForUpdate()->first();
                if (! $receipt || $receipt->status !== 'completed') {
                    return;
                }
                $receipt->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason]);
                // ใบรับคืนที่ยังไม่อนุมัติของบิลนี้ ไม่มีเงินออกจริง ปิดไปพร้อมกัน
                DB::table('pos_receipt_returns')->where('pos_receipt_id', $receipt->id)->where('status', 'pending_approval')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);

                $document = $receipt->document_id ? Document::whereKey($receipt->document_id)->lockForUpdate()->first() : null;
                if ($document && $document->status !== 'cancelled') {
                    $document->forceFill([
                        'status' => 'cancelled', 'cancelled_at' => now(),
                        'remark' => trim(($document->remark ? $document->remark.' | ' : '').'POS void: '.$reason),
                    ])->save();
                    if (StockDocument::where('document_id', $document->id)->exists()) {
                        $fifo->restoreDocumentIssues($document->id, 'void_in');
                    }
                    $gl->reverseDocument($document, 'POS void '.$receipt->receipt_no.' - '.$reason);
                    if ($receipt->member) {
                        $points->reverseDocument($receipt->member, $document, 'ยกเลิกบิล POS '.$receipt->receipt_no);
                    }
                }

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'branch_id' => $receipt->terminal?->branch_id ?? $receipt->shift?->branch_id,
                    'action' => 'void', 'table_name' => 'pos_receipts', 'record_id' => $receipt->id,
                    'old_values' => json_encode(['status' => 'completed', 'receipt_no' => $receipt->receipt_no], JSON_UNESCAPED_UNICODE),
                    'new_values' => json_encode(['status' => 'void', 'void_reason' => $reason, 'by' => 'erp:void-pos-test-sales'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            });
        }
        $this->info("ยกเลิกแล้ว {$receipts->count()} ใบ คืนสต็อกและกลับรายการบัญชีแล้ว");

        return self::SUCCESS;
    }
}
