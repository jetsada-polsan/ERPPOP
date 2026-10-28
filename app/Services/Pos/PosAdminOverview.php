<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosOfflineSale;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ข้อมูลหน้า "จัดการเครื่อง POS": ความพร้อมรายสาขา, สถานะ sync รายเครื่อง และปัญหาล่าสุด
 *
 * ERP ไม่เห็นคิวในเครื่อง (SQLite) โดยตรง จึงดูจากร่องรอยฝั่งเซิร์ฟเวอร์:
 * เวลาที่เครื่องติดต่อล่าสุด, กะ/บิลที่ส่งขึ้นมา, คำขอที่ล้มเหลว, การล็อกอินผิด และบิลออฟไลน์ที่รอตรวจ
 */
class PosAdminOverview
{
    /** ติดต่อภายในกี่นาทีถือว่าออนไลน์ (เครื่อง ping ทุกไม่กี่นาทีตอนเปิดโปรแกรม) */
    public const ONLINE_MINUTES = 10;

    public function branches(): array
    {
        return Branch::query()->where('is_active', true)->orderBy('code')
            ->get(['id', 'code', 'name_th', 'default_warehouse_location_id'])
            ->map(function (Branch $branch) {
                $devices = PosDevice::where('branch_id', $branch->id)->whereNull('revoked_at')->get(['id', 'last_seen_at']);
                $cashiers = Salesman::with('user.posCredential')->where('branch_id', $branch->id)->where('is_active', true)->orderBy('code')->get();
                $withPin = $cashiers->filter(fn (Salesman $s) => $this->hasPin($s));
                // user ที่ผูกเครื่องให้อัตโนมัติ: user ของสาขาที่มีโปรไฟล์คนขายและสิทธิ์ขาย (เช่น amnat)
                $deviceUser = User::where('branch_id', $branch->id)->where('is_active', true)
                    ->whereHas('posCashierProfile', fn ($q) => $q->where('is_active', true))
                    ->with(['roles.permissions', 'branchRoles.permissions'])->orderBy('id')->get()
                    ->first(fn (User $user) => $user->canUsePosDevice($branch->id));

                return [
                    'branch' => $branch,
                    'device_user' => $deviceUser,
                    'cashier_list' => $cashiers,
                    'devices' => $devices->count(),
                    'online_devices' => $devices->whereNotNull('last_seen_at')->count(),
                    'last_seen' => $devices->max('last_seen_at'),
                    'cashiers' => $cashiers->count(),
                    'cashiers_with_pin' => $withPin->count(),
                    'has_warehouse' => $branch->hasDefaultWarehouse(),
                ];
            })->all();
    }

    /** สถานะรายเครื่อง: ออนไลน์ไหม กะเปิดอยู่ไหม วันนี้ส่งบิลมาเท่าไร มีอะไรผิดพลาด */
    public function devices(): Collection
    {
        $since = now()->subDay();

        return PosDevice::with(['user:id,name,username', 'branch:id,code,name_th'])
            ->orderByRaw('revoked_at is not null')->orderBy('branch_id')->orderBy('terminal_code')->get()
            ->map(function (PosDevice $device) use ($since) {
                $terminalId = DB::table('pos_terminals')->where('code', $device->terminal_code)->value('id');
                $shift = $terminalId
                    ? DB::table('pos_shifts as s')->leftJoin('salesmen as c', 'c.id', '=', 's.cashier_id')
                        ->where('s.pos_terminal_id', $terminalId)->where('s.status', 'open')
                        ->orderByDesc('s.id')->first(['s.shift_no', 's.opened_at', 'c.code as cashier_code'])
                    : null;
                $receipts = $terminalId
                    ? DB::table('pos_receipts')->where('pos_terminal_id', $terminalId)->where('status', 'completed')
                        ->whereDate('receipt_date', now()->toDateString())
                        ->selectRaw('count(*) as n, coalesce(sum(net_sales), 0) as total')->first()
                    : null;

                return [
                    'device' => $device,
                    'active' => $device->revoked_at === null,
                    'online' => $device->last_seen_at && $device->last_seen_at->gt(now()->subMinutes(self::ONLINE_MINUTES)),
                    'shift' => $shift,
                    'receipts_today' => (int) ($receipts->n ?? 0),
                    'sales_today' => (float) ($receipts->total ?? 0),
                    'failed_requests' => $this->count('pos_api_idempotency', $device->id, fn ($q) => $q->where('created_at', '>=', $since)
                        ->where(fn ($w) => $w->where('status_code', '>=', 400)->orWhere('state', 'processing'))),
                    'login_failures' => $this->count('pos_auth_event_ingests', $device->id, fn ($q) => $q->where('created_at', '>=', $since)->where('success', false)),
                    'offline_review' => $this->count('pos_offline_sales', $device->id, fn ($q) => $q->whereIn('status', [PosOfflineSale::NEEDS_REVIEW, PosOfflineSale::PROCESSING])),
                ];
            });
    }

