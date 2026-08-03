<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class RouteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_literal_route_reference_exists(): void
    {
        $references = [];
        foreach ([resource_path('views'), app_path()] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                preg_match_all(
                    '/\broute\(\s*[\'"]([^\'"]+)[\'"]/',
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );
                foreach ($matches[1] as $routeName) {
                    $references[$routeName] = $file->getPathname();
                }
            }
        }

        $this->assertGreaterThanOrEqual(250, count($references));
        foreach ($references as $routeName => $file) {
            $this->assertTrue(Route::has($routeName), "Route [{$routeName}] referenced by [{$file}] does not exist.");
        }
    }

    public function test_every_static_get_page_avoids_server_errors(): void
    {
        $this->actingAs(User::factory()->create(['username' => 'route_smoke_admin']));
        $this->withoutMiddleware(ErpAuthorize::class);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
            ->reject(fn (RoutingRoute $route) => str_contains($route->uri(), '{'))
            ->reject(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'download/'))
            ->values();

        $this->assertGreaterThanOrEqual(80, $routes->count());
        foreach ($routes as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            $response = $this->get($uri);

            $this->assertLessThan(
                500,
                $response->getStatusCode(),
                "GET [{$uri}] ({$route->getName()}) returned a server error.",
            );
        }
    }

    public function test_every_literal_blade_form_uses_a_supported_http_method(): void
    {
        $checked = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all(
                '/<form\b([^>]*)>(.*?)<\/form>/si',
                (string) file_get_contents($file->getPathname()),
                $forms,
                PREG_SET_ORDER,
            );
            foreach ($forms as $form) {
                if (! preg_match('/action\s*=\s*[\'"][^\'"]*route\(\s*[\'"]([^\'"]+)[\'"]/si', $form[1], $routeMatch)) {
                    continue;
                }

                $routeName = $routeMatch[1];
                $method = preg_match('/method\s*=\s*[\'"]get[\'"]/i', $form[1]) ? 'GET' : 'POST';
                if (preg_match('/@method\(\s*[\'"](PUT|PATCH|DELETE)[\'"]\s*\)/i', $form[2], $methodMatch)) {
                    $method = strtoupper($methodMatch[1]);
                }

                $route = Route::getRoutes()->getByName($routeName);
                $this->assertNotNull($route, "Form route [{$routeName}] in [{$file->getPathname()}] does not exist.");
                $this->assertContains(
                    $method,
                    $route->methods(),
                    "Form route [{$routeName}] in [{$file->getPathname()}] submits {$method}, but route accepts ".implode('|', $route->methods()).'.',
                );
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(139, $checked);
    }
}
