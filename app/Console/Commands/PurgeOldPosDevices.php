<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ล้างเครื่อง POS / จุดขายเก่าที่ค้างจากช่วงทดสอบ ก่อนเริ่มผูกเครื่องจริง
 *
 * - เครื่อง (pos_devices): ลบทุกเครื่อง ยกเว้นที่ระบุใน --keep (รหัสเครื่อง) พร้อม log ของเครื่องนั้น
 * - จุดขาย (pos_terminals): ลบเฉพาะที่ไม่มีกะ/บิลอ้างอยู่ และไม่ใช่ของเครื่องที่เก็บไว้
 * - เล่มเอกสารขาย POS (PSxx) ของสาขาที่ไม่มีแล้ว: ปิดใช้งาน ไม่ลบ (เอกสารเก่าอาจอ้างอยู่)
 */
class PurgeOldPosDevices extends Command
{
    use RequiresFreshBackup;

    private const DEVICE_LOGS = ['pos_api_idempotency', 'pos_auth_event_ingests'];

    protected $signature = 'erp:purge-old-pos-devices
        {--keep=* : รหัสเครื่องที่เก็บไว้ เช่น POS-B001-01}
        {--dry-run : สรุปโดยไม่แก้ข้อมูล}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Remove leftover test POS devices, unused terminals and POS document books of removed branches';

    public function handle(): int
    {
        $keep = array_values(array_filter(array_map('trim', (array) $this->option('keep'))));
        $devices = DB::table('pos_devices')->whereNotIn('terminal_code', $keep)->get(['id', 'name', 'terminal_code']);
        $deviceIds = $devices->pluck('id')->all();

        $terminals = DB::table('pos_terminals')->whereNotIn('code', $keep)->get(['id', 'code', 'name'])
            ->reject(fn ($t) => DB::table('pos_shifts')->where('pos_terminal_id', $t->id)->exists()
                || DB::table('pos_receipts')->where('pos_terminal_id', $t->id)->exists());
        $terminalIds = $terminals->pluck('id')->all();

        $branchNames = Branch::pluck('name_th')->all();
        $books = DB::table('document_books')->where('code', 'like', 'PS%')->where('is_active', true)->get(['id', 'code', 'name'])
            ->reject(fn ($b) => collect($branchNames)->contains(fn ($n) => str_contains($b->name, $n)));

        $logs = collect(self::DEVICE_LOGS)->filter(fn ($t) => Schema::hasTable($t))
            ->mapWithKeys(fn ($t) => [$t => $deviceIds === [] ? 0 : DB::table($t)->whereIn('pos_device_id', $deviceIds)->count()]);

        $this->table(['ลบเครื่อง', 'รหัส'], $devices->map(fn ($d) => [$d->name, $d->terminal_code])->all());
        $this->table(['ลบจุดขาย', 'ชื่อ'], $terminals->map(fn ($t) => [$t->code, $t->name])->all());
        $this->table(['ปิดเล่มเอกสาร', 'ชื่อ'], $books->map(fn ($b) => [$b->code, $b->name])->all());
        foreach ($logs as $table => $count) {
            $this->line("log เครื่องที่ลบตาม ({$table}): {$count}");
        }
        $this->line('เก็บไว้: '.($keep === [] ? '-' : implode(', ', $keep)));

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันลบเครื่อง '.count($deviceIds).' และจุดขาย '.count($terminalIds).'?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($deviceIds, $terminalIds, $books, $logs) {
                foreach ($logs->keys() as $table) {
                    DB::table($table)->whereIn('pos_device_id', $deviceIds)->delete();
                }
                if (Schema::hasColumn('pos_offline_sales', 'pos_device_id')) {
                    DB::table('pos_offline_sales')->whereIn('pos_device_id', $deviceIds)->update(['pos_device_id' => null]);
                }
                DB::table('pos_devices')->whereIn('id', $deviceIds)->delete();
                DB::table('pos_terminals')->whereIn('id', $terminalIds)->delete();
                DB::table('document_books')->whereIn('id', $books->pluck('id'))->update(['is_active' => false, 'updated_at' => now()]);
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกแก้ (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('ลบเครื่อง %d จุดขาย %d ปิดเล่ม %d', count($deviceIds), count($terminalIds), $books->count()));

        return self::SUCCESS;
    }
}
