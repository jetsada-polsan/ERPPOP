<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ChartOfAccountImportService
{
    /**
     * @return array{created:int, updated:int, skipped:int, roles:int}
     */
    public function import(string $path, bool $assignDefaultRoles = true): array
    {
        $rows = $this->readSpreadsheetRows($path);
        if (count($rows) < 2) {
            throw new RuntimeException('ไฟล์ผังบัญชีไม่มีข้อมูลให้นำเข้า');
        }

        $header = $this->mapHeader(array_shift($rows));
        if (! isset($header['code'], $header['name_th'])) {
            throw new RuntimeException('ไฟล์ต้องมีคอลัมน์ รหัสบัญชี และ ชื่อบัญชีไทย');
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'roles' => 0];

        DB::transaction(function () use ($rows, $header, $assignDefaultRoles, &$stats) {
            foreach ($rows as $row) {
                $code = trim((string) ($row[$header['code']] ?? ''));
                $nameTh = trim((string) ($row[$header['name_th']] ?? ''));

                if ($code === '' || $nameTh === '') {
                    $stats['skipped']++;
                    continue;
                }

                $typeText = trim((string) ($row[$header['account_type'] ?? -1] ?? ''));
                $accountType = $this->mapAccountType($typeText, $code);
                if ($accountType === null) {
                    $stats['skipped']++;
                    continue;
                }

                $role = $assignDefaultRoles ? $this->defaultRoleFor($code, $nameTh) : null;
                if ($role) {
                    ChartOfAccount::where('default_role', $role)
                        ->where('code', '!=', $code)
                        ->update(['default_role' => null]);
                    $stats['roles']++;
                }

                $payload = [
                    'name_th' => $nameTh,
                    'name_en' => trim((string) ($row[$header['name_en'] ?? -1] ?? '')) ?: null,
                    'account_type' => $accountType,
                    'default_role' => $role,
                ];

                $account = ChartOfAccount::where('code', $code)->first();
                if ($account) {
                    $account->update($payload);
                    $stats['updated']++;
                } else {
                    ChartOfAccount::create(['code' => $code] + $payload);
                    $stats['created']++;
                }
            }
        });

        return $stats;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readSpreadsheetRows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('อ่านไฟล์ Excel ไม่ได้ รองรับไฟล์ .xlsx หรือ .xls ที่เป็น Excel รุ่นใหม่เท่านั้น');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('ไม่พบ sheet1 ในไฟล์ Excel');
        }

        $sheet = simplexml_load_string($sheetXml);
        if (! $sheet instanceof SimpleXMLElement) {
            throw new RuntimeException('อ่านข้อมูล sheet ไม่ได้');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $xmlRow) {
            $row = [];
            foreach ($xmlRow->c as $cell) {
                $ref = (string) $cell['r'];
                $index = $this->columnIndex($ref);
                $row[$index] = $this->cellValue($cell, $sharedStrings);
            }

            if ($row !== []) {
                ksort($row);
                $max = max(array_keys($row));
                $rows[] = array_replace(array_fill(0, $max + 1, ''), $row);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $sharedXml = simplexml_load_string($xml);
        if (! $sharedXml instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];
        foreach ($sharedXml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            $parts = [];
            foreach ($si->r as $run) {
                $parts[] = (string) $run->t;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            $idx = (int) $cell->v;
            return $sharedStrings[$idx] ?? '';
        }
        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        return trim((string) $cell->v);
    }

    private function columnIndex(string $cellRef): int
    {
        preg_match('/^[A-Z]+/i', $cellRef, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  array<int, string>  $row
     * @return array<string, int>
     */
    private function mapHeader(array $row): array
    {
        $map = [];
        foreach ($row as $index => $label) {
            $normalized = preg_replace('/\s+/u', '', mb_strtolower(trim((string) $label)));
            if (str_contains($normalized, 'รหัสบัญชี') || $normalized === 'code') {
                $map['code'] = $index;
            } elseif (str_contains($normalized, 'ชื่อบัญชีไทย') || str_contains($normalized, 'nameth')) {
                $map['name_th'] = $index;
            } elseif (str_contains($normalized, 'ชื่อบัญชีอังกฤษ') || str_contains($normalized, 'nameen')) {
                $map['name_en'] = $index;
            } elseif (str_contains($normalized, 'ประเภทบัญชี') || str_contains($normalized, 'accounttype')) {
                $map['account_type'] = $index;
            }
        }

        return $map;
    }

    private function mapAccountType(string $typeText, string $code): ?string
    {
        $normalized = preg_replace('/\s+/u', '', mb_strtolower($typeText));
        $map = [
            'สินทรัพย์' => 'asset',
            'หนี้สิน' => 'liability',
            'ทุน' => 'equity',
            'ส่วนของเจ้าของ' => 'equity',
            'รายได้' => 'revenue',
            'ค่าใช้จ่าย' => 'expense',
        ];

        foreach ($map as $needle => $type) {
            if (str_contains($normalized, $needle)) {
                return $type;
            }
        }

        return match (substr($code, 0, 1)) {
            '1' => 'asset',
            '2' => 'liability',
            '3' => 'equity',
            '4' => 'revenue',
            '5' => 'expense',
            default => null,
        };
    }

    private function defaultRoleFor(string $code, string $nameTh): ?string
    {
        $byCode = [
            '1111-00' => ChartOfAccount::ROLE_CASH,
            '1130-01' => ChartOfAccount::ROLE_AR,
            '1140-00' => ChartOfAccount::ROLE_INVENTORY,
            '1154-00' => ChartOfAccount::ROLE_VAT_INPUT,
            '2120-01' => ChartOfAccount::ROLE_AP,
            '2135-00' => ChartOfAccount::ROLE_VAT_OUTPUT,
            '3200-00' => ChartOfAccount::ROLE_RETAINED_EARNINGS,
            '4100-01' => ChartOfAccount::ROLE_SALES_REVENUE,
            '4100-03' => ChartOfAccount::ROLE_SALES_RETURN,
            '5100-00' => ChartOfAccount::ROLE_COGS,
            '5200-00' => ChartOfAccount::ROLE_EXPENSE,
        ];
        if (isset($byCode[$code])) {
            return $byCode[$code];
        }

        $name = preg_replace('/\s+/u', '', $nameTh);

        return match (true) {
            $name === 'เงินสด' => ChartOfAccount::ROLE_CASH,
            $name === 'ลูกหนี้การค้า' => ChartOfAccount::ROLE_AR,
            $name === 'เจ้าหนี้การค้า' => ChartOfAccount::ROLE_AP,
            $name === 'สินค้าคงเหลือ' => ChartOfAccount::ROLE_INVENTORY,
            $name === 'ภาษีซื้อ' => ChartOfAccount::ROLE_VAT_INPUT,
            $name === 'ภาษีขาย' => ChartOfAccount::ROLE_VAT_OUTPUT,
            $name === 'กำไรสะสม' => ChartOfAccount::ROLE_RETAINED_EARNINGS,
            $name === 'รายได้จากการขาย' => ChartOfAccount::ROLE_SALES_REVENUE,
            str_contains($name, 'รับคืนสินค้า') => ChartOfAccount::ROLE_SALES_RETURN,
            str_contains($name, 'ต้นทุนขาย') => ChartOfAccount::ROLE_COGS,
            $name === 'ค่าใช้จ่ายในการขาย' => ChartOfAccount::ROLE_EXPENSE,
            default => null,
        };
    }
}
