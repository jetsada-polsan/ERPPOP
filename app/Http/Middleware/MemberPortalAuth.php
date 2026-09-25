<?php
namespace App\Http\Middleware;
use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class MemberPortalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('member_portal_member_id');
        $member = $id ? Member::whereKey($id)->where('is_active', true)->first() : null;
        if (! $member) return response()->json(['message' => 'กรุณาเข้าสู่ระบบสมาชิกผ่าน LINE'], 401);
        $request->attributes->set('member_portal_member', $member);
        return $next($request);
    }
}
