<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bplus\BplusDocumentMaterializer;
use App\Services\Bplus\BplusSyncImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * รับเอกสาร BPlus จากเครื่องในร้าน (scripts/bplus-auto-sync.py) แทนการอัปโหลดไฟล์ที่หน้า /bplus/sync
 * ใช้ token จาก `php artisan bplus:sync-token` ส่งได้เฉพาะสายของ token นั้น
 * ตัวไฟล์รูปแบบเดียวกับที่อัปโหลด บีบอัด gzip ได้ (Content-Encoding: gzip)
 */
class BplusSyncApiController extends Controller
{
    /** Read-only connection check used by the bplusAPI settings page. */
    public function ping(Request $request): JsonResponse
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : DB::table('bplus_sync_tokens')
            ->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first(['id']);

        if (! $token) {
            return response()->json(['ok' => false, 'message' => 'token ไม่ถูกต้องหรือถูกเพิกถอน'], 401);
        }

        return response()->json(['ok' => true, 'message' => 'เชื่อมต่อ ERP สำเร็จ']);
    }

    /** ผลการบันทึกของรอบที่ token นี้ส่งมา: processing / ok / failed */
    public function showRun(Request $request, int $run): JsonResponse
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : DB::table('bplus_sync_tokens')
            ->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first(['id']);
        if (! $token) {
            return response()->json(['ok' => false, 'message' => 'token ไม่ถูกต้องหรือถูกเพิกถอน'], 401);
        }

        $row = DB::table('bplus_sync_runs')->where('id', $run)->where('bplus_sync_token_id', $token->id)->first();
        if (! $row) {
            return response()->json(['ok' => false, 'message' => 'ไม่พบรอบนี้'], 404);
        }

        return response()->json([
            'ok' => $row->status !== 'failed',
            'id' => $row->id,
            'status' => $row->status,
            'route' => $row->route_code,
            'received' => (int) $row->received,
            'erp_documents' => (int) $row->erp_documents,
            'problems' => (int) $row->problems,
            'new_documents' => $row->new_documents === null ? null : (int) $row->new_documents,
            'changed_documents' => $row->changed_documents === null ? null : (int) $row->changed_documents,
            'unchanged_documents' => $row->unchanged_documents === null ? null : (int) $row->unchanged_documents,
            'message' => $row->message,
            'started_at' => $row->created_at,
            'finished_at' => $row->status === 'processing' ? null : $row->updated_at,
        ]);
    }

    public function store(Request $request, BplusSyncImporter $importer, BplusDocumentMaterializer $materializer): JsonResponse
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : DB::table('bplus_sync_tokens')->where('token_hash', hash('sha256', $plain))->first();
        if (! $token || $token->revoked_at) {
            return response()->json(['ok' => false, 'message' => 'token ไม่ถูกต้องหรือถูกเพิกถอน'], 401);
        }
        DB::table('bplus_sync_tokens')->where('id', $token->id)->update(['last_used_at' => now(), 'last_ip' => $request->ip()]);

        $body = $request->getContent();
        if (strtolower((string) $request->header('Content-Encoding')) === 'gzip') {
            $body = @gzdecode($body);
            if ($body === false) {
                return response()->json(['ok' => false, 'message' => 'ข้อมูล gzip เสีย'], 422);
            }
        }

        try {
            $parsed = $importer->parse($body);
        } catch (InvalidArgumentException $e) {
            return $this->finish($token, $token->route_code, 'rejected', 422, $e->getMessage());
        }
        if (! preg_match('/^[A-Z0-9_-]{1,20}$/', $parsed['route'])) {
            return $this->finish($token, $parsed['route'], 'rejected', 422, 'รหัสสายไม่ถูกต้อง');
        }
        if ($token->route_code !== null && $parsed['route'] !== $token->route_code) {
            return $this->finish($token, $parsed['route'], 'rejected', 403, "token นี้ส่งได้เฉพาะสาย {$token->route_code}");
        }

        // กันสองรอบทับกัน (เช่นรอบเที่ยงยังไม่เสร็จแล้วมีคนกดส่งเอง)
        $lock = Cache::lock('bplus-sync:'.$parsed['route'], 600);
        if (! $lock->get()) {
            return $this->finish($token, $parsed['route'], 'busy', 409, 'มีการซิงค์สายนี้อยู่ ลองใหม่ภายหลัง');
        }
        // ทำงานหลังตอบกลับแล้ว: ทั้งสายใช้เวลาเกิน 60 วินาที (504 จาก gateway) เครื่องในร้านไม่ต้องรอ
        // ผลจริงดูที่ bplus_sync_runs (processing -> ok/failed) หน้า "เอกสารสายขาย" แสดงรอบล่าสุด
        $runId = $this->record($token, $parsed['route'], 'processing', null, count($parsed['documents']));
        \Illuminate\Support\defer(function () use ($token, $importer, $materializer, $parsed, $lock, $runId) {
            @set_time_limit(900);
            // รอบเต็มกลางคืนส่งทุกใบตั้งแต่วันเริ่มสาย ไฟล์โตขึ้นทุกเดือน ค่าเริ่มต้น 128M ของ php-fpm จะไม่พอ (เครื่องมี RAM 2GB)
            @ini_set('memory_limit', '512M');
            try {
                [$received, $result, $breakdown] = DB::transaction(function () use ($token, $parsed, $importer, $materializer) {
                    if ($token->route_code === null) {
                        $this->ensureSalesRoute($parsed['route']);
                    }
                    $keys = array_values(array_unique(array_map(fn ($d) => (int) $d['bplus_key'], $parsed['documents'])));
                    $known = DB::table('bplus_sync_documents')->whereIn('bplus_key', $keys)->pluck('bplus_key')->map(fn ($k) => (int) $k)->all();
                    $received = $importer->import($parsed);
                    $result = $materializer->run($parsed['route']);

                    return [$received, $result, $this->breakdown($keys, $known, $result['processed_keys'])];
                });
                DB::table('bplus_sync_runs')->where('id', $runId)->update([
                    'status' => 'ok', 'received' => $received, 'erp_documents' => $result['documents'],
                    'problems' => count($result['problems']), 'updated_at' => now(),
                ] + $breakdown);
            } catch (Throwable $e) {
                report($e);
                DB::table('bplus_sync_runs')->where('id', $runId)->update([
                    'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now(),
                ]);
            } finally {
                $lock->release();
            }
        });

        return response()->json([
            'ok' => true, 'status' => 'processing', 'run_id' => $runId, 'received' => count($parsed['documents']),
            'message' => 'ERP รับข้อมูลแล้ว กำลังบันทึกเบื้องหลัง ดูผลที่หน้าเอกสารสายขาย',
        ], 202);
    }

    /**
     * แยกใบที่ได้รับรอบนี้: ใหม่ = ยังไม่เคยมีใน ERP, แก้ไข = มีแล้วแต่เนื้อหาเปลี่ยน (ERP บันทึกซ้ำ), เหมือนเดิม = ข้าม
     *
     * @param  array<int, int>  $keys
     * @param  array<int, int>  $known
     * @param  array<int, int>  $processed
     * @return array{new_documents: int, changed_documents: int, unchanged_documents: int}
     */
    private function breakdown(array $keys, array $known, array $processed): array
    {
        $known = array_flip($known);
        $processed = array_flip($processed);
        $new = $changed = 0;
        foreach ($keys as $key) {
            if (! isset($known[$key])) {
                $new++;
            } elseif (isset($processed[$key])) {
                $changed++;
            }
        }

        return ['new_documents' => $new, 'changed_documents' => $changed, 'unchanged_documents' => count($keys) - $new - $changed];
    }

    /** Provision only routes missing from sales_areas, and only for an all-routes token. */
    private function ensureSalesRoute(string $route): void
    {
        if (DB::table('sales_areas')->where('code', $route)->exists()) {
            return;
        }

        $headOfficeId = DB::table('branches')->where('code', 'HQ')->value('id');
        $bookingTypeId = DB::table('document_types')->where('code', 'BOOKING')->value('id');
        if (! $headOfficeId || ! $bookingTypeId) {
            throw new \RuntimeException('ไม่พบสาขา HQ หรือประเภทเอกสารใบจองใน ERP');
        }

        $book = DB::table('document_books')->where('document_type_id', $bookingTypeId)->where('code', $route)->first();
        if (! $book) {
            $bookId = DB::table('document_books')->insertGetId([
                'code' => $route,
                'name' => 'ใบจอง '.$route,
                'document_type_id' => $bookingTypeId,
                'prefix' => $route,
                'is_default' => false,
                'is_active' => true,
                ...(Schema::hasColumn('document_books', 'no_vat') ? ['no_vat' => false] : []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $bookId = $book->id;
        }

        DB::table('sales_areas')->insert([
            'code' => $route,
            'name' => 'ใบจอง '.$route,
            'area_type' => 'route',
            'branch_id' => $headOfficeId,
            'default_salesman_id' => null,
            'document_book_id' => $bookId,
            'is_active' => true,
        ]);
    }

    private function record(object $token, string $route, string $status, ?string $message, int $received = 0): int
    {
        return DB::table('bplus_sync_runs')->insertGetId([
            'bplus_sync_token_id' => $token->id,
            'route_code' => mb_substr($route, 0, 20),
            'status' => $status,
            'received' => $received,
            'erp_documents' => 0,
            'problems' => 0,
            'message' => $message ? mb_substr($message, 0, 1000) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finish(object $token, string $route, string $status, int $http, ?string $message,
        int $received = 0, int $documents = 0, array $problems = []): JsonResponse
    {
        DB::table('bplus_sync_runs')->insert([
            'bplus_sync_token_id' => $token->id,
            'route_code' => mb_substr($route, 0, 20),
            'status' => $status,
            'received' => $received,
            'erp_documents' => $documents,
            'problems' => count($problems),
            'message' => $message ? mb_substr($message, 0, 1000) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => $status === 'ok',
            'status' => $status,
            'message' => $message,
            'received' => $received,
            'erp_documents' => $documents,
            'problems' => $problems,
        ], $http);
    }
}
