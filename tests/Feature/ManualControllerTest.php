<?php

namespace Tests\Feature;

use App\Http\Controllers\ManualController;
use App\Http\Middleware\ErpAuthorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManualControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_has_four_pillars_and_complete_workflows(): void
    {
        $view = (new ManualController)->index();
        $pillars = $view->getData()['pillars'];
        $workflows = $view->getData()['workflows'];
        $testSuites = $view->getData()['testSuites'];

        $this->assertSame(['man', 'money', 'material', 'management'], array_column($pillars, 'key'));
        $this->assertCount(9, $workflows);
        $this->assertCount(13, $testSuites);
        $this->assertGreaterThanOrEqual(70, collect($testSuites)->sum(fn (array $suite) => count($suite['cases'])));
        $this->assertGreaterThanOrEqual(8, count($view->getData()['gaps']));
        $this->assertCount(5, $view->getData()['controlManuals']);
        $this->assertGreaterThanOrEqual(25, count($view->getData()['thaiErpStandards']));
        $this->assertGreaterThanOrEqual(7, count($view->getData()['thaiErpSources']));
        $this->assertCount(8, $view->getData()['calculationFormulas']);
    }

    public function test_every_program_and_workflow_step_points_to_a_real_route(): void
    {
        $data = (new ManualController)->index()->getData();
        $routeNames = [];

        foreach ($data['pillars'] as $pillar) {
            array_push($routeNames, ...array_column($pillar['programs'], 1));
        }

        foreach ($data['workflows'] as $workflow) {
            array_push($routeNames, ...array_column($workflow['steps'], 1));
        }

        foreach ($data['testSuites'] as $suite) {
            array_push($routeNames, ...array_column($suite['cases'], 3));
        }

        foreach ($data['thaiErpStandards'] as $standard) {
            if ($standard['route']) {
                $routeNames[] = $standard['route'];
            }
        }

        foreach (array_unique($routeNames) as $routeName) {
            $this->assertTrue(Route::has($routeName), "Manual route [{$routeName}] does not exist.");
        }
    }

    public function test_uat_cases_have_unique_ids_complete_instructions_and_critical_coverage(): void
    {
        $testSuites = (new ManualController)->index()->getData()['testSuites'];
        $cases = collect($testSuites)->flatMap(fn (array $suite) => $suite['cases']);
        $ids = $cases->pluck(0);

        $this->assertCount($ids->count(), $ids->unique());
        $this->assertGreaterThanOrEqual(40, $cases->where(1, 'critical')->count());

        foreach ($cases as $case) {
            $this->assertCount(8, $case);
            $this->assertMatchesRegularExpression('/^[A-Z]{2,3}-\d{2}$/', $case[0]);
            $this->assertContains($case[1], ['critical', 'control']);
            $this->assertNotSame('', trim($case[2]));
            $this->assertNotSame('', trim($case[4]));
            $this->assertGreaterThanOrEqual(3, count($case[5]));
            $this->assertNotSame('', trim($case[6]));
            $this->assertNotSame('', trim($case[7]));
        }
    }

    public function test_uat_handbook_renders_complete_checklist_controls(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)
            ->get('/core-modules')
            ->assertOk()
            ->assertSee('คู่มือทดสอบรับมอบระบบ (UAT)')
            ->assertSee('75 กรณี')
            ->assertSee('POS-11')
            ->assertSee('PUR-07')
            ->assertSee('OPS-04')
            ->assertSee('ผลทดสอบผ่าน')
            ->assertSee('ผลทดสอบไม่ผ่าน')
            ->assertSee('สูตรตัดสต๊อก ต้นทุน และกำไรที่ระบบใช้')
            ->assertSee('ไม่เกิน 0.00001%');
    }

    public function test_guest_is_redirected_from_manual_to_login(): void
    {
        $this->get('/core-modules')->assertRedirect('/login');
    }
}
