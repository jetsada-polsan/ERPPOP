<?php

namespace Tests\Unit;

use App\Support\DecimalMath;
use PHPUnit\Framework\TestCase;

class DecimalMathTest extends TestCase
{
    public function test_weighted_cost_keeps_relative_error_below_required_threshold(): void
    {
        $oldValue = DecimalMath::multiply('10', '10');
        $receiptValue = DecimalMath::multiply('3', '33.33333333');
        $actual = DecimalMath::divide(
            DecimalMath::add($oldValue, $receiptValue),
            DecimalMath::add('10', '3'),
        );
        $expected = '15.384615383846153846';

        $this->assertSame('15.38461538', $actual);
        $this->assertLessThanOrEqual(
            0.00001,
            (float) DecimalMath::relativeErrorPercent($actual, $expected),
        );
    }

    public function test_vat_division_and_reconciliation_are_deterministic(): void
    {
        $cost = DecimalMath::divide('107', '1.07');
        $vat = DecimalMath::subtract('107', $cost);

        $this->assertSame('100.00000000', $cost);
        $this->assertSame('7.00000000', $vat);
        $this->assertSame('107.00000000', DecimalMath::add($cost, $vat));
    }
}
