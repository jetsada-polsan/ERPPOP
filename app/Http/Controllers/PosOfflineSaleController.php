<?php

namespace App\Http\Controllers;

use App\Models\PosOfflineSale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Web POS ขายออฟไลน์: รับคิวบิลที่ขายในเครื่องตอนเน็ต/Host ล่ม แล้วลงบัญชีผ่าน checkout เดิม
 *
 * - offline_uuid กันบิลซ้ำ ส่งซ้ำกี่ครั้งก็ได้ผลเดิม
 * - กฎราคา/สต๊อก/บัญชีทั้งหมดยังผ่าน PosController::checkout ตัวเดิม ไม่มีทางลัด
 * - บิลที่ server รับไม่ได้ (ราคาไม่ตรง กะปิดแล้ว ฯลฯ) ไม่หาย: เก็บเป็น needs_review ให้ผู้จัดการตรวจ
 */
class PosOfflineSaleController extends Controller
{
    public const MAX_BATCH = 50;

    /** health check เบา ๆ ให้ POS รู้ว่า Host กลับมาแล้ว + คืน CSRF token ใหม่สำหรับส่งคิว */
    public function ping(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'user_id' => $request->user()?->id,
            'csrf_token' => csrf_token(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sales' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'sales.*.offline_uuid' => ['required', 'uuid'],
            'sales.*.sold_at' => ['required', 'date'],
            'sales.*.total' => ['nullable', 'numeric'],
            'sales.*.payload' => ['required', 'array'],
        ]);

        $results = [];
        foreach ($data['sales'] as $sale) {
            $results[] = $this->processOne($request, $sale);
        }

