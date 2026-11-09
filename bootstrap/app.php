<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pos.device' => \App\Http\Middleware\AuthenticatePosDevice::class,
            'member.portal' => \App\Http\Middleware\MemberPortalAuth::class,
        ]);
        // ประตูเดียวของทั้ง ERP: guest -> หน้า login, ผู้ใช้ถูกปิด -> เตะออก,
        // แต่ละเมนูเช็คสิทธิ์ตาม App\Support\RoutePermissions
        $middleware->appendToGroup('web', \App\Http\Middleware\ErpAuthorize::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A stale login tab should recover to a fresh CSRF token instead of
        // leaving the operator on the generic 419 page. CSRF remains enabled.
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if ($request->is('login') && $request->isMethod('post')) {
                return redirect()->route('login')
                    ->withInput($request->only('username'))
                    ->withErrors(['username' => 'หน้าเข้าสู่ระบบหมดอายุแล้ว กรุณาลองเข้าสู่ระบบอีกครั้ง']);
            }

            return null;
        });
        // abort(422/409/400, 'ข้อความไทย') ในฟอร์มหน้าเว็บ: บน production หน้า error มาตรฐานไม่แสดงข้อความ
        // ผู้ใช้เห็นแค่ "422 Unprocessable Content" ภาษาอังกฤษ → พากลับหน้าเดิมพร้อมแถบแจ้งเหตุผลภาษาไทยแทน
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();
            $referer = (string) $request->headers->get('referer', '');
            if (! in_array($status, [400, 409, 422], true) || $request->expectsJson() || $request->is('api/*')
                || $referer === '' || ! str_starts_with($referer, $request->getSchemeAndHttpHost())) {
                return null;
            }
            $message = trim($exception->getMessage()) ?: 'ทำรายการนี้ไม่ได้ กรุณาตรวจสอบข้อมูลแล้วลองใหม่';
            $target = $referer !== $request->fullUrl() ? $referer : url('/');

            return redirect()->to($target)->withInput($request->except(['password', 'password_confirmation', 'pin', '_token']))->with('error', $message);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
