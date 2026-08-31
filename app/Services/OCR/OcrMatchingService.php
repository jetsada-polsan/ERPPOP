<?php

namespace App\Services\OCR;

use App\Models\OcrDocument;
use App\Models\OcrExtractedLine;
use App\Models\OcrMatchResult;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;

class OcrMatchingService
{
    public function match(OcrDocument $document, ?string $supplierName = null, ?string $supplierTaxId = null): void
    {
        $supplier = $document->supplier;
        if (! $supplier && ($supplierName || $supplierTaxId)) {
            $supplier = Supplier::query()
                ->where('is_active', true)
                ->when($supplierTaxId, fn ($q) => $q->where('tax_id', $supplierTaxId))
                ->when(! $supplierTaxId && $supplierName, fn ($q) => $q->where(fn ($w) => $w
                    ->where('code', $supplierName)
                    ->orWhere('name_th', $supplierName)
                    ->orWhere('name_en', $supplierName)
                ))
                ->first();
            if ($supplier) {
                $document->update(['supplier_id' => $supplier->id]);
            }
        }

        foreach ($document->lines()->get() as $line) {
            $this->matchLine($document, $line, $supplier);
        }
    }

    private function matchLine(OcrDocument $document, OcrExtractedLine $line, ?Supplier $supplier): void
    {
        $document->matchResults()->where('ocr_extracted_line_id', $line->id)->delete();
        $candidates = collect();

        if ($supplier && $line->extracted_product_code) {
            $mapping = SupplierProductMapping::with('product.baseUnit')
                ->where('supplier_id', $supplier->id)
                ->where('supplier_product_code', $line->extracted_product_code)
                ->first();
            if ($mapping?->product?->is_active) {
                $candidates->push(['product' => $mapping->product, 'unit_id' => $mapping->unit_id ?: $mapping->product->base_unit_id, 'score' => 0.99, 'type' => 'supplier_mapping']);
            }
        }

        if ($line->extracted_barcode) {
            $barcode = ProductBarcode::with('product.baseUnit')
                ->where('barcode', $line->extracted_barcode)
                ->where('is_active', true)
                ->first();
            if ($barcode?->product?->is_active) {
                $candidates->push(['product' => $barcode->product, 'unit_id' => $barcode->unit_id, 'score' => 1.0, 'type' => 'barcode']);
            }
        }

        if ($line->extracted_product_code) {
            Product::with('baseUnit')->where('is_active', true)
                ->where(fn ($q) => $q->where('sku_code', $line->extracted_product_code)->orWhere('legacy_sku', $line->extracted_product_code))
                ->get()->each(fn ($product) => $candidates->push(['product' => $product, 'unit_id' => $product->base_unit_id, 'score' => 0.98, 'type' => 'product_code']));
        }

        if ($line->extracted_product_name) {
            Product::with('baseUnit')->where('is_active', true)
                ->where(fn ($q) => $q
                    ->where('name_th', $line->extracted_product_name)
                    ->orWhere('name_th', 'like', '%'.$line->extracted_product_name.'%')
                    ->orWhere('name_en', 'like', '%'.$line->extracted_product_name.'%'))
                ->limit(5)->get()->each(fn ($product) => $candidates->push(['product' => $product, 'unit_id' => $product->base_unit_id, 'score' => $product->name_th === $line->extracted_product_name ? 0.92 : 0.80, 'type' => 'name']));
        }

        $unique = $candidates->unique(fn (array $candidate) => $candidate['product']->id)->sortByDesc('score')->values();
        foreach ($unique as $candidate) {
            OcrMatchResult::create([
                'ocr_document_id' => $document->id,
                'ocr_extracted_line_id' => $line->id,
                'match_type' => $candidate['type'],
                'candidate_id' => (string) $candidate['product']->id,
                'candidate_name' => $candidate['product']->name_th,
                'score' => $candidate['score'],
                'selected' => false,
            ]);
        }

        $top = $unique->first();
        $second = $unique->get(1);
        $isAmbiguous = $top && $second && abs((float) $top['score'] - (float) $second['score']) < 0.05;
        $line->update([
            'matched_product_id' => $isAmbiguous || ! $top ? null : $top['product']->id,
            'matched_unit_id' => $isAmbiguous || ! $top ? null : $top['unit_id'],
            'match_status' => ! $top ? 'unmatched' : ($isAmbiguous ? 'ambiguous' : 'matched'),
        ]);

        if (! $isAmbiguous && $top) {
            $document->matchResults()->where('ocr_extracted_line_id', $line->id)->where('candidate_id', (string) $top['product']->id)->update(['selected' => true]);
        }
    }
}
