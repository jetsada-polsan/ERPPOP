<?php

namespace Tests\Feature;

use App\Support\AutoCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_code_runs_next_number_and_typed_code_stays(): void
    {
        $period = 'PM-'.(now()->year + 543).now()->format('m').'-';
        DB::table('promotions')->insert(['code' => $period.'007', 'name' => 'x'] + (array) []);

        $blank = Request::create('/', 'POST', ['code' => '']);
        AutoCode::fill($blank, 'promotions', 'PM');
        $this->assertSame($period.'008', $blank->input('code'));

        $typed = Request::create('/', 'POST', ['code' => 'MINE']);
        AutoCode::fill($typed, 'promotions', 'PM');
        $this->assertSame('MINE', $typed->input('code'));
    }
}
