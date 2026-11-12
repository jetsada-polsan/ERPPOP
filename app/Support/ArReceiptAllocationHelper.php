<?php

namespace App\Support;

class ArReceiptAllocationHelper
{
    /**
     * กระจายยอดชำระลงบิลที่เลือก โดยเรียงวันที่เอกสารเก่าสุดก่อน แล้วตามด้วยเลขที่เอกสาร
     * คำนวณเป็นสตางค์จำนวนเต็ม (Integer Satang) เพื่อความแม่นยำระดับทศนิยมสุดท้าย
     *
     * @param array<int, array{
     *     id: int,
     *     doc_number?: string,
     *     doc_date?: string,
     *     balance: float|int|string,
     *     checked?: bool
     * }> $bills
     * @param  float|int|string  $paidAmount  ยอดที่ลูกค้าจ่าย
     * @param  float|int|string  $roundingAmount  เศษขาด/เกิน (-1.00 ถึง +1.00)
     * @return array{
     *     allocations: array<int, array{
     *         customer_open_item_id: int,
     *         doc_number: string,
     *         doc_date: string,
     *         balance: float,
     *         amount: float,
     *         remaining_balance: float,
     *         checked: bool
     *     }>,
     *     total_selected_debt: float,
     *     total_allocated: float,
     *     total_paid: float,
     *     next_payment_remaining: float,
     *     rounding_amount: float,
     *     excess_amount: float
     * }
     */
    public static function distribute(
        array $bills,
        float|int|string $paidAmount,
        float|int|string $roundingAmount = 0.0,
        bool $throwOnExcess = true
    ): array {
        $roundingFloat = (float) $roundingAmount;
        if (abs($roundingFloat) > 1.009) {
            throw new \InvalidArgumentException(sprintf('เศษขาด/เกิน ต้องอยู่ระหว่าง -1.00 ถึง +1.00 บาท (ระบุ: %.2f)', $roundingFloat));
        }

        $paidSatang = (int) round(((float) $paidAmount) * 100);
        $roundingSatang = (int) round($roundingFloat * 100);

        // ยอดหนี้ที่ต้องตัดชำระทั้งหมด = ยอดจ่ายจริง - เศษขาด/เกิน
        // เช่น จ่าย 2,039.00 เศษขาด -1.00 => หนี้ที่ตัดได้ = 2,039 - (-1) = 2,040.00
        $allocatableSatang = max(0, $paidSatang - $roundingSatang);

        // คัดเฉพาะบิลที่ติ๊กเลือก (ถ้าไม่มี checked ให้ถือว่าเลือกทั้งหมด)
        $selectedBills = array_filter($bills, fn ($b) => ! isset($b['checked']) || (bool) $b['checked']);

        // คำนวณยอดหนี้รวมก่อน
        $totalSelectedDebtSatang = 0;
        foreach ($selectedBills as $b) {
            $totalSelectedDebtSatang += (int) round(((float) ($b['balance'] ?? 0)) * 100);
        }

        if ($throwOnExcess && $allocatableSatang > $totalSelectedDebtSatang) {
            throw new \InvalidArgumentException(sprintf(
                'ยอดชำระ (%.2f) เกินยอดหนี้ที่เลือก (%.2f)',
                $allocatableSatang / 100,
                $totalSelectedDebtSatang / 100
            ));
        }

        $remainingSatang = $allocatableSatang;

        // เรียงลำดับ: วันที่เอกสารเก่าสุดก่อน (asc), ถ้าวันที่เท่ากันเรียงเลขที่เอกสาร (asc)
        usort($selectedBills, function ($a, $b) {
            $dateA = (string) ($a['doc_date'] ?? '');
            $dateB = (string) ($b['doc_date'] ?? '');
            if ($dateA !== $dateB) {
                return strcmp($dateA, $dateB);
            }
            $numA = (string) ($a['doc_number'] ?? '');
            $numB = (string) ($b['doc_number'] ?? '');
            if ($numA !== $numB) {
                return strcmp($numA, $numB);
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $results = [];
        $totalAllocatedSatang = 0;
        $nextPaymentRemainingSatang = 0;

        foreach ($selectedBills as $bill) {
            $billId = (int) ($bill['id'] ?? $bill['customer_open_item_id'] ?? 0);
            $balanceSatang = (int) round(((float) ($bill['balance'] ?? 0)) * 100);

            $allocateSatang = 0;
            if ($remainingSatang > 0) {
                $allocateSatang = min($remainingSatang, $balanceSatang);
                $remainingSatang -= $allocateSatang;
            }

            $remainingBillSatang = $balanceSatang - $allocateSatang;
            $totalAllocatedSatang += $allocateSatang;
            $nextPaymentRemainingSatang += $remainingBillSatang;

            $results[] = [
                'id' => $billId,
                'customer_open_item_id' => $billId,
                'doc_number' => (string) ($bill['doc_number'] ?? '-'),
                'doc_date' => (string) ($bill['doc_date'] ?? '-'),
                'balance' => round($balanceSatang / 100, 2),
                'amount' => round($allocateSatang / 100, 2),
                'remaining' => round($remainingBillSatang / 100, 2),
                'remaining_balance' => round($remainingBillSatang / 100, 2),
                'checked' => true,
            ];
        }

        // ยอดเงินที่จ่ายเกินหนี้ที่เลือก (ถ้ามี)
        $excessSatang = max(0, $remainingSatang);

        return [
            'allocations' => $results,
            'total_debt' => round($totalSelectedDebtSatang / 100, 2),
            'total_selected_debt' => round($totalSelectedDebtSatang / 100, 2),
            'total_allocated' => round($totalAllocatedSatang / 100, 2),
            'total_paid' => round(((float) $paidAmount), 2),
            'next_payment_remaining' => round($nextPaymentRemainingSatang / 100, 2),
            'rounding_amount' => round($roundingSatang / 100, 2),
            'excess_amount' => round($excessSatang / 100, 2),
        ];
    }
}
