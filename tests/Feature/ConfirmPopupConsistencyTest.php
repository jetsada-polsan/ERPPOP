<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ConfirmPopupConsistencyTest extends TestCase
{
    /**
     * ดึงไฟล์ blade ทั้งหมดใน resources/views
     *
     * @return array<string, string> path => content
     */
    private function getBladeFiles(): array
    {
        $dir = resource_path('views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[$file->getPathname()] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * ตัดคอมเมนต์ Blade, HTML และ JavaScript ออกเพื่อตรวจเฉพาะโค้ดจริง
     */
    private function stripComments(string $content): string
    {
        // Blade comments {{-- ... --}}
        $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content);
        // HTML comments <!-- ... -->
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        // JS block comments /* ... */
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        // JS line comments // ...
        $content = preg_replace('/\/\/[^\n]*/', '', $content);

        return $content;
    }

    /**
     * ข้อกำหนด: ไม่มี confirm() หรือ alert() ของเบราว์เซอร์ใน resources/views (นอก layout/component)
     */
    public function test_no_browser_confirm_or_alert_in_views(): void
    {
        $bladeFiles = $this->getBladeFiles();
        $violations = [];
        $allowedFiles = [
            resource_path('views/layout.blade.php'),
            resource_path('views/pos/index.blade.php'),
            resource_path('views/components/confirm-action.blade.php'),
        ];

        foreach ($bladeFiles as $path => $content) {
            if (in_array($path, $allowedFiles, true)) {
                continue;
            }

            $code = $this->stripComments($content);
            $relPath = str_replace(base_path() . '/', '', $path);

            if (preg_match('/\b(?:window\.)?confirm\s*\(/i', $code)) {
                $violations[] = "{$relPath} contains native confirm()";
            }

            if (preg_match('/\b(?:window\.)?alert\s*\(/i', $code)) {
                $violations[] = "{$relPath} contains native alert()";
            }
        }

        $this->assertEmpty($violations, "พบการเรียก confirm() หรือ alert() ดั้งเดิมของเบราว์เซอร์นอก layout:\n" . implode("\n", $violations));
    }

    /**
     * ข้อกำหนด: ไม่มี Swal.fire ที่มี showCancelButton อยู่นอก layout และ pos helper
     */
    public function test_no_swal_fire_confirmation_outside_central_helpers(): void
    {
        $bladeFiles = $this->getBladeFiles();
        $violations = [];
        $allowedFiles = [
            resource_path('views/layout.blade.php'),
            resource_path('views/pos/index.blade.php'),
        ];

        foreach ($bladeFiles as $path => $content) {
            if (in_array($path, $allowedFiles, true)) {
                continue;
            }

            $code = $this->stripComments($content);
            $relPath = str_replace(base_path() . '/', '', $path);

            if (preg_match('/Swal\.fire\s*\(\s*\{[^}]*showCancelButton\s*:\s*true/s', $code)) {
                $violations[] = "{$relPath} contains direct Swal.fire with showCancelButton";
            }
        }

        $this->assertEmpty($violations, "พบ Swal.fire() ที่สร้าง popup ยืนยันเองนอก layout/pos helper:\n" . implode("\n", $violations));
    }

    /**
     * ข้อกำหนด: ปุ่มลบ/ยกเลิกเอกสารหลักๆ (ใบจอง ใบขาย ใบเสร็จ ปรับสต็อก) มี data-confirm-tone="danger"
     */
    public function test_core_document_cancel_and_void_buttons_have_danger_tone(): void
    {
        // 1. ใบจอง: ยกเลิก, ถอย Confirm, ปิดยอดค้าง
        $bookingShow = file_get_contents(resource_path('views/bookings/show.blade.php'));
        $this->assertMatchesRegularExpression(
            '/bookings\.cancel[^\n]*data-confirm-tone="danger"/',
            $bookingShow,
            'ปุ่มยกเลิกใบจองใน bookings/show.blade.php ต้องระบุ data-confirm-tone="danger"'
        );
        $this->assertMatchesRegularExpression(
            '/bookings\.revert-confirm[^\n]*data-confirm-tone="danger"/',
            $bookingShow,
            'ปุ่มถอย Confirm ใบจองใน bookings/show.blade.php ต้องระบุ data-confirm-tone="danger"'
        );
        $this->assertMatchesRegularExpression(
            '/bookings\.close-remainder[^\n]*data-confirm-tone="danger"/',
            $bookingShow,
            'ปุ่มปิดยอดค้างใบจองใน bookings/show.blade.php ต้องระบุ data-confirm-tone="danger"'
        );

        // 2. ใบขาย / ใบเสร็จ / ใบสำคัญ: ใช้ _void-action.blade.php ร่วมกัน
        $voidAction = file_get_contents(resource_path('views/documents/_void-action.blade.php'));
        $this->assertStringContainsString('route(\'documents.void\', $doc)', $voidAction);
        $this->assertStringContainsString('data-confirm-tone="danger"', $voidAction);

        // 3. ปรับปรุงสต็อก: ปุ่มปฏิเสธ/ยกเลิก
        $adjustmentShow = file_get_contents(resource_path('views/stock-adjustments/show.blade.php'));
        $this->assertMatchesRegularExpression(
            '/stock-adjustments\.reject[^\n]*data-confirm-tone="danger"/',
            $adjustmentShow,
            'ปุ่มปฏิเสธใน stock-adjustments/show.blade.php ต้องระบุ data-confirm-tone="danger"'
        );

        // 4. ใบสั่งซื้อ: ปุ่มยกเลิก
        $poShow = file_get_contents(resource_path('views/purchase-orders/show.blade.php'));
        $this->assertMatchesRegularExpression(
            '/purchase-orders\.cancel[^\n]*data-confirm-tone="danger"/',
            $poShow,
            'ปุ่มยกเลิกใบสั่งซื้อใน purchase-orders/show.blade.php ต้องระบุ data-confirm-tone="danger"'
        );

        // 5. Safe delete (ลบข้อมูลหลัก)
        $safeDelete = file_get_contents(resource_path('views/partials/safe-delete.blade.php'));
        $this->assertStringContainsString('data-confirm-tone="danger"', $safeDelete, 'safe-delete ต้องระบุ data-confirm-tone="danger"');
    }

    /**
     * ตรวจสอบว่าคอมโพเนนต์ confirm-action รองรับ data-confirm-tone และส่งต่อไปยัง window.erpConfirm
     */
    public function test_confirm_action_component_supports_tones(): void
    {
        $content = file_get_contents(resource_path('views/components/confirm-action.blade.php'));

        $this->assertStringContainsString('trigger.dataset.confirmTone', $content);
        $this->assertStringContainsString('link.dataset.confirmTone', $content);
        $this->assertStringContainsString('window.erpConfirm', $content);
    }

    /**
     * ตรวจสอบว่า layout.blade.php มีการตั้งค่า 4 โทนสีและ UX ตามข้อกำหนด
     */
    public function test_layout_defines_unified_popup_styling_and_ergonomics(): void
    {
        $layout = file_get_contents(resource_path('views/layout.blade.php'));

        // คลาส CSS 4 โทน
        $this->assertStringContainsString('.erp-swal-confirm-danger', $layout);
        $this->assertStringContainsString('.erp-swal-confirm-warning', $layout);
        $this->assertStringContainsString('.erp-swal-confirm-primary', $layout);
        $this->assertStringContainsString('.erp-swal-confirm-success', $layout);

        // Ergonomics
        $this->assertStringContainsString('reverseButtons: true', $layout, 'ปุ่มยกเลิกอยู่ซ้าย ปุ่มยืนยันอยู่ขวา');
        $this->assertStringContainsString('allowOutsideClick: true', $layout, 'คลิกนอก = ยกเลิก');
        $this->assertStringContainsString('allowEscapeKey: true', $layout, 'กด Esc = ยกเลิก');
        $this->assertStringContainsString('focusCancel', $layout, 'โฟกัสเริ่มที่ปุ่มยกเลิกสำหรับอันตราย');
        $this->assertStringContainsString('detectTone', $layout, 'มีการเดาโทนสีอัตโนมัติเมื่อไม่ได้ระบุ');
    }
}
