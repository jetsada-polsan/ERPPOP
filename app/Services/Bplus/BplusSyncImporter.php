<?php

namespace App\Services\Bplus;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * รับไฟล์ JSON ที่ scripts/bplus-route-export.py ดึงจาก BPlus แล้วเก็บเป็นสำเนาเอกสาร
 * ใช้ bplus_key (DI_KEY) เป็นตัวกัน: ใบเดิมจะถูกอัปเดต ไม่สร้างซ้ำ
 * ไม่แตะสต๊อก ลูกหนี้ หรือ GL ของ ERP
 */
class BplusSyncImporter
{
    private const KINDS = ['booking', 'credit_sale', 'receipt'];

    /** @return array{route: string, documents: array<int, array>} */
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
                    throw new InvalidArgumentException("เอกสารแถว ".($index + 1)." ไม่มี {$field}");
                }
            }
            if (! in_array($doc['kind'], self::KINDS, true)) {
                throw new InvalidArgumentException("เอกสาร {$doc['ref']} ประเภทไม่รู้จัก");
            }
        }

        return ['route' => $route, 'documents' => $data['documents']];
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
                    'customer_name' => $doc['customer_name'] ?? null,
                    'created_by' => $doc['created_by'] ?? null,
                    'booking_ref' => $doc['booking_ref'] ?? null,
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

    private function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
