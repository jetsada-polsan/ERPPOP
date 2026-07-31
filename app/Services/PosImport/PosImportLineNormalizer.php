<?php

namespace App\Services\PosImport;

/**
 * Normalises BPlus POS detail rows before they affect ERP sales or stock.
 * Raw source values remain untouched on the staged line for audit purposes.
 */
final class PosImportLineNormalizer
{
    /** BPlus detail statuses that are retained for audit but must not be posted. */
    private const NON_POSTING_STATUSES = ['4', '8'];

    /** @param array<string, mixed> $rawData */
    public static function isPostingLine(array $rawData): bool
    {
        return ! in_array((string) ($rawData['PSD_STATUS'] ?? ''), self::NON_POSTING_STATUSES, true);
    }

    /** @param array<string, mixed> $rawData */
    public static function amount(array $rawData): float
    {
        if (! self::isPostingLine($rawData)) {
            return 0.0;
        }

        $netAmount = (float) ($rawData['PSD_N_AMT'] ?? 0);
        if (abs($netAmount) >= 0.000001) {
            return $netAmount;
        }

        // PSD_STATUS=2 keeps its actual charged amount in PSD_G_AMT while the
        // net fields are zero. PSD_G_SELL is VAT-exclusive and is only a fallback.
        $grossAmount = (float) ($rawData['PSD_G_AMT'] ?? 0);
        if (abs($grossAmount) >= 0.000001) {
            return $grossAmount;
        }

        return (float) ($rawData['PSD_G_SELL'] ?? 0);
    }
}
