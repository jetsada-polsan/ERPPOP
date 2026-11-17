<?php

namespace App\Services\Bplus;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * รับไฟล์ JSON ที่ scripts/bplus-route-export.py ดึงจาก BPlus แล้วเก็บเป็นสำเนาเอกสาร
 * ใช้ bplus_key (DI_KEY) เป็นตัวกัน: ใบเดิมจะถูกอัปเดต ไม่สร้างซ้ำ
 * ไม่แตะสต๊อก ลูกหนี้ หรือ GL ของ ERP
 */
class BplusSyncImporter
{
    private const KINDS = ['booking', 'credit_sale', 'opening_ar', 'credit_note', 'receipt'];

    /** @return array{route: string, documents: array<int, array>, customers?: array<int, array>, products?: array<int, array>} */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['route'], $data['documents']) || ! is_array($data['documents'])) {
            throw new InvalidArgumentException('ไฟล์ไม่ใช่ไฟล์ส่งออกจาก BPlus');
        }
        $route = (string) $data['route'];
        foreach ($data['documents'] as $index => $doc) {
            foreach (['bplus_key', 'kind', 'book_code', 'ref', 'doc_date'] as $field) {
                if (! isset($doc[$field]) || $doc[$field] === '') {
                    throw new InvalidArgumentException('เอกสารแถว '.($index + 1)." ไม่มี {$field}");
                }
            }
            if (! in_array($doc['kind'], self::KINDS, true)) {
                throw new InvalidArgumentException("เอกสาร {$doc['ref']} ประเภทไม่รู้จัก");
            }
            if ($doc['kind'] === 'credit_note' && trim((string) ($doc['sale_ref'] ?? '')) === '') {
                throw new InvalidArgumentException('เอกสาร '.$doc['ref'].' ไม่มี sale_ref');
            }
        }

        return [
            'route' => $route,
            'documents' => $data['documents'],
            'customers' => $data['customers'] ?? [],
            'products' => $data['products'] ?? [],
        ];
    }

    /** สรุปจำนวนลูกค้าที่จะสร้างใหม่/อัปเดตชื่อ และสินค้าที่จะสร้างใหม่ */
    public function previewMaster(array $parsed): array
    {
        $customers = $parsed['customers'] ?? [];
        $products = $parsed['products'] ?? [];

        $newCustomers = 0;
        $updatedCustomers = 0;
        $newProducts = 0;

        $uniqueCustomers = collect($customers)
            ->unique(fn ($c) => (string) ($c['ar_key'] ?? $c['customer_key'] ?? $c['code'] ?? ''))
            ->filter(fn ($c) => (string) ($c['ar_key'] ?? $c['customer_key'] ?? $c['code'] ?? '') !== '');

        if ($uniqueCustomers->isNotEmpty()) {
            $codes = $uniqueCustomers->map(fn ($c) => (string) ($c['ar_key'] ?? $c['customer_key'] ?? $c['code']))->all();
            $existingCustomers = DB::table('customers')->whereIn('code', $codes)->pluck('name_th', 'code');

            foreach ($uniqueCustomers as $c) {
                $code = (string) ($c['ar_key'] ?? $c['customer_key'] ?? $c['code']);
                $incomingName = trim((string) ($c['ar_name'] ?? $c['name'] ?? $c['customer_name'] ?? ''));
                if (! $existingCustomers->has($code)) {
                    $newCustomers++;
                } elseif ($incomingName !== '' && trim((string) $existingCustomers[$code]) !== $incomingName) {
                    $updatedCustomers++;
                }
            }
        }

        $uniqueProducts = collect($products)
            ->unique(fn ($p) => (string) ($p['sku_code'] ?? ''))
            ->filter(fn ($p) => (string) ($p['sku_code'] ?? '') !== '');

        if ($uniqueProducts->isNotEmpty()) {
            $skuCodes = $uniqueProducts->map(fn ($p) => (string) $p['sku_code'])->all();
            $existingSkus = DB::table('products')->whereIn('sku_code', $skuCodes)->pluck('sku_code')->flip();

            foreach ($uniqueProducts as $p) {
                $sku = (string) $p['sku_code'];
                if (! isset($existingSkus[$sku])) {
                    $newProducts++;
                }
            }
        }

        return [
            'new_customers' => $newCustomers,
            'updated_customers' => $updatedCustomers,
            'new_products' => $newProducts,
        ];
    }

    /** สรุปว่าไฟล์นี้จะเพิ่ม/อัปเดตอะไร โดยยังไม่บันทึก */
    public function preview(array $parsed): array
    {
        $keys = array_map(fn ($d) => (int) $d['bplus_key'], $parsed['documents']);
        $existing = DB::table('bplus_sync_documents')->whereIn('bplus_key', $keys)
            ->pluck('bplus_updated_at', 'bplus_key');

        $summary = [];
        foreach (self::KINDS as $kind) {
            $summary[$kind] = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'total' => 0.0];
        }
        foreach ($parsed['documents'] as $doc) {
            $row = &$summary[$doc['kind']];
            $key = (int) $doc['bplus_key'];
            if (! $existing->has($key)) {
                $row['new']++;
            } elseif ((string) $existing[$key] !== (string) ($doc['bplus_updated_at'] ?? '')) {
                $row['updated']++;
            } else {
                $row['unchanged']++;
            }
            if (empty($doc['is_cancelled'])) {
                $row['total'] = round($row['total'] + (float) ($doc['total_amount'] ?? 0), 2);
            }
            unset($row);
        }

        return $summary;
    }

    /** บันทึก (เพิ่มหรืออัปเดต) คืนจำนวนเอกสารที่บันทึก */
    public function import(array $parsed): int
    {
        $route = $parsed['route'];

        return DB::transaction(function () use ($parsed, $route) {
            $area = DB::table('sales_areas')->where('code', $route)->first();
            $branchId = $area?->branch_id ?? DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');

            $this->upsertCustomers($parsed['customers'] ?? [], $branchId);
            $this->upsertProducts($parsed['products'] ?? []);

            $count = 0;
            foreach ($parsed['documents'] as $doc) {
                $now = now();
                $values = [
                    'kind' => $doc['kind'],
                    'route_code' => $route,
                    'book_code' => $doc['book_code'],
                    'ref' => $doc['ref'],
                    'doc_date' => substr((string) $doc['doc_date'], 0, 10),
                    'is_cancelled' => ! empty($doc['is_cancelled']),
                    'customer_code' => $doc['customer_code'] ?? null,
                    'customer_key' => isset($doc['customer_key']) ? (string) $doc['customer_key'] : null,
                    'customer_name' => $doc['customer_name'] ?? null,
                    'created_by' => $doc['created_by'] ?? null,
                    'salesman_code' => isset($doc['salesman_code']) && trim((string) $doc['salesman_code']) !== ''
                        ? trim((string) $doc['salesman_code'])
                        : null,
                    'booking_ref' => $doc['booking_ref'] ?? null,
                    'sale_ref' => $doc['sale_ref'] ?? null,
                    'vatable_amount' => $this->money($doc['vatable_amount'] ?? 0),
                    'exempt_amount' => $this->money($doc['exempt_amount'] ?? 0),
                    'vat_amount' => $this->money($doc['vat_amount'] ?? 0),
                    'total_amount' => $this->money($doc['total_amount'] ?? 0),
                    'due_date' => ! empty($doc['due_date']) ? substr((string) $doc['due_date'], 0, 10) : null,
                    'cash_amount' => $this->money($doc['cash_amount'] ?? 0),
                    'transfer_amount' => $this->money($doc['transfer_amount'] ?? 0),
                    'other_amount' => $this->money($doc['other_amount'] ?? 0),
                    'short_amount' => $this->money($doc['short_amount'] ?? 0),
                    'bplus_updated_at' => $doc['bplus_updated_at'] ?? null,
                    'updated_at' => $now,
                ];

                $id = DB::table('bplus_sync_documents')->where('bplus_key', (int) $doc['bplus_key'])->value('id');
                if ($id) {
                    DB::table('bplus_sync_documents')->where('id', $id)->update($values);
                } else {
                    $id = DB::table('bplus_sync_documents')->insertGetId($values + [
                        'bplus_key' => (int) $doc['bplus_key'],
                        'created_at' => $now,
                    ]);
                }

                DB::table('bplus_sync_lines')->where('bplus_sync_document_id', $id)->delete();
                foreach ($doc['lines'] ?? [] as $i => $line) {
                    DB::table('bplus_sync_lines')->insert([
                        'bplus_sync_document_id' => $id,
                        'seq' => (int) ($line['seq'] ?? $i + 1),
                        'sku_code' => $line['sku_code'] ?? null,
                        'barcode' => $line['barcode'] ?? null,
                        'unit_name' => $line['unit_name'] ?? null,
                        'unit_factor' => (float) ($line['unit_factor'] ?? 1),
                        'qty' => (float) ($line['qty'] ?? 0),
                        'free_qty' => (float) ($line['free_qty'] ?? 0),
                        'unit_price' => (float) ($line['unit_price'] ?? 0),
                        'discount' => $this->money($line['discount'] ?? 0),
                        'amount' => $this->money($line['amount'] ?? 0),
                        'has_vat' => (bool) ($line['has_vat'] ?? true),
                    ]);
                }

                DB::table('bplus_sync_allocations')->where('bplus_sync_document_id', $id)->delete();
                foreach ($doc['allocations'] ?? [] as $allocation) {
                    DB::table('bplus_sync_allocations')->insert([
                        'bplus_sync_document_id' => $id,
                        'sale_ref' => (string) $allocation['sale_ref'],
                        'paid_amount' => $this->money($allocation['paid_amount'] ?? 0),
                        'cash_amount' => $this->money($allocation['cash_amount'] ?? 0),
                        'transfer_amount' => $this->money($allocation['transfer_amount'] ?? 0),
                        'discount_amount' => $this->money($allocation['discount_amount'] ?? 0),
                        'wht_amount' => $this->money($allocation['wht_amount'] ?? 0),
                        'short_amount' => $this->money($allocation['short_amount'] ?? 0),
                    ]);
                }
                $count++;
            }

            return $count;
        });
    }

    private function upsertCustomers(array $customers, ?int $branchId): void
    {
        $uniqueCustomers = collect($customers)
            ->unique(fn ($c) => (string) ($c['ar_key'] ?? $c['customer_key'] ?? $c['code'] ?? ''))
            ->filter(fn ($c) => (string) ($c['ar_key'] ?? $c['customer_key'] ?? $c['code'] ?? '') !== '');

        foreach ($uniqueCustomers as $c) {
            $arKey = (string) ($c['ar_key'] ?? $c['customer_key'] ?? $c['code']);
            $name = trim((string) ($c['ar_name'] ?? $c['name'] ?? $c['customer_name'] ?? ''));
            $taxId = isset($c['tax_id']) && trim((string) $c['tax_id']) !== '' ? trim((string) $c['tax_id']) : null;
            $address = isset($c['address']) && trim((string) $c['address']) !== '' ? trim((string) $c['address']) : null;
            $phone = isset($c['phone']) && trim((string) $c['phone']) !== '' ? trim((string) $c['phone']) : null;

            $existing = DB::table('customers')->where('code', $arKey)->first();

            if (! $existing) {
                $customerId = DB::table('customers')->insertGetId([
                    'code' => $arKey,
                    'name_th' => $name ?: $arKey,
                    'name_en' => null,
                    'tax_id' => $taxId,
                    'branch_id' => $branchId,
                    'credit_limit' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($address !== null) {
                    DB::table('customer_addresses')->insert([
                        'customer_id' => $customerId,
                        'address_line' => $address,
                        'is_default' => true,
                    ]);
                }

                if ($phone !== null) {
                    DB::table('customer_contacts')->insert([
                        'customer_id' => $customerId,
                        'name' => $name ?: $arKey,
                        'phone' => $phone,
                        'email' => null,
                    ]);
                }
            } else {
                $customerId = $existing->id;
                $updateValues = ['updated_at' => now()];
                if ($name !== '') {
                    $updateValues['name_th'] = $name;
                }
                if ($taxId !== null) {
                    $updateValues['tax_id'] = $taxId;
                }
                DB::table('customers')->where('id', $customerId)->update($updateValues);

                if ($address !== null) {
                    $addr = DB::table('customer_addresses')
                        ->where('customer_id', $customerId)
                        ->orderByDesc('is_default')
                        ->orderBy('id')
                        ->first();
                    if ($addr) {
                        DB::table('customer_addresses')->where('id', $addr->id)->update([
                            'address_line' => $address,
                            'is_default' => true,
                        ]);
                    } else {
                        DB::table('customer_addresses')->insert([
                            'customer_id' => $customerId,
                            'address_line' => $address,
                            'is_default' => true,
                        ]);
                    }
                }

                if ($phone !== null) {
                    $contact = DB::table('customer_contacts')
                        ->where('customer_id', $customerId)
                        ->orderBy('id')
                        ->first();
                    if ($contact) {
                        DB::table('customer_contacts')->where('id', $contact->id)->update([
                            'phone' => $phone,
                        ]);
                    } else {
                        DB::table('customer_contacts')->insert([
                            'customer_id' => $customerId,
                            'name' => $name ?: $existing->name_th,
                            'phone' => $phone,
                            'email' => null,
                        ]);
                    }
                }
            }
        }
    }

    private function upsertProducts(array $products): void
    {
        $uniqueProducts = collect($products)
            ->unique(fn ($p) => (string) ($p['sku_code'] ?? ''))
            ->filter(fn ($p) => (string) ($p['sku_code'] ?? '') !== '');

        foreach ($uniqueProducts as $p) {
            $skuCode = trim((string) $p['sku_code']);
            $existing = DB::table('products')->where('sku_code', $skuCode)->first();

            if ($existing) {
                continue;
            }

            $unitName = trim((string) ($p['unit_name'] ?? ''));
            $unitId = null;
            if ($unitName !== '') {
                $unitId = DB::table('product_units')->where('name', $unitName)->value('id')
                    ?? DB::table('product_units')->where('code', $unitName)->value('id');
            }
            if (! $unitId) {
                if ($unitName !== '') {
                    $code = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $unitName) ?: 'UNIT', 0, 10));
                    if (DB::table('product_units')->where('code', $code)->exists()) {
                        $code = 'U_'.Str::upper(Str::random(6));
                    }
                    $unitId = DB::table('product_units')->insertGetId([
                        'code' => $code,
                        'name' => $unitName,
                        'qty_per_base_unit' => 1,
                        'is_active' => true,
                    ]);
                } else {
                    $unitId = DB::table('product_units')->orderBy('id')->value('id');
                }
            }
            if (! $unitId) {
                $unitId = DB::table('product_units')->insertGetId([
                    'code' => 'EA',
                    'name' => 'ชิ้น',
                    'qty_per_base_unit' => 1,
                    'is_active' => true,
                ]);
            }

            DB::table('products')->insert([
                'sku_code' => $skuCode,
                'name_th' => trim((string) ($p['name'] ?? $skuCode)) ?: $skuCode,
                'name_en' => null,
                'base_unit_id' => $unitId,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
