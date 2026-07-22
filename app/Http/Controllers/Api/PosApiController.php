<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PosController;
use App\Models\AppSetting;
use App\Models\PosReceipt;
use App\Models\Salesman;
use App\Services\Sales\SaleReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ทางเข้า API สำหรับ POS desktop (Tauri). auth ผ่าน AuthenticatePosDevice
 * (Bearer token → login แทน cashier user) แล้ว delegate ไปตรรกะเดิมใน PosController
 * ส่วน checkout ห่อ idempotency: บิลเดิม (idempotency key เดิม) จะได้ response เดิมเป๊ะ
 * ไม่สร้างซ้ำ — จำเป็นสำหรับ offline-first ที่ client retry ได้
 */
class PosApiController extends Controller
{
    /** health check + บอก client ว่า device นี้ผูกสาขา/พนักงานอะไร */
    public function ping(Request $request): JsonResponse
    {
        $device = $request->attributes->get('pos_device');
        $user = $request->user();
        $user?->loadMissing('branch');

        return response()->json([
            'success' => true,
            'server_time' => now()->toIso8601String(),
            'device' => [
                'id' => $device?->id,
                'name' => $device?->name,
                'terminal_code' => $device?->terminal_code,
            ],
            'branch_id' => $user?->branch_id,
            'branch_name' => $user?->branch?->name_th,
            'device_user' => $user?->name,
            // หัวบิลใบกำกับภาษีอย่างย่อ (มาตรา 86/6) — desktop แคชไว้พิมพ์ใบเสร็จได้แม้ออฟไลน์
            'company' => [
                'name' => AppSetting::company('name_th'),
                'tax_id' => AppSetting::company('tax_id'),
                'address' => AppSetting::company('address'),
                'phone' => AppSetting::company('phone'),
            ],
            'vat_rate' => (float) (DB::table('vat_rates')
                ->where('effective_from', '<=', now()->toDateString())
                ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
                ->orderByDesc('effective_from')->value('rate_percent') ?? 7.0),
        ]);
    }

