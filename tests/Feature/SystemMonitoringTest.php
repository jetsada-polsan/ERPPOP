<?php

namespace Tests\Feature;

use App\Http\Middleware\RecordResponseMetrics;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\PosOfflineSale;
use App\Models\Role;
use App\Models\SystemError;
use App\Models\User;
use App\Services\Monitoring\SystemErrorRecorder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SystemMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        putenv('ERP_ALERT_EMAIL');
        unset($_ENV['ERP_ALERT_EMAIL']);
    }

    private function createSystemAdminUser(): User
    {
        $role = Role::create(['code' => 'SYS_ADMIN', 'name' => 'System Admin']);
        $p1 = Permission::firstOrCreate(['code' => 'users.manage'], ['name' => 'Users']);
        $p2 = Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'Settings']);
        $role->permissions()->attach([$p1->id, $p2->id]);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_5xx_error_is_recorded_in_system_errors_table(): void
    {
        $recorder = app(SystemErrorRecorder::class);

        $request = Request::create('/sales/invoices/999', 'GET');
        $exception = new Exception('Database deadlock encountered during invoice generation');

        $error = $recorder->record($exception, $request);

        $this->assertNotNull($error);
        $this->assertDatabaseCount('system_errors', 1);

        $record = SystemError::first();
        $this->assertSame(500, $record->status);
        $this->assertSame('http://localhost/sales/invoices/999', $record->url);
        $this->assertSame('Database deadlock encountered during invoice generation', $record->message);
        $this->assertSame(Exception::class, $record->exception_class);
        $this->assertSame(1, $record->count);
        $this->assertNotNull($record->occurred_at);
        $this->assertNotEmpty($record->fingerprint);
    }

    public function test_recurring_5xx_error_is_deduplicated_by_fingerprint_and_increments_count(): void
    {
        $recorder = app(SystemErrorRecorder::class);
        $request = Request::create('/api/v1/checkout', 'POST');
        $exception = new Exception('Third-party payment gateway timeout');

        $first = $recorder->record($exception, $request);
        $this->assertNotNull($first);
        $this->assertSame(1, $first->count);

        // Record the identical error again
        $second = $recorder->record($exception, $request);
        $this->assertNotNull($second);

        $this->assertDatabaseCount('system_errors', 1);
        $this->assertSame($first->fingerprint, $second->fingerprint);

        $record = SystemError::first();
        $this->assertSame(2, $record->count);
    }

    public function test_sensitive_data_passwords_pins_tokens_are_never_recorded(): void
    {
        $recorder = app(SystemErrorRecorder::class);

        // URL containing secret parameters
        $request = Request::create('/api/checkout?token=secret-token-xyz&pin=1234&password=mypassword&user_id=42', 'GET');
        $exception = new Exception('Failed with password=plain_secret_password and pin: 9999');

        $record = $recorder->record($exception, $request);

        $this->assertNotNull($record);

        // URL must NOT contain raw token, pin, or password
        $this->assertStringNotContainsString('secret-token-xyz', $record->url);
        $this->assertStringNotContainsString('1234', $record->url);
        $this->assertStringNotContainsString('mypassword', $record->url);
        $this->assertStringContainsString('token=%5BFILTERED%5D', $record->url);
        $this->assertStringContainsString('user_id=42', $record->url);

        // Message must NOT leak secrets
        $this->assertStringNotContainsString('plain_secret_password', $record->message);
        $this->assertStringNotContainsString('9999', $record->message);
    }

    public function test_4xx_client_errors_are_not_recorded(): void
    {
        $recorder = app(SystemErrorRecorder::class);
        $request = Request::create('/non-existent-page', 'GET');

        // 404 Not Found
        $notFound = new NotFoundHttpException('Page not found');
        $res1 = $recorder->record($notFound, $request);
        $this->assertNull($res1);

        // 403 Forbidden
        $forbidden = new HttpException(403, 'Permission denied');
        $res2 = $recorder->record($forbidden, $request);
        $this->assertNull($res2);

        // 422 Unprocessable Content
        $unprocessable = new HttpException(422, 'Invalid input data');
        $res3 = $recorder->record($unprocessable, $request);
        $this->assertNull($res3);

        $this->assertDatabaseCount('system_errors', 0);
    }

    public function test_system_health_page_access_control(): void
    {
        // 1. Guest -> redirected to login
        $response = $this->get('/operations/health');
        $response->assertRedirect(route('login'));

        // 2. Normal user without system.admin -> 403 Forbidden
        $normalUser = User::factory()->create(['is_active' => true]);
        $response = $this->actingAs($normalUser)->get('/operations/health');
        $response->assertStatus(403);

        // 3. User with system.admin -> 200 OK
        $admin = $this->createSystemAdminUser();
        $this->withoutVite();
        $response = $this->actingAs($admin)->get('/operations/health');
        $response->assertStatus(200);
        $response->assertSee('ศูนย์ติดตามสุขภาพระบบ');
        $response->assertSee('5xx Errors');
        $response->assertSee('เวลาตอบสนองเฉลี่ย');
        $response->assertSee('บิล POS ที่ ERP ปฏิเสธวันนี้');
    }

    public function test_alert_email_is_sent_when_erp_alert_email_is_configured(): void
    {
        Mail::fake();
        $_ENV['ERP_ALERT_EMAIL'] = 'admin-alerts@mpmfoodgroup.com';
        putenv('ERP_ALERT_EMAIL=admin-alerts@mpmfoodgroup.com');

        $recorder = app(SystemErrorRecorder::class);
        $request = Request::create('/sales/pos', 'POST');
        $exception = new Exception('Critical DB connection loss during sale checkout');

        $recorder->record($exception, $request);

        Mail::assertSentCount(1);
    }

    public function test_alert_email_does_not_fail_or_send_when_erp_alert_email_is_empty(): void
    {
        Mail::fake();
        $_ENV['ERP_ALERT_EMAIL'] = '';
        putenv('ERP_ALERT_EMAIL=');

        $recorder = app(SystemErrorRecorder::class);
        $request = Request::create('/sales/pos', 'POST');
        $exception = new Exception('Another crash without email alert configured');

        $result = $recorder->record($exception, $request);

        $this->assertNotNull($result);
        Mail::assertNothingSent();
    }

    public function test_alert_email_spike_when_errors_exceed_10_in_10_minutes(): void
    {
        Mail::fake();
        $_ENV['ERP_ALERT_EMAIL'] = 'devops@mpmfoodgroup.com';
        putenv('ERP_ALERT_EMAIL=devops@mpmfoodgroup.com');

        $recorder = app(SystemErrorRecorder::class);
        $request = Request::create('/api/ping', 'GET');
        $exception = new Exception('Repeated service outage');

        // First occurrence triggers the new fingerprint alert (1 email)
        $recorder->record($exception, $request);
        Mail::assertSentCount(1);

        // Manually increment count to simulate 11 occurrences in 10 minutes
        SystemError::first()->update(['count' => 11, 'occurred_at' => now()]);

        // The next occurrence should trigger the spike alert (>10 in 10 minutes)
        $recorder->record($exception, $request);
        Mail::assertSentCount(2);

        // Immediate subsequent occurrence should be throttled by cache
        $recorder->record($exception, $request);
        Mail::assertSentCount(2);
    }

    public function test_erp_health_cli_json_option_outputs_valid_json_with_expected_keys(): void
    {
        SystemError::create([
            'occurred_at' => now(),
            'status' => 500,
            'url' => 'http://localhost/test',
            'route_name' => 'test.index',
            'message' => 'Simulated test 500 error',
            'exception_class' => Exception::class,
            'file_line' => 'TestFile.php:42',
            'fingerprint' => 'test_fingerprint_cli_json',
            'count' => 3,
        ]);

        \Illuminate\Support\Facades\Artisan::call('erp:health', ['--json' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $data = json_decode($output, true);
        $this->assertIsArray($data, 'erp:health --json must return valid JSON');
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('db', $data);
        $this->assertArrayHasKey('queue', $data);
        $this->assertArrayHasKey('disk', $data);
        $this->assertArrayHasKey('latest_errors', $data);
        $this->assertCount(1, $data['latest_errors']);
        $this->assertSame('Simulated test 500 error', $data['latest_errors'][0]['message']);
    }

    public function test_resolve_error_endpoint_marks_system_error_resolved(): void
    {
        $admin = $this->createSystemAdminUser();

        $error = SystemError::create([
            'occurred_at' => now(),
            'status' => 500,
            'url' => 'http://localhost/test-resolve',
            'message' => 'Crash to be resolved',
            'exception_class' => Exception::class,
            'file_line' => 'Controller.php:10',
            'fingerprint' => 'test_fingerprint_resolve',
            'count' => 1,
        ]);

        $this->assertNull($error->resolved_at);

        $response = $this->actingAs($admin)->post(route('operations.health.resolve', $error));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $error->refresh();
        $this->assertNotNull($error->resolved_at);
    }

    public function test_pos_rejected_sales_are_displayed_read_only_on_health_dashboard(): void
    {
        $admin = $this->createSystemAdminUser();
        $this->withoutVite();

        $branch = Branch::create(['code' => 'B099', 'name_th' => 'สาขาพระราม 9', 'is_active' => true]);
        $device = PosDevice::create([
            'name' => 'POS เครื่อง 1 พระราม 9',
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'token_hash' => hash('sha256', 'pos-token-123'),
            'platform' => 'desktop',
            'is_active' => true,
        ]);

        PosOfflineSale::create([
            'offline_uuid' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'pos_device_id' => $device->id,
            'offline_total' => 350.00,
            'status' => PosOfflineSale::NEEDS_REVIEW,
            'review_reason' => 'ราคาสินค้าไม่ตรงกับโปรโมชั่นปัจจุบัน',
            'payload' => ['items' => []],
            'sold_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/operations/health');
        $response->assertStatus(200);
        $response->assertSee('1 ใบ');
        $response->assertSee('ราคาสินค้าไม่ตรงกับโปรโมชั่นปัจจุบัน');
        $response->assertSee('350.00 ฿');
    }

    public function test_apm_middleware_samples_response_time(): void
    {
        $middleware = new RecordResponseMetrics();
        $request = Request::create('/sales', 'GET');

        // Test sampling behavior: we run the middleware directly with a next callback
        $response = $middleware->handle($request, function () {
            usleep(2000); // 2ms sleep
            return response('OK', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_unhandled_exception_in_web_request_is_captured_by_exception_handler(): void
    {
        Route::get('/test-500-trigger', function () {
            throw new \RuntimeException('Simulated live web server crash');
        });

        $response = $this->get('/test-500-trigger');
        $response->assertStatus(500);

        $this->assertDatabaseHas('system_errors', [
            'status' => 500,
            'message' => 'Simulated live web server crash',
            'exception_class' => \RuntimeException::class,
        ]);
    }
}
