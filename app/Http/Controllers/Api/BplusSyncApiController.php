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
        try {
            [$received, $result] = DB::transaction(function () use ($token, $parsed, $importer, $materializer) {
                if ($token->route_code === null) {
                    $this->ensureSalesRoute($parsed['route']);
                }

                return [$importer->import($parsed), $materializer->run($parsed['route'])];
            });
        } catch (Throwable $e) {
            report($e);

            return $this->finish($token, $parsed['route'], 'failed', 500, $e->getMessage());
        } finally {
            $lock->release();
        }

        return $this->finish($token, $parsed['route'], 'ok', 200, null, $received, $result['documents'], $result['problems']);
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
