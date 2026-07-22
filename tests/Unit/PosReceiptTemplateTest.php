<?php

namespace Tests\Unit;

use App\Support\PosReceiptTemplate;
use PHPUnit\Framework\TestCase;

class PosReceiptTemplateTest extends TestCase
{
    public function test_it_filters_unknown_blocks_and_normalizes_properties(): void
    {
        $template = PosReceiptTemplate::sanitize([
            'paper_width' => 61,
            'blocks' => [
                ['id' => 'custom <script>', 'type' => 'custom', 'align' => 'diagonal', 'size' => 'huge', 'bold' => 'yes', 'text' => str_repeat('ก', 200)],
                ['id' => 'bad', 'type' => 'html', 'text' => '<script>alert(1)</script>'],
            ],
        ]);

        $this->assertSame(80, $template['paper_width']);
        $this->assertSame('customscript', $template['blocks'][0]['id']);
        $this->assertSame('left', $template['blocks'][0]['align']);
        $this->assertSame('small', $template['blocks'][0]['size']);
        $this->assertTrue($template['blocks'][0]['bold']);
        $this->assertSame(160, mb_strlen($template['blocks'][0]['text']));
        $this->assertNotContains('html', array_column($template['blocks'], 'type'));
    }

    public function test_it_restores_required_fiscal_blocks_and_limits_fixed_duplicates(): void
    {
        $template = PosReceiptTemplate::sanitize([
            'paper_width' => 58,
            'blocks' => [
                ['id' => 'footer-one', 'type' => 'footer'],
                ['id' => 'footer-two', 'type' => 'footer'],
                ['id' => 'custom-one', 'type' => 'custom'],
                ['id' => 'custom-two', 'type' => 'custom'],
            ],
        ]);

        $types = array_column($template['blocks'], 'type');
        $this->assertSame(58, $template['paper_width']);
        $this->assertSame(1, count(array_keys($types, 'footer', true)));
        $this->assertSame(2, count(array_keys($types, 'custom', true)));
        foreach (['company', 'title', 'meta', 'items', 'totals'] as $required) {
            $this->assertContains($required, $types);
        }
    }

    public function test_it_makes_repeatable_block_ids_unique(): void
    {
        $template = PosReceiptTemplate::sanitize([
            'blocks' => [
                ['id' => 'note', 'type' => 'custom'],
                ['id' => 'note', 'type' => 'custom'],
            ],
        ]);

        $ids = array_column($template['blocks'], 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));
    }
}
