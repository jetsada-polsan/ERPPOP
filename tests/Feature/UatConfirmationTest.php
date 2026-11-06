<?php

namespace Tests\Feature;

use Tests\TestCase;

class UatConfirmationTest extends TestCase
{
    public function test_views_use_the_shared_confirmation_instead_of_native_dialogs(): void
    {
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/return\s+confirm\s*\(/', $file->getContents(), $file->getPathname());
        }
        $this->assertStringContainsString('<x-confirm-action />', file_get_contents(resource_path('views/layout.blade.php')));
        $this->assertStringContainsString('data-confirm="ยืนยันรับสินค้าเข้าคลังและสร้างเจ้าหนี้?"', file_get_contents(resource_path('views/purchase-orders/show.blade.php')));
    }
}
