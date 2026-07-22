<?php

namespace Tests\Feature;

use App\Http\Controllers\ManualController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManualControllerTest extends TestCase
{
    public function test_manual_has_four_pillars_and_complete_workflows(): void
    {
        $view = (new ManualController)->index();
        $pillars = $view->getData()['pillars'];
        $workflows = $view->getData()['workflows'];

        $this->assertSame(['man', 'money', 'material', 'management'], array_column($pillars, 'key'));
        $this->assertCount(8, $workflows);
        $this->assertGreaterThanOrEqual(8, count($view->getData()['gaps']));
        $this->assertCount(5, $view->getData()['controlManuals']);
        $this->assertGreaterThanOrEqual(25, count($view->getData()['thaiErpStandards']));
        $this->assertGreaterThanOrEqual(7, count($view->getData()['thaiErpSources']));
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

        foreach ($data['thaiErpStandards'] as $standard) {
            if ($standard['route']) {
                $routeNames[] = $standard['route'];
            }
        }

        foreach (array_unique($routeNames) as $routeName) {
            $this->assertTrue(Route::has($routeName), "Manual route [{$routeName}] does not exist.");
        }
    }

    public function test_guest_is_redirected_from_manual_to_login(): void
    {
        $this->get('/core-modules')->assertRedirect('/login');
    }
}
