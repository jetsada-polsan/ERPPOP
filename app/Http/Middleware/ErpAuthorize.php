<?php

namespace App\Http\Middleware;

use App\Support\RoutePermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single gate for the whole ERP: guests go to the login page, disabled
 * accounts are logged out, and each module route requires its mapped
 * permission (see RoutePermissions). Runs on every web request.
 */
class ErpAuthorize
{
    private const PUBLIC_ROUTES = [
        'login', 'login.attempt', 'logout', 'pos.download',
        'pos.release.latest', 'pos.release.download',
    ];

    private const PASSWORD_CHANGE_ROUTES = ['password.change', 'password.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if (in_array($routeName, self::PUBLIC_ROUTES, true)) {
            return $next($request);
        }

        $user = Auth::user();

        if (! $user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'กรุณาเข้าสู่ระบบ'], 401)
                : redirect()->guest(route('login'));
        }

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['username' => 'บัญชีนี้ถูกปิดใช้งาน ติดต่อผู้ดูแลระบบ']);
        }

        if ($user->must_change_password && ! in_array($routeName, self::PASSWORD_CHANGE_ROUTES, true)) {
            return redirect()->route('password.change');
        }

        $permission = RoutePermissions::resolve($routeName);
        if ($permission && ! $user->hasPermission($permission)) {
            abort(403, 'บัญชีของคุณไม่มีสิทธิ์ใช้เมนูนี้ ติดต่อผู้ดูแลระบบเพื่อขอสิทธิ์เพิ่ม');
        }

        return $next($request);
    }
}
