<?php

namespace App\Support;

/**
 * Standard Code 128 barcode generator (Code Set B)
 * Produces clean standalone vector SVG without external dependencies.
 */
class Code128
{
    /** @var array<int, string> 107 standard Code 128 width patterns (11 modules each, stop is 13) */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const STOP = 106;

    /**
     * Render a standard Code 128 vector SVG barcode.
     */
    public static function svg(string $text, int $height = 40, bool $showText = true): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Keep printable ASCII (32..126)
        $cleanText = '';
        for ($i = 0; $i < strlen($text); $i++) {
            $ord = ord($text[$i]);
            if ($ord >= 32 && $ord <= 126) {
                $cleanText .= $text[$i];
            }
        }

        if ($cleanText === '') {
            $cleanText = $text;
        }

        $codes = [self::START_B];
        $checksum = self::START_B;

        for ($i = 0; $i < strlen($cleanText); $i++) {
            $val = ord($cleanText[$i]) - 32;
            $codes[] = $val;
            $checksum += $val * ($i + 1);
        }

        $codes[] = $checksum % 103;
        $codes[] = self::STOP;

        $quietZone = 10;
        $x = $quietZone;
        $rects = '';

        foreach ($codes as $code) {
            $pattern = self::PATTERNS[$code] ?? self::PATTERNS[0];
            $isBar = true;
            for ($k = 0; $k < strlen($pattern); $k++) {
                $width = (int) $pattern[$k];
                if ($isBar) {
                    $rects .= '<rect x="'.$x.'" y="0" width="'.$width.'" height="'.$height.'"/>';
                }
                $x += $width;
                $isBar = ! $isBar;
            }
        }

        $totalWidth = $x + $quietZone;
        $totalHeight = $showText ? ($height + 15) : $height;

        $svgText = $showText
            ? '<text x="'.($totalWidth / 2).'" y="'.($height + 11).'" text-anchor="middle" font-family="monospace" font-size="9" fill="#111827">'.htmlspecialchars($cleanText, ENT_QUOTES, 'UTF-8').'</text>'
            : '';

        return '<svg viewBox="0 0 '.$totalWidth.' '.$totalHeight.'" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="'.htmlspecialchars($cleanText, ENT_QUOTES, 'UTF-8').'" class="code128-barcode"><g fill="#000">'.$rects.'</g>'.$svgText.'</svg>';
    }
}
