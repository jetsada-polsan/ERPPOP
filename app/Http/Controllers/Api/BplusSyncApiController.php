<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bplus\BplusDocumentMaterializer;
use App\Services\Bplus\BplusSyncImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * รับเอกสาร BPlus จากเครื่องในร้าน (scripts/bplus-auto-sync.py) แทนการอัปโหลดไฟล์ที่หน้า /bplus/sync
 * ใช้ token จาก `php artisan bplus:sync-token` ส่งได้เฉพาะสายของ token นั้น
 * ตัวไฟล์รูปแบบเดียวกับที่อัปโหลด บีบอัด gzip ได้ (Content-Encoding: gzip)
 */
class BplusSyncApiController extends Controller
{
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
        if ($parsed['route'] !== $token->route_code) {
            return $this->finish($token, $parsed['route'], 'rejected', 403, "token นี้ส่งได้เฉพาะสาย {$token->route_code}");
        }

        // กันสองรอบทับกัน (เช่นรอบเที่ยงยังไม่เสร็จแล้วมีคนกดส่งเอง)
        $lock = Cache::lock('bplus-sync:'.$parsed['route'], 600);
        if (! $lock->get()) {
            return $this->finish($token, $parsed['route'], 'busy', 409, 'มีการซิงค์สายนี้อยู่ ลองใหม่ภายหลัง');
        }
        try {
            $received = $importer->import($parsed);
            $result = $materializer->run($parsed['route']);
        } catch (Throwable $e) {
            report($e);

            return $this->finish($token, $parsed['route'], 'failed', 500, $e->getMessage());
        } finally {
            $lock->release();
        }

        return $this->finish($token, $parsed['route'], 'ok', 200, null, $received, $result['documents'], $result['problems']);
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
