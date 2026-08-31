<?php

namespace App\Services\OCR;

class MockOcrEngine implements OcrEngineInterface
{
    public function extract(string $filePath, string $mimeType, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $rawText = in_array($extension, ['txt', 'csv'], true) && is_readable($filePath)
            ? (string) file_get_contents($filePath)
            : '';

        return [
            'engine' => 'mock',
            'raw_text' => $rawText,
            'confidence_score' => $rawText === '' ? 0.0 : 0.75,
        ];
    }
}
