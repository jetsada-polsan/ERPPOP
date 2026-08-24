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
     * ถอดป้ายเครื่องชั่งตาม profile ที่ตั้งไว้ในระบบ
     *
     * ไม่เดารูปแบบเอง — รูปแบบมาจากตาราง scale_barcode_profiles เพราะเครื่องชั่ง
     * คนละรุ่นออกป้ายคนละแบบ และการเดาผิดแปลว่าคิดเงินผิดทันทีที่หน้าเคาน์เตอร์
     *
     * คืน null เมื่อไม่ตรง profile ไหนเลย ผู้เรียกจะได้ไปหาบาร์โค้ดปกติต่อ
     *
     * @return array{plu: string, price: float, profile: string}|null
     */
    public function decode(string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '' || ! ctype_digit($barcode)) {
            return null;
        }

        foreach ($this->profiles() as $profile) {
            $decoded = $this->decodeWith($barcode, $profile);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  object  $profile
     * @return array{plu: string, price: float, profile: string}|null
     */
    private function decodeWith(string $barcode, $profile): ?array
    {
        if (strlen($barcode) !== (int) $profile->total_length) {
            return null;
        }
        if (! str_starts_with($barcode, (string) $profile->prefix)) {
            return null;
        }

        $pluLength = (int) $profile->plu_length;
        $valueLength = (int) $profile->value_length;
        $plu = substr($barcode, 0, $pluLength);
        $value = substr($barcode, $pluLength, $valueLength);
        if (! ctype_digit($plu) || ! ctype_digit($value)) {
            return null;
        }

        // 800-839 เป็นรหัสประเทศ EAN ของอิตาลีด้วย ป้ายที่บังคับ check digit
        // จึงเป็นตัวกันไม่ให้สินค้านำเข้าถูกอ่านเป็นป้ายชั่ง และกันการแก้ PLU บนป้าย
        if ($profile->check_digit === 'ean13') {
            $body = substr($barcode, 0, (int) $profile->total_length - 1);
            if ((int) substr($barcode, -1) !== $this->checkDigit($body)) {
                return null;
            }
        }

        return [
            'plu' => $plu,
            'price' => $profile->value_type === 'price' ? (int) $value / 100 : (int) $value,
            'profile' => (string) $profile->code,
        ];
    }

    /**
     * profile ที่เปิดใช้อยู่ เรียงให้ตัวที่ตรวจ check digit มาก่อน
     *
     * ป้ายเดียวกันอาจเข้าได้ทั้งแบบตรวจและไม่ตรวจ ถ้าให้แบบไม่ตรวจชนะ
     * ป้ายที่ถูกแก้ตัวเลขจะผ่านไปได้ทั้งที่ควรถูกปฏิเสธ
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function profiles()
    {
        return \Illuminate\Support\Facades\DB::table('scale_barcode_profiles')
            ->where('is_active', true)
            ->orderByRaw("case when check_digit = 'ean13' then 0 else 1 end")
            ->orderByDesc('total_length')
            ->get();
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