    /** ปัญหาล่าสุด 24 ชม. รวมทุกเครื่อง เรียงใหม่สุดก่อน */
    public function issues(int $limit = 20): Collection
    {
        $since = now()->subDay();
        $names = PosDevice::pluck('name', 'id');
        $rows = collect();

        if (Schema::hasTable('pos_api_idempotency')) {
            $rows = $rows->concat(DB::table('pos_api_idempotency')->where('created_at', '>=', $since)
                ->where(fn ($w) => $w->where('status_code', '>=', 400)->orWhere('state', 'processing'))
                ->orderByDesc('id')->limit($limit)->get()
                ->map(fn ($r) => ['at' => $r->created_at, 'device' => $names[$r->pos_device_id] ?? '-', 'kind' => 'ส่งข้อมูลไม่สำเร็จ',
                    'detail' => $r->endpoint.' · '.($r->state === 'processing' ? 'ค้างประมวลผล' : 'HTTP '.$r->status_code).' · '.$this->message($r->response_body)]));
        }
        if (Schema::hasTable('pos_auth_event_ingests')) {
            $rows = $rows->concat(DB::table('pos_auth_event_ingests')->where('created_at', '>=', $since)->where('success', false)
                ->orderByDesc('id')->limit($limit)->get()
                ->map(fn ($r) => ['at' => $r->occurred_at, 'device' => $names[$r->pos_device_id] ?? ($r->terminal_code ?: '-'), 'kind' => 'ล็อกอินไม่ผ่าน',
                    'detail' => $r->cashier_code.' · '.($r->reason ?: $r->event_type)]));
        }
        if (Schema::hasTable('pos_offline_sales')) {
            $rows = $rows->concat(DB::table('pos_offline_sales')->whereIn('status', [PosOfflineSale::NEEDS_REVIEW, PosOfflineSale::PROCESSING])
                ->orderByDesc('id')->limit($limit)->get()
                ->map(fn ($r) => ['at' => $r->updated_at ?? $r->created_at, 'device' => $names[$r->pos_device_id ?? 0] ?? '-', 'kind' => 'บิลออฟไลน์รอตรวจ',
                    'detail' => number_format((float) $r->offline_total, 2).' บาท · '.($r->review_reason ?: $r->status)]));
        }

        return $rows->sortByDesc(fn ($r) => (string) $r['at'])->take($limit)->values();
    }

    public function hasPin(Salesman $salesman): bool
    {
        return filled($salesman->user?->posCredential?->pin_hash) || filled($salesman->pos_pin_hash);
    }

    private function count(string $table, int $deviceId, callable $scope): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'pos_device_id')) {
            return 0;
        }

        return $scope(DB::table($table)->where('pos_device_id', $deviceId))->count();
    }

    private function message(?string $body): string
    {
        $decoded = json_decode((string) $body, true);

        return mb_substr((string) ($decoded['message'] ?? ''), 0, 120);
    }
}
