<?php

namespace App\Services\Sales;

use App\Support\DecimalMath;
use RuntimeException;

class PosPaymentValidator
{
    public function validate(array $data): float
    {
        $total = DecimalMath::round(DecimalMath::sum(collect($data['items'])->map(
            fn (array $item) => DecimalMath::multiply($item['qty'], $item['unit_price'])
        )), DecimalMath::DISPLAY_MONEY_SCALE);

        if (in_array($data['method'], ['transfer', 'mixed'], true)
            && ! ($data['payment_confirmed'] ?? false)) {
            throw new RuntimeException('กรุณาตรวจเงินเข้าก่อนออกบิล');
        }

        if ($data['method'] === 'cash') {
            $received = DecimalMath::round($data['cash_received'] ?? 0, DecimalMath::DISPLAY_MONEY_SCALE);
            if (DecimalMath::compare(DecimalMath::add($received, '0.01', 2), $total) < 0) {
                $short = number_format((float) DecimalMath::subtract($total, $received, 2), 2);
                throw new RuntimeException("ยอดเงินสดที่รับไม่ครบ ขาดอีก {$short} บาท");
            }
        }

        if ($data['method'] === 'mixed') {
            $cash = DecimalMath::round($data['cash_amount'] ?? 0, DecimalMath::DISPLAY_MONEY_SCALE);
            $transfer = DecimalMath::round($data['transfer_amount'] ?? 0, DecimalMath::DISPLAY_MONEY_SCALE);
            if (DecimalMath::compare($cash, '0.01') < 0 || DecimalMath::compare($transfer, '0.01') < 0) {
                throw new RuntimeException('จ่ายผสมต้องระบุทั้งยอดเงินสดและยอดโอน');
            }
            if (DecimalMath::compare(DecimalMath::absoluteDifference(DecimalMath::add($cash, $transfer, 2), $total, 2), '0.01') > 0) {
                throw new RuntimeException('ยอดเงินสด+โอนต้องเท่ากับยอดบิล');
            }
        }

        return (float) $total;
    }
}
