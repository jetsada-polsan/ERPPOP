<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * เอกสารที่พิมพ์ออกกระดาษต้องบอกขนาดกระดาษไว้ในตัวเอง
 *
 * ไม่ประกาศ @page แปลว่าใช้ค่าที่ตั้งไว้ในเครื่องพิมพ์แต่ละเครื่อง เอกสารเดียวกัน
 * จึงออกมาคนละขนาดตามแต่ว่าใครสั่งพิมพ์ และเนื้อหาที่วางไว้ 210mm จะถูกย่อ
 * หรือตัดขอบเงียบ ๆ กว่าจะรู้ก็ตอนใบกำกับภาษีถึงมือลูกค้าแล้ว
 */
class PrintablePageSizeTest extends TestCase
{
    /**
     * หน้าที่มี @media print แต่ไม่ใช่เอกสาร — เป็นหน้าจอที่เผื่อให้พิมพ์ได้เฉย ๆ
     * หรือเป็นฉลากที่ขนาดขึ้นกับสติกเกอร์ที่ร้านใช้ ต้องให้คนตั้งเอง
     */
    private const NOT_DOCUMENTS = [
        'layout.blade.php',
        'core-modules/index.blade.php',
        'database-structure/index.blade.php',
        'financial-statements/index.blade.php',
        'pos/index.blade.php',
        'documents/partials/print-theme.blade.php',
        'management-controls/payslip.blade.php',
        'price-tags/preview.blade.php',
        'stock-transforms/labels.blade.php',
    ];

    public function test_every_printable_document_declares_its_paper_size(): void
    {
        $missing = [];

        foreach ($this->bladeFiles() as $relative => $contents) {
            if (! str_contains($contents, '@media print')) {
                continue;
            }
            if (in_array($relative, self::NOT_DOCUMENTS, true)) {
                continue;
            }
            if (! str_contains($contents, '@page')) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing,
            "เอกสารที่ไม่ได้ประกาศขนาดกระดาษ:\n".implode("\n", $missing));
    }

    public function test_the_delivery_note_stays_a5(): void
    {
        $contents = file_get_contents($this->viewPath().'/delivery-note.blade.php');

        // ใบส่งของพิมพ์ทุกวันบนกระดาษ A5 ที่ซื้อมาเป็นรีม เปลี่ยนเป็น A4 คือพิมพ์ผิดทั้งกอง
        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*size:\s*A5\s+landscape/i', $contents);
        $this->assertStringContainsString('<div class="page delivery-note-page">', $contents);

        // The shared print theme styles .page as A4; the A5 overrides must come after it.
        $themePosition = strpos($contents, "@include('documents.partials.print-theme')");
        $overridePosition = strpos($contents, '.page.delivery-note-page .doc-head');
        $this->assertNotFalse($themePosition);
        $this->assertNotFalse($overridePosition);
        $this->assertGreaterThan($themePosition, $overridePosition);
        $this->assertStringContainsString('width: min(210mm, calc(100vw - 32px)) !important;', $contents);
        $this->assertStringContainsString('min-height: 148mm !important;', $contents);
        $this->assertStringContainsString('min-height: 0 !important;', $contents);
        $this->assertStringContainsString('padding: 4mm !important;', $contents);
    }

    public function test_the_till_receipt_stays_on_the_thermal_roll(): void
    {
        $contents = file_get_contents($this->viewPath().'/pos/z-report.blade.php');

        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*size:\s*80mm/i', $contents,
            'ใบสรุปกะออกทางเครื่องพิมพ์ความร้อน 80mm ไม่ใช่กระดาษ A4');
    }

    /** @return array<string, string> */
    private function bladeFiles(): array
    {
        $files = [];
        $root = $this->viewPath();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $files[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }

    private function viewPath(): string
    {
        return dirname(__DIR__, 2).'/resources/views';
    }
}
