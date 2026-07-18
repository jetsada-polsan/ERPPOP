<?php

namespace App\Http\Middleware;

use App\Models\PosDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * เกตของ /api/pos/*: อ่าน Bearer token → หา device (ยังไม่ถูกเพิกถอน) →
 * login แทน cashier user ของ device เพื่อให้ controller เดิม (auth()->user())
 * ทำงานได้ทั้งหมด. user ต้องมีสิทธิ์ pos.sell จึงจะผ่าน
 */
class AuthenticatePosDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['success' => false, 'message' => 'ไม่พบ token อุปกรณ์'], 401);
        }

        $device = PosDevice::with('user')
            ->where('token_hash', PosDevice::hashToken($token))
            ->first();

        if (! $device || ! $device->isActive()) {
            return response()->json(['success' => false, 'message' => 'token อุปกรณ์ไม่ถูกต้องหรือถูกเพิกถอน'], 401);
        }

        $user = $device->user;
        if (! $user || ! $user->is_active) {
            return response()->json(['success' => false, 'message' => 'บัญชีผู้ใช้ของอุปกรณ์ถูกปิดใช้งาน'], 403);
        }
        if (! $user->hasPermission('pos.sell')) {
            return response()->json(['success' => false, 'message' => 'บัญชีนี้ไม่มีสิทธิ์ขายหน้า POS'], 403);
        }

        Auth::setUser($user);
        $request->attributes->set('pos_device', $device);

        // อัปเดต last seen แบบไม่ให้กระทบ response (ไม่ trigger updated_at ของ device เกินจำเป็น)
        $device->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
        ])->saveQuietly();

        return $next($request);
    }
}
