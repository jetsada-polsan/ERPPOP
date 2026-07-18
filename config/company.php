<?php

// ข้อมูลบริษัทสำหรับหัวเอกสาร/รายงานทุกหน้า - แก้ที่นี่ที่เดียว (หรือ override
// ผ่าน .env) แล้วหัวกระดาษพิมพ์เปลี่ยนตามทั้งระบบ
return [
    'name_th' => env('COMPANY_NAME_TH', 'บริษัท ป๊อบสตาร์ฟู้ดเทรดดิ้ง จำกัด'),
    'name_en' => env('COMPANY_NAME_EN', 'POP STAR FOOD TRADING CO., LTD.'),
    'tax_id' => env('COMPANY_TAX_ID', '0345566003246'),
    'address' => env('COMPANY_ADDRESS', '480 หมู่ที่ 1 ตำบลแสนสุข อำเภอวารินชำราบ จังหวัดอุบลราชธานี 34190'),
    'phone' => env('COMPANY_PHONE', ''),
];
