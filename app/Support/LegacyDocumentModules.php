<?php

namespace App\Support;

class LegacyDocumentModules
{
    /**
     * Codes are intentionally limited to the imported BPlus DOCTYPE rows.
     * Do not invent codes here; import DOCTYPE first, then map the real code.
     */
    public static function byModule(): array
    {
        return [
            'pos-backoffice' => [
                'title' => 'POS / POS Online',
                'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5'],
            ],
            'pos-sales' => [
                'title' => 'POS หน้าร้าน',
                'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5'],
            ],
            'price-promotion' => [
                'title' => 'ราคา / โปรโมชัน',
                'codes' => [],
            ],
            'member' => [
                'title' => 'สมาชิก',
                'codes' => [],
            ],
            'inventory' => [
                'title' => 'สินค้า / คลัง',
                'codes' => [
                    'CST', 'DD', 'DM', 'DR', 'DT', 'IP', 'RG', 'RM', 'RU',
                    'RT01', 'RT09', 'TT11', 'TT12', 'TT16',
                ],
            ],
            'purchase' => [
                'title' => 'ซื้อ / เจ้าหนี้',
                'codes' => ['CB', 'IB', 'IB-N', 'PP', 'RP'],
            ],
            'sales-ar' => [
                'title' => 'ขาย / ลูกหนี้',
                'codes' => [
                    'Q21',
                    'B10', 'B11', 'B12', 'B14', 'B15', 'B16', 'B17', 'B18', 'B20', 'B21', 'B26',
                    'B33', 'B34', 'B36', 'B37', 'B38', 'B39', 'BK5', 'BK6', 'BK7',
                    'DS', 'DSN', 'DF1', 'CNR', 'IS', 'IS-N',
                    'RR1', 'RR2', 'RR3', 'RR4', 'RR5', 'RR6', 'RR7', 'RR8', 'RR9', 'RR10', 'RR11', 'RR12',
                ],
            ],
            'production' => [
                'title' => 'ผลิต / แปรรูป',
                'codes' => ['IP', 'DT', 'RM'],
            ],
            'accounting' => [
                'title' => 'บัญชี / การเงิน',
                'codes' => ['PV', 'PV-2', 'PV-3', 'PV-4', 'PV-5'],
            ],
            'reports' => [
                'title' => 'รายงาน / ตรวจสอบ',
                'codes' => ['DS', 'DSN', 'PS1', 'PS2', 'PS3', 'PS4', 'PS5', 'DM', 'RG', 'TT16', 'IB', 'CB', 'PV'],
            ],
            'online' => [
                'title' => 'Online / API',
                'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5', 'B37'],
            ],
        ];
    }

    public static function bplusFlowByModule(): array
    {
        return [
            'pos-backoffice' => [
                ['name' => 'เตรียมข้อมูลเครื่อง POS', 'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5']],
                ['name' => 'นำเข้ายอดขายสดจากเครื่อง POS', 'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5']],
                ['name' => 'ตรวจแฟ้มขายสด / ยอดรับเงิน', 'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5']],
            ],
            'pos-sales' => [
                ['name' => 'เปิดกะ / เตรียมเครื่องขาย', 'codes' => ['PS1']],
                ['name' => 'สแกนขายหน้าร้าน', 'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5']],
                ['name' => 'รับชำระ / ออกบิล POS', 'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5']],
            ],
            'price-promotion' => [
                ['name' => 'ตั้งราคาสินค้า / ตารางราคา', 'codes' => []],
                ['name' => 'ตั้งโปรโมชัน / ส่วนลด', 'codes' => []],
            ],
            'member' => [
                ['name' => 'แฟ้มสมาชิก / ราคาสมาชิก', 'codes' => []],
                ['name' => 'แต้ม / สิทธิ์สมาชิก', 'codes' => []],
            ],
            'inventory' => [
                ['name' => 'รับสินค้าเข้า / รับจากการผลิต', 'codes' => ['IP']],
                ['name' => 'โอนย้ายระหว่างคลัง / สาขา', 'codes' => ['DM', 'RT01', 'RT09', 'TT11', 'TT12', 'TT16']],
                ['name' => 'เบิก / คืน / ตัดชำรุด', 'codes' => ['DR', 'RU', 'DD', 'RG']],
                ['name' => 'ตรวจนับ / แปรรูป', 'codes' => ['CST', 'DT', 'RM']],
            ],
            'purchase' => [
                ['name' => 'ซื้อสด / ซื้อเชื่อ', 'codes' => ['CB', 'IB', 'IB-N']],
                ['name' => 'ชำระเจ้าหนี้', 'codes' => ['PP']],
                ['name' => 'ขออนุมัติจ่าย', 'codes' => ['RP']],
            ],
            'sales-ar' => [
                ['name' => 'เสนอราคา', 'codes' => ['Q21']],
                ['name' => 'ใบจองสินค้าแยกสาขา / ช่องทาง', 'codes' => [
                    'B10', 'B11', 'B12', 'B14', 'B15', 'B16', 'B17', 'B18', 'B20', 'B21', 'B26',
                    'B33', 'B34', 'B36', 'B37', 'B38', 'B39', 'BK5', 'BK6', 'BK7',
                ]],
                ['name' => 'ขายเชื่อ / ขายเชื่อ N / ของเสีย', 'codes' => ['DS', 'DSN', 'DF1']],
                ['name' => 'รับคืน / ลดหนี้ลูกหนี้', 'codes' => ['IS', 'IS-N', 'CNR']],
                ['name' => 'ใบเสร็จรับเงินแยกผู้รับ / ช่องทาง', 'codes' => [
                    'RR1', 'RR2', 'RR3', 'RR4', 'RR5', 'RR6', 'RR7', 'RR8', 'RR9', 'RR10', 'RR11', 'RR12',
                ]],
            ],
            'production' => [
                ['name' => 'รับสินค้าจากการผลิต', 'codes' => ['IP']],
                ['name' => 'แปรรูปสินค้า', 'codes' => ['DT', 'RM']],
            ],
            'accounting' => [
                ['name' => 'ใบสำคัญจ่าย', 'codes' => ['PV', 'PV-2', 'PV-3', 'PV-4', 'PV-5']],
            ],
            'reports' => [
                ['name' => 'รายงานขาย / POS', 'codes' => ['DS', 'DSN', 'PS1', 'PS2', 'PS3', 'PS4', 'PS5']],
                ['name' => 'รายงานคลัง / โอน / ตรวจนับ', 'codes' => ['DM', 'RG', 'TT16', 'CST']],
                ['name' => 'รายงานซื้อ / เจ้าหนี้', 'codes' => ['IB', 'IB-N', 'CB', 'PP']],
                ['name' => 'รายงานบัญชี / การเงิน', 'codes' => ['PV', 'PV-2', 'PV-3', 'PV-4', 'PV-5']],
            ],
            'online' => [
                ['name' => 'POS Online / Member Online', 'codes' => ['PS1', 'PS2', 'PS3', 'PS4', 'PS5']],
                ['name' => 'ใบจองออนไลน์', 'codes' => ['B37']],
            ],
        ];
    }

    public static function coreModules(): array
    {
        return [
            'products-stock' => ['inventory'],
            'sales' => ['sales-ar'],
            'pos' => ['pos-sales', 'pos-backoffice'],
            'transfer' => ['inventory'],
            'purchase' => ['purchase'],
            'reports' => ['reports', 'accounting'],
        ];
    }

    public static function codesFor(string $moduleKey): array
    {
        return self::byModule()[$moduleKey]['codes'] ?? [];
    }

    public static function codesForCore(string $coreKey): array
    {
        $codes = [];

        foreach (self::coreModules()[$coreKey] ?? [] as $moduleKey) {
            $codes = array_merge($codes, self::codesFor($moduleKey));
        }

        return array_values(array_unique($codes));
    }

    public static function attachToModules(array $modules): array
    {
        $legacyModules = self::byModule();
        $flows = self::bplusFlowByModule();

        return array_map(function (array $module) use ($legacyModules, $flows) {
            $legacy = $legacyModules[$module['key']] ?? ['title' => null, 'codes' => []];
            $module['legacy_title'] = $legacy['title'];
            $module['legacy_codes'] = $legacy['codes'];
            $module['bplus_flow'] = $flows[$module['key']] ?? [];

            return $module;
        }, $modules);
    }
}
