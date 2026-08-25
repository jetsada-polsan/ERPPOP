<?php

namespace App\Http\Controllers;

use App\Support\AppMark;
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

        $payload = array_map(fn (array $section, int $sectionIndex) => [
            'label' => $section['label'],
            'title' => $section['displayLabel'] ?? $section['label'],
            'icon' => ErpMenu::SECTION_ICONS[$section['label']] ?? 'bi-grid-fill',
            'items' => array_map(fn (array $item, int $itemIndex) => [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'tone' => $item['tone'] ?? 'blue',
                // ตราสร้างฝั่งเซิร์ฟเวอร์ ให้เมนูข้างกับ launcher ใช้ชุดเดียวกันเสมอ
                'mark' => AppMark::forItem($sectionIndex, $itemIndex, $item['tone'] ?? 'blue'),
                'url' => route($item['route'], $item['params'] ?? []),
                'target' => $item['target'] ?? null,
            ], $section['items'], array_keys($section['items'])),
        ], $sections, array_keys($sections));

        return view('apps.index', ['sections' => $payload]);
    }
}
