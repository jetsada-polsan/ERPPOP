<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveInactiveCustomers extends Command
{
    protected $signature = 'erp:archive-inactive-customers
        {--months=6 : Number of months without a completed sale before deactivation}
        {--apply : Actually set eligible customers to inactive; without this flag the command is dry-run}
        {--limit= : Maximum number of customers to process}';

    protected $description = 'Deactivate customers with no sale for the configured period, while preserving history and open AR';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months)->startOfDay();
        $eligible = Customer::query()
            ->where('is_active', true)
            ->whereDoesntHave('openItems', fn ($query) => $query->whereIn('status', ['open', 'partial'])->where('balance_amount', '>', 0))
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('documents', fn ($query) => $query
                ->whereNull('cancelled_at')
                ->whereDate('doc_date', '>=', $cutoff->toDateString())
                ->whereHas('documentType', fn ($type) => $type->whereIn('code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])));

        $limit = (int) ($this->option('limit') ?: 0);
        if ($limit > 0) {
            $eligible->limit($limit);
        }
        $customers = $eligible->orderBy('id')->get(['id', 'code', 'name_th', 'branch_id']);
        $mode = $this->option('apply') ? 'APPLY' : 'DRY-RUN';
        $this->info("[{$mode}] ลูกค้าที่เข้าเกณฑ์ไม่มีการขาย {$months} เดือนก่อน ".$cutoff->format('Y-m-d').": ".number_format($customers->count()).' ราย');

        if ($customers->isEmpty()) {
            return self::SUCCESS;
        }
        $this->table(['รหัส', 'ชื่อลูกค้า', 'สาขา'], $customers->map(fn (Customer $customer) => [$customer->code, $customer->name_th, $customer->branch_id ?? '-'])->all());
        if (! $this->option('apply')) {
            $this->comment('ยังไม่เปลี่ยนข้อมูล ใช้ --apply เมื่อตรวจรายชื่อแล้ว');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($customers): void {
            foreach ($customers as $customer) {
                $customer->update(['is_active' => false]);
                AuditLog::create([
                    'user_id' => null, 'branch_id' => $customer->branch_id,
                    'action' => 'auto_archive_inactive_customer', 'table_name' => 'customers', 'record_id' => $customer->id,
                    'old_values' => ['is_active' => true],
                    'new_values' => ['is_active' => false, 'reason' => 'ไม่มีการขายตามระยะเวลาที่กำหนด'],
                ]);
            }
        });
        $this->info('ปิดใช้งานลูกค้าแล้ว '.number_format($customers->count()).' ราย โดยเก็บประวัติและเอกสารเดิมไว้ครบ');
        return self::SUCCESS;
    }
}
