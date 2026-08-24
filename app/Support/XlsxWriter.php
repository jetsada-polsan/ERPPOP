<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * เขียนไฟล์ .xlsx แผ่นเดียว โดยไม่พึ่งไลบรารีภายนอก
 *
 * ที่ต้องการมีแค่: หัวตารางหนึ่งแถว ตัวเลขที่ Excel บวกได้จริง วันที่ที่เรียงได้
 * และตรึงหัวตารางไว้ตอนเลื่อน ซึ่งไม่คุ้มกับการลาก PhpSpreadsheet ทั้งก้อน
 * (สิบกว่าเมกะไบต์และต้องลง dependency บนเครื่องจริง) เข้ามาในระบบ
 *
 * xlsx คือไฟล์ zip ที่ข้างในเป็น XML ตามมาตรฐาน OOXML — ส่วนที่ใช้จริงมีไม่กี่ไฟล์
 */
class XlsxWriter
{
    /** วันที่ใน Excel นับเป็นจำนวนวันจาก 1899-12-30 (ค่าเพี้ยนของ Lotus ที่สืบทอดกันมา) */
    private const EXCEL_EPOCH = '1899-12-30';

    private const STYLE_DEFAULT = 0;

    private const STYLE_HEADER = 1;

    private const STYLE_MONEY = 2;

    private const STYLE_DATE = 3;

    /**
     * @param  array<int, array{label?:string, key?:string, type?:string}>  $columns
     * @param  iterable<int, array<string, mixed>|object>  $rows
     */
    public function write(string $path, string $sheetName, array $columns, iterable $rows): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("เปิดไฟล์เพื่อเขียนไม่ได้: {$path}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($columns, $rows));

        if (! $zip->close()) {
            throw new RuntimeException('ปิดไฟล์ xlsx ไม่สำเร็จ');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  iterable<int, array<string, mixed>|object>  $rows
     */
    private function sheet(array $columns, iterable $rows): string
    {
        $widths = [];
        foreach ($columns as $index => $column) {
            $label = (string) ($column['label'] ?? '');
            // ความกว้างคร่าว ๆ จากความยาวหัวคอลัมน์ — ตัวอักษรไทยกินที่มากกว่าละติน
            $widths[] = sprintf('<col min="%d" max="%d" width="%.1f"/>',
                $index + 1, $index + 1, min(46, max(12, mb_strlen($label) * 1.6 + 4)));
        }

        $body = '<row r="1">';
        foreach ($columns as $index => $column) {
            $body .= $this->textCell($this->cellRef($index, 1), (string) ($column['label'] ?? ''), self::STYLE_HEADER);
        }
        $body .= '</row>';

        $rowNumber = 1;
        foreach ($rows as $row) {
            $rowNumber++;
            $body .= '<row r="'.$rowNumber.'">';
            foreach ($columns as $index => $column) {
                $key = $column['key'] ?? null;
                $value = $key === null ? null : data_get($row, $key);
                $body .= $this->cell($this->cellRef($index, $rowNumber), $value, (string) ($column['type'] ?? 'text'));
            }
            $body .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
            // ตรึงหัวตาราง เลื่อนลงพันแถวก็ยังรู้ว่าคอลัมน์ไหนคืออะไร
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<cols>'.implode('', $widths).'</cols>'
            .'<sheetData>'.$body.'</sheetData>'
            .'</worksheet>';
    }

    private function cell(string $reference, mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($type, ['money', 'number'], true) && is_numeric($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>',
                $reference, $type === 'money' ? self::STYLE_MONEY : self::STYLE_DEFAULT, (float) $value);
        }

        if ($type === 'date' && ($serial = $this->excelDate((string) $value)) !== null) {
            return sprintf('<c r="%s" s="%d"><v>%d</v></c>', $reference, self::STYLE_DATE, $serial);
        }

        return $this->textCell($reference, (string) $value, self::STYLE_DEFAULT);
    }

    private function textCell(string $reference, string $value, int $style): string
    {
        // inlineStr ไม่ต้องมีตาราง sharedStrings แยก ไฟล์ใหญ่ขึ้นนิดแต่โค้ดน้อยลงมาก
        return sprintf('<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $reference, $style, $this->escape($value));
    }

    /** null = แปลงเป็นวันที่ไม่ได้ ให้ผู้เรียกเก็บเป็นข้อความแทน ดีกว่าเขียนวันที่มั่ว */
    private function excelDate(string $value): ?int
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $days = (int) floor(($timestamp - strtotime(self::EXCEL_EPOCH)) / 86400);

        return $days > 0 ? $days : null;
    }

    private function cellRef(int $columnIndex, int $row): string
    {
        $letters = '';
        for ($index = $columnIndex; $index >= 0; $index = intdiv($index, 26) - 1) {
            $letters = chr(65 + $index % 26).$letters;
        }

        return $letters.$row;
    }

    private function escape(string $value): string
    {
        // ตัวควบคุมที่ XML ไม่รับ ทำให้ Excel ปฏิเสธทั้งไฟล์ — ตัดทิ้งก่อน
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(string $sheetName): string
    {
        // ชื่อชีทห้ามเกิน 31 ตัวและห้ามมีอักขระต้องห้าม ไม่งั้น Excel ฟ้องว่าไฟล์เสีย
        $name = mb_substr(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', '-', $sheetName) ?: 'Sheet1', 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($name).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2">'
            .'<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            .'<numFmt numFmtId="165" formatCode="yyyy-mm-dd"/>'
            .'</numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Tahoma"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font>'
            .'</fonts>'
            // fill 0 กับ 1 เป็นของบังคับตามมาตรฐาน ห้ามตัดออกแม้ไม่ได้ใช้
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}
