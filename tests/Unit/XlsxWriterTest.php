<?php

namespace Tests\Unit;

use App\Support\XlsxWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * ไฟล์ .xlsx ที่เขียนเองต้องเป็นไฟล์ที่ Excel เปิดได้จริง
 *
 * จุดที่พลาดง่ายคือโครงสร้าง zip ไม่ครบ ตัวเลขถูกเขียนเป็นข้อความจนบวกไม่ได้
 * และอักขระควบคุมที่หลุดเข้ามาแล้วทำให้ Excel ปฏิเสธทั้งไฟล์
 */
class XlsxWriterTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_it_writes_a_zip_with_every_part_excel_requires(): void
    {
        $this->write([['label' => 'สินค้า', 'key' => 'name']], [['name' => 'น้ำปลา']]);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path) === true, 'ไฟล์ต้องเปิดเป็น zip ได้');
        foreach ([
            '[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml',
        ] as $part) {
            $this->assertNotFalse($zip->locateName($part), "ขาดไฟล์ {$part} — Excel จะบอกว่าไฟล์เสีย");
        }
        $zip->close();
    }

    public function test_numbers_are_written_as_numbers_so_excel_can_add_them_up(): void
    {
        $this->write(
            [['label' => 'ยอด', 'key' => 'amount', 'type' => 'money']],
            [['amount' => '1234.50']],
        );

        $sheet = $this->sheetXml();
        $this->assertStringContainsString('<v>1234.5</v>', $sheet, 'ต้องเป็นค่าตัวเลข ไม่ใช่ข้อความ');
        $this->assertStringNotContainsString('<t xml:space="preserve">1234.50</t>', $sheet);
    }

    public function test_thai_text_survives_and_the_header_is_the_first_row(): void
    {
        $this->write([['label' => 'ชื่อสินค้า', 'key' => 'name']], [['name' => 'ปลาทูนึ่ง']]);

        $sheet = $this->sheetXml();
        $this->assertStringContainsString('ชื่อสินค้า', $sheet);
        $this->assertStringContainsString('ปลาทูนึ่ง', $sheet);
        $this->assertStringContainsString('<row r="1">', $sheet);
        $this->assertStringContainsString('<row r="2">', $sheet);
    }

    public function test_the_header_row_stays_put_when_scrolling(): void
    {
        $this->write([['label' => 'ก', 'key' => 'a']], [['a' => '1']]);

        $this->assertStringContainsString('state="frozen"', $this->sheetXml(),
            'รายงานยาวเป็นพันแถว เลื่อนแล้วต้องยังเห็นหัวตาราง');
    }

    public function test_control_characters_are_stripped_rather_than_breaking_the_file(): void
    {
        $this->write([['label' => 'หมายเหตุ', 'key' => 'note']], [['note' => "ปกติ\x07เสีย"]]);

        $sheet = $this->sheetXml();
        $this->assertStringNotContainsString("\x07", $sheet);
        $this->assertNotFalse(simplexml_load_string($sheet), 'XML ต้องยังอ่านได้');
    }

    public function test_an_unparseable_date_falls_back_to_text_instead_of_a_wrong_date(): void
    {
        $this->write(
            [['label' => 'วันที่', 'key' => 'day', 'type' => 'date']],
            [['day' => 'ไม่ใช่วันที่']],
        );

        $sheet = $this->sheetXml();
        $this->assertStringContainsString('ไม่ใช่วันที่', $sheet, 'แปลงไม่ได้ให้เก็บตามเดิม ดีกว่าเขียนวันที่มั่ว');
    }

    public function test_columns_past_z_get_the_right_reference(): void
    {
        $columns = [];
        $row = [];
        for ($index = 0; $index < 28; $index++) {
            $columns[] = ['label' => 'c'.$index, 'key' => 'k'.$index];
            $row['k'.$index] = 'v'.$index;
        }
        $this->write($columns, [$row]);

        $sheet = $this->sheetXml();
        $this->assertStringContainsString('r="AA1"', $sheet, 'คอลัมน์ที่ 27 ต้องเป็น AA ไม่ใช่ตัวอักษรเดียว');
        $this->assertStringContainsString('r="AB1"', $sheet);
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function write(array $columns, array $rows): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'xlsx-test-').'.xlsx';
        (new XlsxWriter)->write($this->path, 'รายงานทดสอบ', $columns, $rows);
    }

    private function sheetXml(): string
    {
        $zip = new ZipArchive;
        $zip->open($this->path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml;
    }
}
