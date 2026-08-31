<?php

namespace App\Services\OCR;

use Carbon\Carbon;

class OcrParserService
{
    /**
     * The mock format is intentionally plain text so it can be used in UAT
     * before a real OCR provider is installed.
     *
     * @return array{header:array<string,mixed>,lines:array<int,array<string,mixed>>}
     */
    public function parse(string $rawText): array
    {
        $header = [
            'reference_no' => null,
            'document_date' => null,
            'supplier_name' => null,
            'supplier_tax_id' => null,
            'branch_name' => null,
            'total_amount' => null,
            'vat_amount' => null,
            'net_amount' => null,
        ];
        $lines = [];

        foreach (preg_split('/\R/u', $rawText) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '|')) {
                $parsed = $this->parseItemLine($line);
                if ($parsed !== null) {
                    $lines[] = $parsed + ['raw_text' => $line, 'line_no' => count($lines) + 1];

                    continue;
                }
            }

            if (! preg_match('/^\s*([^:：]+)\s*[:：]\s*(.*?)\s*$/u', $line, $matches)) {
                continue;
            }
            $key = mb_strtolower(trim($matches[1]));
            $value = trim($matches[2]);

            if (str_contains($key, 'เลขที่') || str_contains($key, 'reference') || str_contains($key, 'document no')) {
                $header['reference_no'] = $value !== '' ? $value : null;
            } elseif (str_contains($key, 'วันที่') || str_contains($key, 'date')) {
                $header['document_date'] = $this->parseDate($value);
            } elseif (str_contains($key, 'ผู้ขาย') || str_contains($key, 'supplier')) {
                $header['supplier_name'] = $value !== '' ? $value : null;
            } elseif (str_contains($key, 'ภาษี') || str_contains($key, 'tax id') || str_contains($key, 'tax no')) {
                $header['supplier_tax_id'] = $value !== '' ? $value : null;
            } elseif (str_contains($key, 'สาขา') || str_contains($key, 'branch') || str_contains($key, 'คลัง')) {
                $header['branch_name'] = $value !== '' ? $value : null;
            } elseif (str_contains($key, 'ยอดสุทธิ') || str_contains($key, 'net')) {
                $header['net_amount'] = $this->number($value);
            } elseif (str_contains($key, 'vat') || str_contains($key, 'ภาษีมูลค่าเพิ่ม')) {
                $header['vat_amount'] = $this->number($value);
            } elseif (str_contains($key, 'ยอดรวม') || str_contains($key, 'total')) {
                $header['total_amount'] = $this->number($value);
            }
        }

        if ($header['net_amount'] === null && $header['total_amount'] !== null && $header['vat_amount'] !== null) {
            $header['net_amount'] = $header['total_amount'] - $header['vat_amount'];
        }

        return ['header' => $header, 'lines' => $lines];
    }

    private function parseItemLine(string $line): ?array
    {
        $parts = array_map('trim', explode('|', trim($line, '| ')));
        if (count($parts) < 5 || $this->looksLikeHeader($parts)) {
            return null;
        }

        // name | product_code | barcode | qty | unit | unit_price | discount | line_total
        return [
            'extracted_product_name' => $parts[0] !== '' ? $parts[0] : null,
            'extracted_product_code' => $parts[1] !== '' ? $parts[1] : null,
            'extracted_barcode' => $parts[2] !== '' ? $parts[2] : null,
            'extracted_qty' => $this->number($parts[3]),
            'extracted_unit' => $parts[4] !== '' ? $parts[4] : null,
            'extracted_unit_price' => $this->number($parts[5] ?? null),
            'extracted_discount' => $this->number($parts[6] ?? null),
            'extracted_line_total' => $this->number($parts[7] ?? null),
            'confidence_score' => 0.75,
        ];
    }

    private function looksLikeHeader(array $parts): bool
    {
        $text = mb_strtolower(implode(' ', $parts));

        return str_contains($text, 'barcode') || str_contains($text, 'product')
            || str_contains($text, 'จำนวน')
            || (str_contains($text, 'สินค้า') && str_contains($text, 'รหัส'));
    }

    private function number(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $normalized = preg_replace('/[^\d.-]/', '', str_replace(',', '', $value));

        return ($normalized !== null && is_numeric($normalized)) ? (float) $normalized : null;
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('/^(\\d{4})[-\\/.](\\d{1,2})[-\\/.](\\d{1,2})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/^(\\d{1,2})[-\\/.](\\d{1,2})[-\\/.](\\d{4})$/', $value, $m)) {
            $year = (int) $m[3];
            if ($year > 2400) {
                $year -= 543;
            }

            return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
