<?php

namespace Tests\Unit;

use App\Services\Sales\BookingSplitPlan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BookingSplitPlanTest extends TestCase
{
    private function line(int $id, bool $vat, string $qty, string $price): object
    {
        return (object) ['id' => $id, 'product' => (object) ['is_vat' => $vat], 'qty' => $qty, 'unit_price' => $price];
    }

    public function test_groups_explicit_assignments_without_losing_precision(): void
    {
        $books = collect([1 => (object) ['is_active' => true, 'no_vat' => false], 2 => (object) ['is_active' => true, 'no_vat' => true]]);
        $lines = collect([$this->line(10, false, '1', '1180'), $this->line(11, false, '4', '645')]);
        $plan = (new BookingSplitPlan)->prepare($lines, [10 => 1, 11 => 2], $books);
        $this->assertSame('1180.00000000', $plan[1]['total']);
        $this->assertSame('2580.00000000', $plan[2]['total']);
        $this->assertSame([10, 11], collect($plan)->flatMap(fn ($group) => $group['items'])->pluck('id')->all());
    }

    public function test_rejects_missing_or_foreign_lines(): void
    {
        $this->expectException(RuntimeException::class);
        (new BookingSplitPlan)->prepare(collect([$this->line(10, false, '1', '1')]), [11 => 1], collect());
    }

    public function test_rejects_taxable_line_in_no_vat_book(): void
    {
        $this->expectException(RuntimeException::class);
        (new BookingSplitPlan)->prepare(collect([$this->line(10, true, '1', '1')]), [10 => 2], collect([2 => (object) ['is_active' => true, 'no_vat' => true]]));
    }
}
