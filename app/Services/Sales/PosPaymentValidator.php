<?php

namespace App\Services\Sales;

use RuntimeException;

class PosPaymentValidator
{
    public function validate(array $data): float
    {
        $total = round(collect($data['items'])->sum(
            fn (array $item) => (float) $item['qty'] * (float) $item['unit_price']
        ), 2);

        if (in_array($data['method'], ['transfer', 'mixed'], true)
            && ! ($data['payment_confirmed'] ?? false)) {
            throw new RuntimeException('กรุณาตรวจเงินเข้าก่อนออกบิล');
        }

        if ($data['method'] === 'cash') {
            $received = round((float) ($data['cash_received'] ?? 0), 2);
            if ($received + 0.01 < $total) {
                $short = number_format($total - $received, 2);
                throw new RuntimeException("ยอดเงินสดที่รับไม่ครบ ขาดอีก {$short} บาท");
            }
        }

        if ($data['method'] === 'mixed') {
            $cash = round((float) ($data['cash_amount'] ?? 0), 2);
            $transfer = round((float) ($data['transfer_amount'] ?? 0), 2);
            if ($cash < 0.01 || $transfer < 0.01) {
                throw new RuntimeException('จ่ายผสมต้องระบุทั้งยอดเงินสดและยอดโอน');
            }
            if (abs($cash + $transfer - $total) > 0.01) {
                throw new RuntimeException('ยอดเงินสด+โอนต้องเท่ากับยอดบิล');
            }
        }

        return $total;
    }
}