    public function cashiers(Request $request): JsonResponse
    {
        $device = $request->attributes->get('pos_device');
        $branchId = $device?->branch_id ?: $request->user()?->branch_id;

        $cashiers = Salesman::query()
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where(fn ($w) => $w
                ->whereNull('branch_id')
                ->orWhere('branch_id', $branchId)))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'branch_id']);

        return response()->json(['success' => true, 'cashiers' => $cashiers]);
    }

    public function cashierLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'min:4', 'max:20'],
        ]);

        $device = $request->attributes->get('pos_device');
        $branchId = $device?->branch_id ?: $request->user()?->branch_id;
        $cashier = Salesman::query()
            ->where('code', $data['code'])
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where(fn ($w) => $w
                ->whereNull('branch_id')
                ->orWhere('branch_id', $branchId)))
            ->first();

        if (! $cashier) {
            return response()->json(['success' => false, 'message' => 'ไม่พบแคชเชียร์ในสาขานี้'], 422);
        }
        if (! $cashier->pos_pin_hash) {
            return response()->json(['success' => false, 'message' => 'แคชเชียร์นี้ยังไม่ได้ตั้ง PIN POS'], 422);
        }
        if (! Hash::check($data['pin'], $cashier->pos_pin_hash)) {
            return response()->json(['success' => false, 'message' => 'PIN ไม่ถูกต้อง'], 422);
        }

        return response()->json([
            'success' => true,
            'cashier' => [
                'id' => $cashier->id,
                'code' => $cashier->code,
                'name' => $cashier->name,
                'branch_id' => $cashier->branch_id,
            ],
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $key = trim((string) ($request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key') ?: ''));
        if ($key === '') {
            return response()->json(['success' => false, 'message' => 'ต้องส่ง Idempotency-Key'], 400);
        }
        if (strlen($key) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            return response()->json(['success' => false, 'message' => 'Idempotency-Key ไม่ถูกต้อง'], 400);
        }

        $device = $request->attributes->get('pos_device');
        if (! $device) {
            return response()->json(['success' => false, 'message' => 'ไม่พบอุปกรณ์ POS'], 401);
        }

        $request->merge(['allow_negative_stock' => true]);
        $requestHash = $this->payloadHash($request->all());

        return DB::transaction(function () use ($request, $key, $device, $requestHash) {
            // จอง key ใน transaction เดียวกับการสร้างบิล คำขอที่แข่งกันจะรอและ replay ผลเดิม
            $inserted = DB::table('pos_api_idempotency')->insertOrIgnore([
                'idempotency_key' => $key,
                'pos_device_id' => $device->id,
                'endpoint' => 'checkout',
                'request_hash' => $requestHash,
                'state' => 'processing',
                'status_code' => 102,
                'response_body' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! $inserted) {
                $existing = DB::table('pos_api_idempotency')
                    ->where('idempotency_key', $key)->lockForUpdate()->first();
                if (! $existing || (int) $existing->pos_device_id !== (int) $device->id) {
                    return response()->json(['success' => false, 'message' => 'คีย์บิลนี้เป็นของ POS อีกเครื่อง'], 409);
                }
                if ($existing->request_hash && ! hash_equals($existing->request_hash, $requestHash)) {
                    return response()->json(['success' => false, 'message' => 'คีย์บิลเดิมถูกใช้กับข้อมูลคนละชุด กรุณาตรวจบิลค้าง'], 409);
                }
                if (($existing->state ?? 'completed') === 'completed') {
                    return response()->json(json_decode($existing->response_body, true), (int) $existing->status_code);
                }

                return response()->json(['success' => false, 'message' => 'บิลนี้กำลังประมวลผล ให้ระบบส่งซ้ำอีกครั้ง'], 409);
            }

            /** @var JsonResponse $response */
            $response = app()->call([app(PosController::class), 'checkout'], ['request' => $request]);

            if ($response->getStatusCode() >= 400) {
                DB::table('pos_api_idempotency')->where('idempotency_key', $key)->delete();
                Log::warning('POS desktop checkout rejected', [
                    'idempotency_key' => $key,
                    'device_id' => $device->id,
                    'status' => $response->getStatusCode(),
                    'response' => json_decode($response->getContent(), true),
                    'branch_id' => $request->input('branch_id'),
                    'cashier_id' => $request->input('cashier_id'),
                    'shift_id' => $request->input('shift_id'),
                    'items' => $request->input('items'),
                ]);

                return $response;
            }

            DB::table('pos_api_idempotency')->where('idempotency_key', $key)->update([
                'state' => 'completed',
                'status_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    public function voidReceipt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receipt_no' => ['required', 'string', 'max:80'],
            'terminal_code' => ['nullable', 'string', 'max:80'],
            'shift_id' => ['required', 'integer', 'exists:pos_shifts,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $receipt = PosReceipt::with(['shift', 'terminal'])
            ->where('receipt_no', $data['receipt_no'])
            ->when($data['terminal_code'] ?? null, fn ($query, $terminalCode) => $query
                ->whereHas('terminal', fn ($terminal) => $terminal->where('code', $terminalCode)))
            ->first();

        if (! $receipt) {
            return response()->json(['success' => false, 'message' => 'ไม่พบบิลที่ต้องการยกเลิก'], 404);
        }

        $user = $request->user();
        if ($user?->branch_id && $receipt->shift?->branch_id && (int) $receipt->shift->branch_id !== (int) $user->branch_id) {
            return response()->json(['success' => false, 'message' => 'ยกเลิกบิลต่างสาขาไม่ได้'], 403);
        }

        /** @var JsonResponse $response */
        $response = app()->call([app(PosController::class), 'voidReceipt'], [
            'request' => $request,
            'receipt' => $receipt,
        ]);

        return $response;
    }

    public function returnReceipt(Request $request, SaleReturnService $service): JsonResponse
    {
        if (! auth()->user()?->hasPermission('pos.void')) {
            return response()->json(['success' => false, 'message' => 'เฉพาะผู้จัดการสาขาที่รับคืนสินค้า POS ได้'], 403);
        }

        $data = $request->validate([
            'receipt_no' => ['required', 'string', 'max:80'],
            'terminal_code' => ['nullable', 'string', 'max:80'],
            'shift_id' => ['nullable', 'integer', 'exists:pos_shifts,id'],
            'reason' => ['required', 'string', 'max:500'],
            'refund_method' => ['required', 'string', 'in:cash,transfer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            $result = DB::transaction(function () use ($data, $service) {
                $receipt = PosReceipt::with(['terminal', 'shift', 'items'])
                    ->where('receipt_no', $data['receipt_no'])
                    ->when($data['terminal_code'] ?? null, fn ($query, $terminalCode) => $query
                        ->whereHas('terminal', fn ($terminal) => $terminal->where('code', $terminalCode)))
                    ->lockForUpdate()
                    ->first();

                if (! $receipt) {
                    throw new RuntimeException('ไม่พบบิลที่ต้องการรับคืน');
                }
                if ($receipt->status !== 'completed') {
                    throw new RuntimeException('รับคืนได้เฉพาะบิลที่สมบูรณ์และยังไม่ถูกยกเลิก');
                }

                $user = request()->user();
                $branchId = $receipt->terminal?->branch_id ?? $receipt->shift?->branch_id;
                if ($user?->branch_id && $branchId && (int) $branchId !== (int) $user->branch_id) {
                    throw new RuntimeException('รับคืนบิลต่างสาขาไม่ได้');
                }
                if (! $branchId) {
                    throw new RuntimeException('ไม่พบสาขาของบิล POS');
                }

                // กะที่จะหักเงินคืน ต้องเป็นกะที่ "เปิดอยู่" และเป็น "สาขาเดียวกับบิล" เท่านั้น
                // (กันส่ง shift_id มั่วไปลดยอดเงินสดของกะอื่น/สาขาอื่น — refreshShiftTotals เขียนทับยอดกะนั้น)
                if (! empty($data['shift_id'])) {
                    $refundShift = DB::table('pos_shifts')->where('id', $data['shift_id'])->first();
                    if (! $refundShift || $refundShift->status !== 'open' || (int) $refundShift->branch_id !== (int) $branchId) {
                        throw new RuntimeException('กะสำหรับคืนเงินไม่ถูกต้อง ต้องเป็นกะที่เปิดอยู่ของสาขานี้');
                    }
                }

                $requested = collect($data['items'])
                    ->groupBy('product_id')
                    ->map(fn ($rows) => round($rows->sum(fn ($row) => (float) $row['qty']), 4))
                    ->filter(fn ($qty) => $qty > 0);

                if ($requested->isEmpty()) {
                    throw new RuntimeException('ต้องมีจำนวนคืนอย่างน้อย 1 รายการ');
                }

                $sold = $receipt->items
                    ->groupBy('product_id')
                    ->map(function ($rows) {
                        $qty = $rows->sum(fn ($row) => (float) $row->qty);
                        $amount = $rows->sum(fn ($row) => (float) $row->qty * (float) $row->unit_price);

                        return [
                            'qty' => round($qty, 4),
                            'unit_price' => $qty > 0 ? round($amount / $qty, 4) : 0,
                            'item_id' => $rows->first()?->id,
                        ];
                    });

                $returned = DB::table('pos_receipt_return_items')
                    ->join('pos_receipt_returns', 'pos_receipt_returns.id', '=', 'pos_receipt_return_items.pos_receipt_return_id')
                    ->where('pos_receipt_returns.pos_receipt_id', $receipt->id)
                    ->where('pos_receipt_returns.status', 'completed')
                    ->selectRaw('pos_receipt_return_items.product_id, sum(pos_receipt_return_items.qty) as qty')
                    ->groupBy('pos_receipt_return_items.product_id')
                    ->pluck('qty', 'product_id');

                $returnItems = [];
                foreach ($requested as $productId => $qty) {
                    $line = $sold->get((int) $productId);
                    if (! $line) {
                        throw new RuntimeException("สินค้า #{$productId} ไม่อยู่ในบิลนี้");
                    }

                    $remaining = round((float) $line['qty'] - (float) ($returned[$productId] ?? 0), 4);
                    if ($qty > $remaining + 0.0001) {
                        throw new RuntimeException("คืนสินค้า #{$productId} เกินจำนวนคงเหลือในบิล");
                    }

                    $returnItems[] = [
                        'product_id' => (int) $productId,
                        'qty' => $qty,
                        'unit_price' => (float) $line['unit_price'],
                        'pos_receipt_item_id' => $line['item_id'],
                    ];
                }

                $document = $service->create([
                    'branch_id' => (int) $branchId,
                    'customer_id' => null,
                    'customer_open_item_id' => null,
                    'remark' => trim('POS return '.$receipt->receipt_no.': '.$data['reason']),
                    'items' => array_map(fn ($item) => [
                        'product_id' => $item['product_id'],
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                    ], $returnItems),
                ]);

                $totalAmount = round(collect($returnItems)->sum(fn ($item) => $item['qty'] * $item['unit_price']), 4);
                $returnId = DB::table('pos_receipt_returns')->insertGetId([
                    'pos_receipt_id' => $receipt->id,
                    'pos_shift_id' => $data['shift_id'] ?? null,
                    'document_id' => $document->id,
                    'return_no' => $document->doc_number,
                    'returned_at' => now(),
                    'returned_by' => auth()->id(),
                    'refund_method' => $data['refund_method'],
                    'total_amount' => $totalAmount,
                    'status' => 'completed',
                    'reason' => $data['reason'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($returnItems as $item) {
                    DB::table('pos_receipt_return_items')->insert([
                        'pos_receipt_return_id' => $returnId,
                        'pos_receipt_item_id' => $item['pos_receipt_item_id'],
                        'product_id' => $item['product_id'],
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'amount' => round($item['qty'] * $item['unit_price'], 4),
                    ]);
                }

                DB::table('audit_logs')->insert([
                    'user_id' => auth()->id(),
                    'branch_id' => $branchId,
                    'action' => 'return',
                    'table_name' => 'pos_receipt_returns',
                    'record_id' => $returnId,
                    'old_values' => null,
                    'new_values' => json_encode([
                        'receipt_no' => $receipt->receipt_no,
                        'return_no' => $document->doc_number,
                        'refund_method' => $data['refund_method'],
                        'total_amount' => $totalAmount,
                        'reason' => $data['reason'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);

                if (! empty($data['shift_id'])) {
                    $this->refreshShiftTotals((int) $data['shift_id']);
                }

                return [
                    'return_no' => $document->doc_number,
                    'receipt_no' => $receipt->receipt_no,
                    'total_amount' => $totalAmount,
                ];
            });
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'รับคืนสินค้าเรียบร้อย',
            ...$result,
        ]);
    }

    private function refreshShiftTotals(int $shiftId): void
    {
        $sales = DB::table('pos_payments')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_payments.pos_receipt_id')
            ->where('pos_receipts.pos_shift_id', $shiftId)
            ->where('pos_receipts.status', 'completed')
            ->selectRaw('pos_payments.method, sum(pos_payments.amount) as total')
            ->groupBy('pos_payments.method')
            ->pluck('total', 'method');

        $returns = DB::table('pos_receipt_returns')
            ->where('pos_shift_id', $shiftId)
            ->where('status', 'completed')
            ->selectRaw('refund_method, sum(total_amount) as total')
            ->groupBy('refund_method')
            ->pluck('total', 'refund_method');

        $cash = round((float) ($sales['cash'] ?? 0) - (float) ($returns['cash'] ?? 0), 2);
        $transfer = round((float) ($sales['transfer'] ?? 0) - (float) ($returns['transfer'] ?? 0), 2);
        $shift = DB::table('pos_shifts')->where('id', $shiftId)->first();
        if (! $shift) {
            return;
        }

        DB::table('pos_shifts')->where('id', $shiftId)->update([
            'cash_sales' => $cash,
            'transfer_sales' => $transfer,
            'expected_cash' => round((float) $shift->opening_cash + $cash, 2),
            'updated_at' => now(),
        ]);
    }
}
