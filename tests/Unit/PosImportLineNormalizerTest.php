<?php

namespace Tests\Unit;

use App\Services\PosImport\PosImportLineNormalizer;
use Tests\TestCase;

class PosImportLineNormalizerTest extends TestCase
{
    public function test_it_uses_net_amount_for_normal_completed_line(): void
    {
        $this->assertSame(49.29, PosImportLineNormalizer::amount([
            'PSD_STATUS' => '0', 'PSD_N_AMT' => '49.2900', 'PSD_G_AMT' => '49.2900',
        ]));
    }

    public function test_it_uses_gross_amount_when_status_two_has_zero_net_amount(): void
    {
        $this->assertSame(39.0, PosImportLineNormalizer::amount([
            'PSD_STATUS' => '2', 'PSD_N_AMT' => '0.0000', 'PSD_G_AMT' => '39.0000', 'PSD_G_SELL' => '36.4500',
        ]));
    }

    public function test_it_excludes_cancelled_detail_statuses_from_posting(): void
    {
        foreach (['4', '8'] as $status) {
            $raw = ['PSD_STATUS' => $status, 'PSD_N_AMT' => '0.0000', 'PSD_G_AMT' => '159.0000'];
            $this->assertFalse(PosImportLineNormalizer::isPostingLine($raw));
            $this->assertSame(0.0, PosImportLineNormalizer::amount($raw));
        }
    }
}
