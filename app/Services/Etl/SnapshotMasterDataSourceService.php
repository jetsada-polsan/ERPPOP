<?php

namespace App\Services\Etl;

use RuntimeException;

/**
 * แหล่งข้อมูลสินค้าจากไฟล์ JSON ที่ scripts/bplus-master-export.py ดึงมาจาก BPlus
 *
 * เซิร์ฟเวอร์ ERP ต่อ MSSQL ของร้านตรงๆ ไม่ได้ (ต้องผ่าน VPN) จึงดึงเป็นไฟล์จากเครื่องในร้าน
 * แล้วส่งให้ MasterDataEtlService ตัวเดิมใช้ กติกาการจับคู่และอัปเดตจึงเหมือนการดึงจาก MSSQL ทุกอย่าง
 * ไฟล์นี้มีเฉพาะข้อมูลสินค้า ใช้กับ etl:master-data --products-only เท่านั้น
 */
class SnapshotMasterDataSourceService extends MssqlMasterDataSourceService
{
    public const SECTIONS = ['categories', 'departments', 'brands', 'units', 'products', 'barcodes'];

    /** @param  array<string, array<int, array<string, mixed>>>  $snapshot */
    public function __construct(private readonly array $snapshot)
    {
        foreach (self::SECTIONS as $section) {
            if (! isset($snapshot[$section]) || ! is_array($snapshot[$section])) {
                throw new RuntimeException("ไฟล์สินค้าไม่มีส่วน {$section}");
            }
        }
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new RuntimeException('อ่านไฟล์สินค้าไม่ได้ ไม่ใช่ JSON');
        }

        return new self($data);
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return array_map('count', array_intersect_key($this->snapshot, array_flip(self::SECTIONS)));
    }

    public function fetchProductCategories(): array
    {
        return $this->snapshot['categories'];
    }

    public function fetchProductDepartments(): array
    {
        return $this->snapshot['departments'];
    }

    public function fetchProductBrands(): array
    {
        return $this->snapshot['brands'];
    }

    public function fetchProductUnits(): array
    {
        return $this->snapshot['units'];
    }

    public function fetchProducts(): array
    {
        return $this->snapshot['products'];
    }

    public function fetchProductBarcodes(): array
    {
        return $this->snapshot['barcodes'];
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        throw new RuntimeException('ไฟล์นี้มีเฉพาะข้อมูลสินค้า ใช้กับ --products-only เท่านั้น');
    }
}
