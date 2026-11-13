<?php

namespace Tests\Feature;

use Tests\TestCase;

/** เจ้าของ 13 พ.ย.: "ทุกเอกสารต้องมีเวลาแสดงในตาราง ไม่ต้องกดเข้าไปดู" */
class DocumentListsShowTimeTest extends TestCase
{
    public function test_every_document_list_shows_the_time_next_to_the_date(): void
    {
        foreach (glob(resource_path('views/*/index.blade.php')) as $file) {
            $source = file_get_contents($file);
            if (! preg_match('/->doc_date(\?)?->(thaiDate\(\)|format\()/', $source)) {
                continue;
            }
            $this->assertMatchesRegularExpression('/created_at\?->format\([\'"]H:i/', $source,
                basename(dirname($file)).': ตารางมีวันที่เอกสารแต่ไม่มีเวลา');
        }
    }
}
