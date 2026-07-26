<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class DecimalMath
{
    public const QUANTITY_SCALE = 8;

    public const COST_SCALE = 8;

    public const MONEY_SCALE = 4;

    public const DISPLAY_MONEY_SCALE = 2;

    public static function of(BigDecimal|int|float|string|null $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }

        return BigDecimal::of((string) $value);
    }

    public static function add(BigDecimal|int|float|string|null $left, BigDecimal|int|float|string|null $right, int $scale = self::COST_SCALE): string
    {
        return (string) self::of($left)->plus(self::of($right))->toScale($scale, RoundingMode::HalfUp);
    }

    public static function subtract(BigDecimal|int|float|string|null $left, BigDecimal|int|float|string|null $right, int $scale = self::COST_SCALE): string
    {
        return (string) self::of($left)->minus(self::of($right))->toScale($scale, RoundingMode::HalfUp);
    }

    public static function multiply(BigDecimal|int|float|string|null $left, BigDecimal|int|float|string|null $right, int $scale = self::COST_SCALE): string
    {
        return (string) self::of($left)->multipliedBy(self::of($right))->toScale($scale, RoundingMode::HalfUp);
    }

    public static function divide(BigDecimal|int|float|string|null $numerator, BigDecimal|int|float|string|null $denominator, int $scale = self::COST_SCALE): string
    {
        $divisor = self::of($denominator);
        if ($divisor->isZero()) {
            return (string) BigDecimal::zero()->toScale($scale);
        }

        return (string) self::of($numerator)->dividedBy($divisor, $scale, RoundingMode::HalfUp);
    }

    public static function sum(iterable $values, int $scale = self::COST_SCALE): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::of($value));
        }

        return (string) $total->toScale($scale, RoundingMode::HalfUp);
    }

    public static function round(BigDecimal|int|float|string|null $value, int $scale): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function compare(BigDecimal|int|float|string|null $left, BigDecimal|int|float|string|null $right): int
    {
        return self::of($left)->compareTo(self::of($right));
    }

    public static function absoluteDifference(BigDecimal|int|float|string|null $left, BigDecimal|int|float|string|null $right, int $scale = self::COST_SCALE): string
    {
        return (string) self::of($left)->minus(self::of($right))->abs()->toScale($scale, RoundingMode::HalfUp);
    }

    public static function relativeErrorPercent(BigDecimal|int|float|string|null $actual, BigDecimal|int|float|string|null $expected, int $scale = 10): string
    {
        $base = self::of($expected)->abs();
        if ($base->isZero()) {
            return self::of($actual)->isZero()
                ? (string) BigDecimal::zero()->toScale($scale)
                : (string) BigDecimal::of('100')->toScale($scale);
        }

        return (string) self::of($actual)
            ->minus(self::of($expected))
            ->abs()
            ->multipliedBy(100)
            ->dividedBy($base, $scale, RoundingMode::HalfUp);
    }
}
