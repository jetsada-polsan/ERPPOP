<?php

namespace Tests\Unit;

use App\Support\PosLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Laravel กับ POS Python ต้อง normalize pos_layout.runtime ให้ได้ค่าเดียวกันเป๊ะ
 * ไม่งั้น cache ที่พังชิ้นเดียวกันจะทำให้หน้าขายเว็บกับเครื่องสาขาหน้าตาไม่เหมือนกัน
 *
 * ทั้งสองฝั่งอ่าน fixture ไฟล์เดียวกัน ฝั่ง Python คือ
 * apps/pos-python/tests/test_pos_layout_parity.py — แก้ fixture ต้องรันทั้งสองชุด
 */
class PosLayoutParityTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/pos-layout-runtime-parity.json';

    public function test_the_defaults_match_the_shared_contract(): void
    {
        $this->assertSame(self::fixture()['defaults'], PosLayout::defaultRuntime());
    }

    #[DataProvider('cases')]
    public function test_runtime_normalizes_exactly_like_the_python_pos(array $input, array $expected): void
    {
        $want = array_merge(self::fixture()['defaults'], $expected);
        ksort($want);
        $got = PosLayout::normalizeRuntime($input);
        ksort($got);

        $this->assertSame($want, $got);
    }

    public function test_a_malformed_component_position_falls_back_instead_of_collapsing_to_the_edge(): void
    {
        $components = PosLayout::componentsFrom([
            ['id' => 'cart', 'type' => 'cart', 'x' => 'bad', 'y' => '2', 'w' => '6abc', 'h' => null],
        ]);

        $this->assertSame(
            [['id' => 'cart', 'type' => 'cart', 'x' => 1, 'y' => 2, 'w' => 3, 'h' => 2]],
            $components,
        );
    }

    /** @return array<string, array{0: array, 1: array}> */
    public static function cases(): array
    {
        $rows = [];
        foreach (self::fixture()['cases'] as $case) {
            $rows[$case['name']] = [$case['input'], $case['expected']];
        }

        return $rows;
    }

    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    }
}
