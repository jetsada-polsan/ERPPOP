<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'username.required' => 'กรุณากรอกชื่อผู้ใช้',
            'password.required' => 'กรุณากรอกรหัสผ่าน',
        ]);

        // กันเดารหัสผ่าน: 5 ครั้ง/นาที ต่อ username+IP
        $throttleKey = 'login:'.Str::lower($credentials['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->withErrors([
                'username' => 'ลองเข้าสู่ระบบผิดหลายครั้ง กรุณารอ '.RateLimiter::availableIn($throttleKey).' วินาที',
            ])->onlyInput('username');
        }

        $identifier = trim($credentials['username']);
        $lookup = Str::lower($identifier);
        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [$lookup])
            ->orWhereRaw('LOWER(email) = ?', [$lookup])
            ->orWhere('phone', $identifier)
            ->first();

        $ok = $user && $user->is_active && Hash::check($credentials['password'], $user->password);

        if (! $ok) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['username' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'])->onlyInput('username');
        }

        RateLimiter::clear($throttleKey);
        if ($user->mfa_enabled_at && $user->mfa_secret) {
            $request->session()->regenerate();
            $request->session()->put([
                'mfa_user_id' => $user->id,
                'mfa_remember' => $request->boolean('remember'),
                'mfa_intended' => $request->session()->pull('url.intended', route('dashboard')),
            ]);

            return redirect()->route('mfa.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $this->recordLogin($user, $request, 'login');

        return redirect()->intended(route('dashboard'));
    }

    public function showMfaChallenge(Request $request): View|RedirectResponse
    {
        return $request->session()->has('mfa_user_id')
            ? view('auth.mfa-challenge')
            : redirect()->route('login');
    }

    public function verifyMfaChallenge(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = User::find($request->session()->get('mfa_user_id'));
        $key = 'mfa:'.($user?->id ?: 'unknown').'|'.$request->ip();
        if (! $user || RateLimiter::tooManyAttempts($key, 5) || ! $totp->verify((string) $user->mfa_secret, $data['code'])) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['code' => 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('mfa_remember', false);
        $intended = $request->session()->pull('mfa_intended', route('dashboard'));
        $request->session()->forget('mfa_user_id');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $this->recordLogin($user, $request, 'mfa_login');

        return redirect()->to($intended);
    }

    public function showMfaSetup(Request $request, TotpService $totp): View
    {
        $secret = $request->session()->get('mfa_setup_secret');
        if (! $secret) {
            $secret = $totp->generateSecret();
            $request->session()->put('mfa_setup_secret', $secret);
        }

        return view('auth.mfa-setup', [
            'secret' => $secret,
            'uri' => $totp->uri($secret, $request->user()->username),
        ]);
    }

    public function enableMfa(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'code' => ['required', 'digits:6'],
        ]);
        $secret = (string) $request->session()->get('mfa_setup_secret');
        if (! $secret || ! $totp->verify($secret, $data['code'])) {
            return back()->withErrors(['code' => 'รหัสจากแอป Authenticator ไม่ถูกต้อง']);
        }

        $request->user()->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now()])->save();
        $request->session()->forget('mfa_setup_secret');
        $this->audit($request->user(), 'mfa_enable', $request);

        return redirect()->route('operations.index')->with('success', 'เปิด MFA แล้ว การเข้าสู่ระบบครั้งต่อไปต้องใช้รหัส 6 หลัก');
    }

    public function disableMfa(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'code' => ['required', 'digits:6'],
        ]);
        if (! $request->user()->mfa_secret || ! $totp->verify($request->user()->mfa_secret, $data['code'])) {
            return back()->withErrors(['code' => 'รหัสจากแอป Authenticator ไม่ถูกต้อง']);
        }

        $request->user()->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null])->save();
        $this->audit($request->user(), 'mfa_disable', $request);

        return redirect()->route('operations.index')->with('success', 'ปิด MFA แล้ว');
    }

    public function showChangePassword(): View
    {
        return view('auth.change-password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
        ], [
            'current_password.required' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
            'current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่',
            'password.confirmed' => 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน',
        ]);

        $request->user()->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function recordLogin(User $user, Request $request, string $action): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit($user, $action, $request);
    }

    private function audit(User $user, string $action, Request $request): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'action' => $action,
            'table_name' => 'users',
            'record_id' => $user->id,
            'new_values' => ['ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 250)],
        ]);
    }
}
