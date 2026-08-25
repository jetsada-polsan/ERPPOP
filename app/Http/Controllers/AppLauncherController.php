<?php

namespace App\Http\Controllers;

use App\Support\ErpMenu;
use Illuminate\View\View;

/**
 * หน้ารวมโมดูลแบบ App Launcher
 *
 * ไม่มี query และไม่มี endpoint ใหม่ — ส่งเมนูชุดเดียวกับที่ layout ใช้
 * ซึ่งกรองสิทธิ์มาแล้วเข้าไปให้ Vue เรนเดอร์ กดการ์ดแล้วไป route เดิมของ ERP
 */
class AppLauncherController extends Controller
{
    public function index(): View
    {
        $sections = ErpMenu::forUser(auth()->user());

        $payload = array_map(fn (array $section) => [
            'label' => $section['label'],
            'title' => $section['displayLabel'] ?? $section['label'],
            'icon' => ErpMenu::SECTION_ICONS[$section['label']] ?? 'bi-grid-fill',
            'items' => array_map(fn (array $item) => [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'tone' => $item['tone'] ?? 'blue',
                'url' => route($item['route'], $item['params'] ?? []),
                'target' => $item['target'] ?? null,
            ], $section['items']),
        ], $sections);

        return view('apps.index', ['sections' => $payload]);
    }
}
