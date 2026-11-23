<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** CSS ของ layout แยกเป็นไฟล์ให้ browser cache ได้ เทสต์ที่ตรวจกฎ CSS จึงต้องแทน <link> ด้วยเนื้อไฟล์ตามลำดับเดิม */
    protected function withLayoutCss(string $html): string
    {
        return preg_replace_callback('#<link[^>]+href="[^"]*/(css/erp-(?:layout|ui-standard)\.css)[^"]*"[^>]*>#',
            fn ($m) => '<style>'.file_get_contents(public_path($m[1])).'</style>', $html);
    }

    protected static function layoutSourceWithCss(): string
    {
        return file_get_contents(resource_path('views/layout.blade.php'))
            .file_get_contents(public_path('css/erp-layout.css'))
            .file_get_contents(public_path('css/erp-ui-standard.css'));
    }
}
