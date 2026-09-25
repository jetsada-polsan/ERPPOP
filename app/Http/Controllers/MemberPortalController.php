<?php
namespace App\Http\Controllers;
use App\Models\Member;
use App\Models\MemberLineAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
class MemberPortalController extends Controller
{
    public function liff(): RedirectResponse
    {
        $liffId = trim((string) config('services.line.liff_id'));
        if ($liffId === '') {
            return redirect()->route('member.portal')->with('error', 'ยังไม่ได้ตั้งค่า LINE LIFF ID');
        }

        return redirect()->away('https://liff.line.me/'.rawurlencode($liffId));
    }

    public function page(): View { return view('member-portal.index', ['liffId' => config('services.line.liff_id')]); }
    public function authenticateLiff(Request $request): JsonResponse
    {
        $data = $request->validate(['id_token' => ['required', 'string', 'max:4096']]);
        $channelId = (string) config('services.line.login_channel_id');
        if ($channelId === '') return response()->json(['message' => 'ยังไม่ได้ตั้งค่า LINE Login Channel ID'], 503);
        $response = Http::asForm()->timeout(8)->post('https://api.line.me/oauth2/v2.1/verify', ['id_token' => $data['id_token'], 'client_id' => $channelId]);
        if (! $response->successful() || $response->json('aud') !== $channelId) return response()->json(['message' => 'LIFF token ไม่ถูกต้องหรือหมดอายุ'], 401);
        $link = MemberLineAccount::where('line_user_id', (string) $response->json('sub'))->where('is_active', true)->first();
        if (! $link || ! $link->member()->where('is_active', true)->exists()) return response()->json(['message' => 'บัญชี LINE นี้ยังไม่ได้ผูกกับสมาชิก'], 403);
        $request->session()->regenerate();
        $request->session()->put('member_portal_member_id', $link->member_id);
        return response()->json(['success' => true]);
    }
    public function logout(Request $request): JsonResponse { $request->session()->forget('member_portal_member_id'); return response()->json(['success' => true]); }
    public function me(Request $request): JsonResponse { return response()->json(['member' => $this->memberPayload($request->attributes->get('member_portal_member'))]); }
    public function points(Request $request): JsonResponse
    {
        $member = $request->attributes->get('member_portal_member');
        return response()->json(['points' => (float) $member->points, 'tier' => $member->memberType?->name ?? 'สมาชิก', 'next_tier_points' => null, 'progress_percent' => null]);
    }
    public function pointHistory(Request $request): JsonResponse
    {
        $items = $request->attributes->get('member_portal_member')->pointTransactions()->latest('created_at')->limit(50)->get()->map(fn ($i) => ['id' => $i->id, 'direction' => $i->direction, 'points' => (float) $i->points, 'note' => $i->note, 'created_at' => $i->created_at?->toIso8601String()]);
        return response()->json(['items' => $items]);
    }
    public function coupons(): JsonResponse { return response()->json(['items' => [], 'message' => 'ยังไม่มีคูปองที่พร้อมใช้งาน']); }
    public function rewards(): JsonResponse { return response()->json(['items' => [], 'message' => 'ยังไม่มีรางวัลที่พร้อมแลก']); }
    public function purchases(Request $request): JsonResponse
    {
        $items = $request->attributes->get('member_portal_member')->posReceipts()->where('status', 'completed')->whereNull('voided_at')->latest('receipt_date')->limit(50)->get()->map(fn ($r) => ['receipt_no' => $r->receipt_no, 'total' => (float) $r->net_sales, 'date' => $r->receipt_date?->toIso8601String()]);
        return response()->json(['items' => $items]);
    }
    private function memberPayload(Member $member): array { return ['name' => $member->name, 'member_code' => $member->member_code, 'points' => (float) $member->points, 'tier' => $member->memberType?->name ?? 'สมาชิก']; }
}
