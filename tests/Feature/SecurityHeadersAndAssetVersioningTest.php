<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Support\AssetVersion;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class SecurityHeadersAndAssetVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_includes_security_headers_without_hsts_over_http(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Permissions-Policy');
    }

    public function test_login_page_includes_hsts_without_include_subdomains_over_https(): void
    {
        $response = $this->get('https://localhost/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Permissions-Policy');

        $this->assertStringNotContainsString(
            'includeSubDomains',
            (string) $response->headers->get('Strict-Transport-Security')
        );
    }

    public function test_security_headers_middleware_does_not_overwrite_existing_response_headers(): void
    {
        $request = Request::create('https://localhost/custom', 'GET');
        $middleware = new SecurityHeaders;

        $response = $middleware->handle($request, function (): Response {
            return response('ok', 200, [
                'X-Content-Type-Options' => 'custom-nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'no-referrer',
                'Strict-Transport-Security' => 'max-age=60',
            ]);
        });

        $this->assertSame('custom-nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('max-age=60', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_asset_version_helper_appends_filemtime_and_does_not_throw_for_missing_files(): void
    {
        $expectedMtime = filemtime(public_path('css/erp-layout.css'));
        $this->assertIsInt($expectedMtime);

        $this->assertSame(
            asset('css/erp-layout.css').'?v='.$expectedMtime,
            AssetVersion::url('css/erp-layout.css')
        );
        $this->assertSame(
            asset('css/erp-layout.css').'?v='.$expectedMtime,
            AssetVersion::url('/css/erp-layout.css')
        );
        $this->assertSame(
            asset('vendor/nonexistent-asset-check.js'),
            AssetVersion::url('vendor/nonexistent-asset-check.js')
        );
    }

    public function test_login_and_authenticated_pages_version_all_same_origin_stylesheets_and_scripts(): void
    {
        $loginResponse = $this->get('/login')->assertOk();
        $loginAssets = $this->extractSameOriginAssetUrls((string) $loginResponse->getContent());

        $this->assertNotEmpty($loginAssets, 'หน้า login ต้องมี stylesheet หรือ script ในโดเมนตัวเองอย่างน้อย 1 ไฟล์');
        foreach ($loginAssets as $url) {
            $this->assertMatchesRegularExpression('/[?&]v=\d+/', $url, "หน้า login มี asset ที่ไม่มี ?v=: {$url}");
        }

        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $dashboardResponse = $this->actingAs($user)->get('/dashboard')->assertOk();
        $dashboardAssets = $this->extractSameOriginAssetUrls((string) $dashboardResponse->getContent());

        $this->assertGreaterThanOrEqual(8, count($dashboardAssets), 'หน้า dashboard ที่ login แล้วต้องโหลด CSS/JS ในโดเมนตัวเองหลายไฟล์');
        foreach ($dashboardAssets as $url) {
            $this->assertMatchesRegularExpression('/[?&]v=\d+/', $url, "หน้า dashboard มี asset ที่ไม่มี ?v=: {$url}");
        }
    }

    public function test_non_pos_blade_views_use_asset_version_helper_instead_of_unversioned_css_js_or_raw_filemtime(): void
    {
        $viewsRoot = str_replace('\\', '/', resource_path('views'));
        $basePrefix = str_replace('\\', '/', base_path()).'/';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $normalized = str_replace('\\', '/', $file->getPathname());
            $relative = str_replace($basePrefix, '', $normalized);
            if (str_starts_with($relative, 'resources/views/pos/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/filemtime\s*\(\s*public_path\s*\(/', $contents) === 1) {
                $violations[] = "{$relative} still calls raw filemtime(public_path(...))";
            }
            if (preg_match('/asset\s*\(\s*[\'"][^\'"]+\.(?:css|js)[\'"]\s*\)/i', $contents) === 1) {
                $violations[] = "{$relative} calls unversioned asset() for CSS/JS";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return list<string>
     */
    private function extractSameOriginAssetUrls(string $html): array
    {
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $urls = [];

        foreach ($dom->getElementsByTagName('link') as $link) {
            $rel = strtolower(trim($link->getAttribute('rel')));
            $href = trim($link->getAttribute('href'));
            if ($rel === 'stylesheet' && $href !== '' && $this->isSameOriginUrl($href, $appHost)) {
                $urls[] = $href;
            }
        }

        foreach ($dom->getElementsByTagName('script') as $script) {
            $src = trim($script->getAttribute('src'));
            if ($src !== '' && $this->isSameOriginUrl($src, $appHost)) {
                $urls[] = $src;
            }
        }

        return $urls;
    }

    private function isSameOriginUrl(string $url, string $appHost): bool
    {
        if (str_starts_with($url, '//')) {
            $host = strtolower((string) parse_url('http:'.$url, PHP_URL_HOST));

            return $host === $appHost || $host === 'localhost';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null) {
            return true;
        }

        $normalizedHost = strtolower($host);

        return $normalizedHost === $appHost || $normalizedHost === 'localhost';
    }
}
