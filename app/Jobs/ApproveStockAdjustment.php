<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Document;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ApproveStockAdjustment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(
        public int $documentId,
        public int $userId,
    ) {}

    public function handle(StockAdjustmentService $service): void
    {
        Auth::onceUsingId($this->userId);
        $document = Document::findOrFail($this->documentId);

        try {
            $service->approve($document, $this->userId);
            Cache::forget("stock_adjustment_error_{$this->documentId}");

            AuditLog::create([
                'user_id' => $this->userId,
                'branch_id' => $document->branch_id,
                'action' => 'approve',
                'table_name' => 'documents',
                'record_id' => $document->id,
                'old_values' => ['status' => 'approving'],
                'new_values' => ['status' => 'active'],
            ]);
        } catch (Throwable $e) {
            // เมื่อล้มเหลว: ย้อนกลับเป็น pending_approval และเก็บ error message ไว้ใน Cache
            Document::whereKey($this->documentId)->update(['status' => 'pending_approval']);
            Cache::put("stock_adjustment_error_{$this->documentId}", $e->getMessage(), now()->addHours(24));
            throw $e;
        }
    }
}
