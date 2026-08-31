<?php

namespace App\Services\OCR;

interface OcrEngineInterface
{
    /**
     * @return array{engine:string, raw_text:string, confidence_score:float}
     */
    public function extract(string $filePath, string $mimeType, string $originalName): array;
}
