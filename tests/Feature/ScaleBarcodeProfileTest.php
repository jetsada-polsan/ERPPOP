<?php

namespace Tests\Feature;

use App\Services\Inventory\ScaleBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * รูปแบบป้ายเครื่องชั่งมาจากที่ตั้งค่าไว้ ไม่ใช่จากการเดาในโค้ด
 *
 * เครื่องชั่งคนละรุ่นออกป้ายคนละแบบ เดารูปแบบผิดแปลว่าคิดเงินผิดที่หน้าเคาน์เตอร์
 * ทันที และเดิมกฎถูกฝังไว้สองที่ (ERP กับ POS) ที่ต้องแก้ให้ตรงกันเอง
 */
class ScaleBarcodeProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipped_profiles_read_the_labels_in_use_today(): void
    {
        $service = app(ScaleBarcodeService::class);
        $label = $service->fromTotalPrice('800123', 125.50);

        $decoded = $service->decode($label);

        $this->assertSame('800123', $decoded['plu']);
        $this->assertSame(125.5, $decoded['price']);
        $this->assertSame('POPSTAR-800', $decoded['profile'], 'ต้องบอกได้ว่าอ่านด้วย profile ไหน');
    }

    public function test_turning_a_profile_off_stops_its_labels_being_read(): void
    {
        $service = app(ScaleBarcodeService::class);
        $label = $service->fromTotalPrice('801200', 80.00);
        $this->assertNotNull($service->decode($label));

        DB::table('scale_barcode_profiles')->where('prefix', '801')->update(['is_active' => false]);

        $this->assertNull($service->decode($label),
            'ปิด profile แล้วป้ายแบบนั้นต้องอ่านไม่ออก ไม่ใช่ยังอ่านได้จากกฎที่ฝังในโค้ด');
    }

    public function test_a_new_scale_format_works_without_touching_code(): void
    {
        // เครื่องชั่งรุ่นใหม่: ขึ้นต้น 27 · PLU 5 หลัก · น้ำหนักเป็นกรัม 5 หลัก · ไม่มี check digit
        DB::table('scale_barcode_profiles')->insert([
            'code' => 'NEW-SCALE', 'name' => 'เครื่องชั่งรุ่นใหม่',
            'prefix' => '27', 'plu_length' => 5, 'value_length' => 5, 'value_type' => 'weight',
            'check_digit' => 'none', 'total_length' => 10, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $decoded = app(ScaleBarcodeService::class)->decode('2700101250');

        $this->assertSame('27001', $decoded['plu']);
        $this->assertEqualsWithDelta(1250, $decoded['price'], 0.001, 'ประเภท weight ต้องคืนค่าดิบ ไม่หารร้อยเหมือนราคา');
        $this->assertSame('NEW-SCALE', $decoded['profile']);
    }

    public function test_a_tampered_label_is_refused_by_the_check_digit_profile(): void
    {
        $service = app(ScaleBarcodeService::class);
        $label = $service->fromTotalPrice('800123', 125.50);
        $tampered = '800124'.substr($label, 6);

        $this->assertNull($service->decode($tampered),
            'แก้ PLU บนป้ายแล้วสวมเป็นสินค้าอื่นไม่ได้');
    }

    public function test_a_profile_that_checks_the_digit_is_tried_before_one_that_does_not(): void
    {
        // ป้ายที่ถูกแก้ตัวเลขเข้าได้ทั้งกฎ 13 หลัก (ตรวจ) และกฎ 12 หลัก (ไม่ตรวจ)
        // ถ้าให้กฎที่ไม่ตรวจชนะ ป้ายปลอมจะผ่านไปได้ทั้งที่ควรถูกปฏิเสธ
        $service = app(ScaleBarcodeService::class);
        $valid = $service->fromTotalPrice('800123', 99.00);

        $decoded = $service->decode($valid);

        $this->assertSame(13, strlen($valid));
        $this->assertSame('POPSTAR-800', $decoded['profile']);
        $this->assertEqualsWithDelta(99, $decoded['price'], 0.001, 'ต้องอ่านด้วยกฎ 13 หลัก ไม่ใช่กฎ 12 หลักที่ตัดเลขผิดตำแหน่ง');
    }

    public function test_the_till_is_told_the_rules_instead_of_guessing_them(): void
    {
        $profiles = DB::table('scale_barcode_profiles')->where('is_active', true)->get();

        $this->assertGreaterThanOrEqual(2, $profiles->count());
        foreach ($profiles as $profile) {
            $this->assertContains($profile->value_type, ['price', 'weight']);
            $this->assertContains($profile->check_digit, ['ean13', 'none']);
            $this->assertGreaterThan(0, $profile->plu_length);
            $this->assertSame(
                (int) $profile->total_length >= (int) $profile->plu_length + (int) $profile->value_length,
                true,
                "profile {$profile->code} ความยาวรวมต้องพอสำหรับ PLU และมูลค่า",
            );
        }
    }
}
