<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ฝ่ายขาย 13 พ.ย.: เลือกบาร์โค้ดกระสอบ X20 จำนวนคิดถูก (= 20 กิโลกรัม) แต่ช่องหน่วยยังโชว์ "กิโลกรัม"
 * เพราะ option ของ select สร้างทีหลังค่า x-model เบราว์เซอร์เลยเลือกตัวแรก ต้องผูก :selected กับ item.unit_id
 */
class UnitSelectShowsChosenUnitTest extends TestCase
{
    public function test_every_unit_dropdown_marks_the_chosen_unit_as_selected(): void
    {
        foreach (['bookings/create.blade.php', 'documents/partials/entry-modal.blade.php'] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString(':selected="String(u.unit_id) === String(item.unit_id)"', $source, $view);
        }
    }
}
