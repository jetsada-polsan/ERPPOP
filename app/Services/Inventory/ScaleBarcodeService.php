<?php

namespace App\Services\Inventory;

use RuntimeException;

class ScaleBarcodeService
{
    public function fromTotalPrice(string $plu, float $totalPrice): string
    {
        if (preg_match('/^80[01][0-9]{3}$/', $plu) !== 1) {
            throw new RuntimeException('สินค้าผลผลิตต้องมี PLU เครื่องชั่ง 6 หลัก ช่วง 800xxx หรือ 801xxx');
        }

        $cents = (int) round($totalPrice * 100);
        if ($cents < 0 || $cents > 999999) {
            throw new RuntimeException('ราคารวมต่อถุงเกินช่วงที่บาร์โค้ดเครื่องชั่งรองรับ');
        }

        $body = $plu.str_pad((string) $cents, 6, '0', STR_PAD_LEFT);

        return $body.$this->checkDigit($body);
    }

    /**
     * Read a price-embedded scale label back: 6-digit PLU + 6-digit total price
     * in satang + EAN-13 check digit. Returns null when the code is not a valid
     * scale label, so callers can fall through to a normal barcode lookup.
     *
     * @return array{plu: string, price: float}|null
     */
    public function decode(string $barcode): ?array
    {
        if (preg_match('/^(80[01][0-9]{3})([0-9]{6})([0-9])$/', trim($barcode), $matches) !== 1) {
            return null;
        }
        if ((int) $matches[3] !== $this->checkDigit($matches[1].$matches[2])) {
            return null;
        }

        return ['plu' => $matches[1], 'price' => (int) $matches[2] / 100];
    }

    public function svg(string $barcode, int $height = 42): string
    {
        if (preg_match('/^[0-9]{13}$/', $barcode) !== 1) {
            return '';
        }

        $left = [
            '0' => '0001101', '1' => '0011001', '2' => '0010011', '3' => '0111101', '4' => '0100011',
            '5' => '0110001', '6' => '0101111', '7' => '0111011', '8' => '0110111', '9' => '0001011',
        ];
        $right = array_map(fn (string $bits) => strtr($bits, '01', '10'), $left);
        $g = array_map(fn (string $bits) => strrev(strtr($bits, '01', '10')), $left);
        $parity = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
        $digits = str_split($barcode);
        $bits = '101';
        for ($i = 1; $i <= 6; $i++) {
            $bits .= $parity[(int) $digits[0]][$i - 1] === 'L' ? $left[$digits[$i]] : $g[$digits[$i]];
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bits .= $right[$digits[$i]];
        }
        $bits .= '101';

        $bars = '';
        foreach (str_split($bits) as $x => $bit) {
            if ($bit === '1') {
                $bars .= '<rect x="'.($x + 10).'" y="0" width="1" height="'.$height.'"/>';
            }
        }

        return '<svg viewBox="0 0 115 '.($height + 14).'" role="img" aria-label="'.$barcode.'" xmlns="http://www.w3.org/2000/svg"><g fill="#000">'.$bars.'</g><text x="57.5" y="'.($height + 11).'" text-anchor="middle" font-family="monospace" font-size="8">'.$barcode.'</text></svg>';
    }

    private function checkDigit(string $body): int
    {
        $sum = 0;
        foreach (str_split($body) as $index => $digit) {
            $sum += ((int) $digit) * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }
}