        return response()->json(['results' => $results]);
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', PosOfflineSale::NEEDS_REVIEW);
        $sales = PosOfflineSale::with(['user:id,name', 'branch:id,code,name_th', 'resolver:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('sold_at')
            ->paginate(50)
            ->withQueryString();

        return view('pos.offline-sales', [
            'sales' => $sales,
            'status' => $status,
            'counts' => PosOfflineSale::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** ส่งบิลที่ติดตรวจเข้า checkout อีกครั้ง หลังแก้ต้นเหตุ (เช่น เปิดกะ/แก้ราคา) */
    public function retry(Request $request, PosOfflineSale $offlineSale): RedirectResponse
    {
        if ($offlineSale->status !== PosOfflineSale::NEEDS_REVIEW) {
            return back()->with('error', 'บิลนี้ไม่ได้อยู่ในสถานะต้องตรวจสอบ');
        }

        $payload = $offlineSale->payload;
        $payload['shift_id'] = $request->integer('shift_id') ?: ($payload['shift_id'] ?? null);
        // ลงบิลในชื่อพนักงานที่ขายจริง (ไม่ใช่ผู้จัดการที่กดส่งใหม่) ราคา/ส่วนลดตรวจตามสิทธิ์ของคนขาย
        $owner = $offlineSale->user;
        if (! $owner) {
            return back()->with('error', 'ไม่พบผู้ใช้ที่ขายบิลนี้ กรุณาปิดรายการด้วยมือ');
        }
        $manager = Auth::user();
        Auth::setUser($owner);
        try {
            $result = $this->post($request, $offlineSale, $payload, true, $owner);
        } finally {
            Auth::setUser($manager);
        }
        $offlineSale->update(['review_reason' => trim(($offlineSale->fresh()->review_reason ?? '')."\nส่งใหม่โดย: ".$manager->name)]);

        return back()->with(
            $result['status'] === PosOfflineSale::POSTED ? 'success' : 'error',
            $result['status'] === PosOfflineSale::POSTED
                ? 'ลงบิล '.$result['receipt_no'].' แล้ว'
                : 'ยังลงบิลไม่ได้: '.$result['message'],
        );
    }

    /** ปิดรายการเมื่อผู้จัดการจัดการด้วยมือแล้ว (เช่น คีย์บิลใหม่ หรือยืนยันว่าเป็นบิลทดสอบ) */
    public function resolve(Request $request, PosOfflineSale $offlineSale): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        if ($offlineSale->status !== PosOfflineSale::NEEDS_REVIEW) {
            return back()->with('error', 'บิลนี้ไม่ได้อยู่ในสถานะต้องตรวจสอบ');
        }

        $offlineSale->update([
            'status' => PosOfflineSale::RESOLVED,
            'review_reason' => trim($offlineSale->review_reason."\nปิดโดยผู้จัดการ: ".$data['note']),
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'ปิดรายการแล้ว');
    }

    private function processOne(Request $request, array $sale): array
    {
        $uuid = strtolower($sale['offline_uuid']);
        $payload = $sale['payload'];

        $existing = PosOfflineSale::where('offline_uuid', $uuid)->first();
        if (! $existing) {
            try {
                $existing = PosOfflineSale::create([
                    'offline_uuid' => $uuid,
                    'user_id' => $request->user()?->id,
                    'branch_id' => isset($payload['branch_id']) ? (int) $payload['branch_id'] : null,
                    'shift_id' => isset($payload['shift_id']) ? (int) $payload['shift_id'] : null,
                    'sold_at' => Carbon::parse($sale['sold_at']),
                    'offline_total' => (float) ($sale['total'] ?? 0),
                    'status' => PosOfflineSale::PROCESSING,
                    'payload' => $payload,
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = PosOfflineSale::where('offline_uuid', $uuid)->firstOrFail();

                return $this->result($existing, 'บิลนี้กำลังถูกส่งจากอีกหน้าต่าง');
            }

            return $this->post($request, $existing, $payload, true);
        }

        if ((int) $existing->user_id !== (int) $request->user()?->id) {
            return ['offline_uuid' => $uuid, 'status' => 'conflict', 'receipt_no' => null, 'message' => 'เลขบิลออฟไลน์นี้เป็นของผู้ใช้อื่น'];
        }

        // ส่งซ้ำ: คืนผลเดิม ไม่สร้างบิลใหม่
        return $this->result($existing);
    }

    /**
     * @param  bool  $asOwner  ลงบิลในชื่อคนขายจริงได้เฉพาะตอนผู้ขายเองเป็นคนส่ง
     */
    private function post(Request $request, PosOfflineSale $sale, array $payload, bool $asOwner, $actingUser = null): array
    {
        if (! $asOwner) {
            $sale->update(['attempts' => $sale->attempts + 1]);

            return ['offline_uuid' => $sale->offline_uuid, 'status' => $sale->status, 'receipt_no' => null,
                'message' => 'ต้องให้พนักงานที่ขายบิลนี้ล็อกอินแล้วกดส่งใหม่ หรือปิดรายการด้วยมือ'];
        }

        $payload['offline_sold_at'] = $sale->sold_at?->toIso8601String();
        $payload['offline_uuid'] = $sale->offline_uuid;
        unset($payload['_token']);

        $sub = Request::create($request->getUri(), 'POST', [], $request->cookies->all(), [], $request->server->all());
        $sub->headers->set('Accept', 'application/json');
        $sub->merge($payload);
        $sub->setUserResolver($actingUser ? fn () => $actingUser : $request->getUserResolver());
        $sub->setLaravelSession($request->session());
        $sub->attributes->set('pos_offline_sync', true);

        $previous = app('request');
        app()->instance('request', $sub);
        try {
            $response = app()->call([app(PosController::class), 'checkout'], ['request' => $sub]);
            $body = json_decode($response->getContent(), true) ?: [];
            $ok = $response->getStatusCode() < 400 && ($body['success'] ?? false);
            $message = $ok ? null : ($body['message'] ?? 'เซิร์ฟเวอร์ตอบกลับรหัส '.$response->getStatusCode());
        } catch (ValidationException $e) {
            $ok = false;
            $body = [];
            $message = collect($e->errors())->flatten()->first() ?: $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
            $body = [];
            $message = 'ลงบิลไม่สำเร็จ: '.$e->getMessage();
        } finally {
            app()->instance('request', $previous);
        }

        $sale->update([
            'status' => $ok ? PosOfflineSale::POSTED : PosOfflineSale::NEEDS_REVIEW,
            'pos_receipt_id' => $ok ? ($body['receipt_id'] ?? null) : null,
            'receipt_no' => $ok ? ($body['receipt_no'] ?? $body['doc_number'] ?? null) : null,
            'review_reason' => $ok ? null : $message,
            'shift_id' => $payload['shift_id'] ?? $sale->shift_id,
            'attempts' => $sale->attempts + 1,
        ]);

        return $this->result($sale->fresh());
    }

    private function result(PosOfflineSale $sale, ?string $message = null): array
    {
        return [
            'offline_uuid' => $sale->offline_uuid,
            'status' => $sale->status,
            'receipt_no' => $sale->receipt_no,
            'message' => $message ?? $sale->review_reason,
        ];
    }
}
