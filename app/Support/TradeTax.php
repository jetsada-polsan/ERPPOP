<?php

namespace App\Support;

final class TradeTax
{
    /** @return array{gross_unit:string, gross:string, base:string, vat:string} */
    public static function line(string|int|float $qty, string|int|float $price, bool $included, string $rate): array
    {
        $factor = DecimalMath::add(100, $rate);
        $grossUnit = $included ? DecimalMath::round($price, 8)
            : DecimalMath::divide(DecimalMath::multiply($price, $factor), 100);
        $gross = DecimalMath::multiply($qty, $grossUnit);
        $base = $included
            ? DecimalMath::divide(DecimalMath::multiply($gross, 100), $factor)
            : DecimalMath::multiply($qty, $price);

        return [
            'gross_unit' => $grossUnit,
            'gross' => $gross,
            'base' => $base,
            'vat' => DecimalMath::subtract($gross, $base),
        ];
    }
}
