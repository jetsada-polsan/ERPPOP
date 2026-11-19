<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckMenuVisibility;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\ErpMenu;
use App\Support\MenuVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use ZipArchive;

class AccountingPackageMenusTest extends TestCase
{
    use RefreshDatabase;

    // Session identity exists in memory only. Permissions come from the migrated role.
    private function signIn(string $role, int $branch = 1): void
    {
        $record = Role::with('permissions')->where('code', $role)->firstOrFail();
        $user = new class extends User
        {
            protected $table = 'users';

            public function getForeignKey()
            {
                return 'user_id';
            }

            public function hasGlobalPermission(string $code): bool
            {
                return in_array($code, $this->permissionCodes(), true);
            }

            public function permissionCodes(): array
            {
                return $this->roles->first()->permissions->pluck('code')->all();
            }
        };
        $user->forceFill(['id' => 90001, 'name' => 'ผู้ตรวจรายงาน', 'is_active' => true, 'branch_id' => $branch]);
        $user->setRelation('roles', collect([$record]));
        $this->actingAs($user);
        $this->withoutVite();
    }

    public function test_firm_access_and_export_permission(): void
    {
        $this->signIn('ACCOUNTING_FIRM');
        $this->get('/reports')->assertOk();
        $this->get('/accounting-package')->assertOk();
        foreach (['/settings', '/system/status', '/pos-admin', '/settings/menus'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/bplus/sync/import')->assertForbidden();
        $this->signIn('HR');
        $this->get('/accounting-package')->assertForbidden();
        $this->get('/accounting-package/download')->assertForbidden();
    }

    private function document(int $branch, int $area, string $type, string $number, string $date, array $extra = []): int
    {
        DB::table('document_types')->insertOrIgnore(['code' => $type, 'name_th' => $type]);

        return DB::table('documents')->insertGetId($extra + [
            'document_type_id' => DB::table('document_types')->where('code', $type)->value('id'),
            'branch_id' => $branch, 'sales_area_id' => $area, 'source_system' => 'bplus', 'source_key' => $number,
            'doc_number' => $number, 'doc_date' => $date, 'total_amount' => 100, 'status' => 'active',
        ]);
    }

    public function test_monthly_zip_contains_all_rows_only_from_requested_area_and_closes_ar_on_month_end(): void
    {
        $branch = DB::table('branches')->insertGetId(['code' => 'TEST', 'name_th' => 'สาขาทดสอบ']);
        $this->signIn('ACCOUNTING_FIRM', $branch);
        foreach (['B18', 'B12'] as $code) {
            DB::table('sales_areas')->updateOrInsert(['code' => $code], ['name' => $code, 'branch_id' => $branch, 'is_active' => true]);
        }
        $area = DB::table('sales_areas')->where('code', 'B18')->value('id');
        $other = DB::table('sales_areas')->where('code', 'B12')->value('id');
        DB::table('sales_areas')->whereIn('id', [$area, $other])->update(['branch_id' => $branch]);
        $customer = DB::table('customers')->insertGetId(['code' => 'cust', 'name_th' => 'ลูกค้า']);
        for ($i = 1; $i <= 30; $i++) {
            $this->document($branch, $area, 'CREDIT_NOTE', 'CN-IN-'.$i, '2026-12-02');
        }
        $this->document($branch, $other, 'CREDIT_NOTE', 'CN-OTHER', '2026-12-02');
        $this->document($branch, $area, 'CREDIT_NOTE', 'CN-JAN', '2027-01-01');
        $invoice = $this->document($branch, $area, 'OPENING_AR', 'INV-OLD', '2026-11-01', ['customer_id' => $customer]);
        $item = DB::table('customer_open_items')->insertGetId(['document_id' => $invoice, 'customer_id' => $customer, 'gross_amount' => 100, 'balance_amount' => 0, 'status' => 'paid', 'due_date' => '2026-11-29']);
        foreach (['2026-12-19' => 30, '2027-01-03' => 70] as $date => $paid) {
            $receipt = $this->document($branch, $area, 'RECEIPT', 'RR-'.$date, $date, ['customer_id' => $customer, 'total_amount' => $paid]);
            $payment = DB::table('payment_documents')->insertGetId(['document_id' => $receipt, 'party_type' => 'customer', 'customer_id' => $customer]);
            DB::table('payment_lines')->insert(['payment_document_id' => $payment, 'seq' => 1, 'amount' => $paid, 'method' => 'cash']);
            DB::table('payment_allocations')->insert(['payment_document_id' => $payment, 'customer_open_item_id' => $item, 'allocated_amount' => $paid]);
        }
        $response = $this->get('/accounting-package/download?month=2026-12&sales_area_id='.$area.'&per_page=1&page=9')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $this->assertSame(8, $zip->numFiles);
            $summary = $zip->getFromName('00-สรุป.txt');
            $this->assertStringContainsString('รับชำระ 1 ใบ 30.00', $summary);
            $this->assertStringContainsString('ลดหนี้/รับคืน 30 ใบ -3000.00', $summary);
            $this->assertStringContainsString('ลูกหนี้คงค้าง 1 ใบ 70.00', $summary);
            for ($i = 0; $i < 7; $i++) {
                $tmp = tempnam(sys_get_temp_dir(), 'check-sheet-');
                file_put_contents($tmp, $zip->getFromIndex($i));
                $sheet = new ZipArchive;
                $this->assertTrue($sheet->open($tmp));
                $xml = $sheet->getFromName('xl/worksheets/sheet1.xml');
                $this->assertStringNotContainsString('CN-OTHER', $xml);
                $this->assertStringNotContainsString('CN-JAN', $xml);
                if ($i === 2) {
                    $this->assertStringContainsString('CN-IN-30', $xml);
                    $this->assertStringContainsString('CN-IN-1<', $xml);
                }
                if ($i === 4) {
                    $this->assertStringContainsString('31–60 วัน', $xml);
                }
                $sheet->close();
                unlink($tmp);
            }
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_hidden_menu_blocks_direct_access_but_admin_can_inspect_and_restore(): void
    {
        $this->signIn('IT_MGR');
        $response = $this->get('/settings/menus')->assertOk()->assertSee('เปิดทั้งหมด');
        $sections = ErpMenu::all();
        $keys = collect($sections)->pluck('items')->flatten(1)->map(fn ($item) => MenuVisibility::key($item));
        $this->post('/settings/menus', ['action' => 'save', 'sections' => array_column($sections, 'label'),
            'enabled' => $keys->diff(['bplus.sync.index', 'settings.menus', 'settings.index', 'system.status', 'dashboard'])->values()->all()])->assertRedirect();
        $this->assertSame(['bplus.sync.index'], MenuVisibility::hidden());
        $this->signIn('HR');
        $this->get('/bplus/sync')->assertNotFound();
        $this->get('/apps')->assertOk()->assertDontSee('href="'.route('bplus.sync.index').'"', false);
        $this->signIn('IT_MGR');
        $this->get('/bplus/sync')->assertOk()->assertSee('เมนูนี้ปิดอยู่');
        $this->post('/settings/menus', ['action' => 'restore'])->assertRedirect();
        $this->signIn('HR');
        $this->get('/bplus/sync')->assertOk();
    }

    public function test_sync_api_keeps_its_own_auth_when_web_menu_is_closed(): void
    {
        AppSetting::set('hidden_menus', json_encode(['bplus.sync.index', 'pos.control']));
        $this->postJson('/api/bplus/sync')->assertUnauthorized();
        $router = app('router');
        $api = Route::getRoutes()->getByName('api.bplus.sync');
        $this->assertNotContains(CheckMenuVisibility::class, $router->gatherRouteMiddleware($api));
        DB::table('bplus_sync_tokens')->insert(['name' => 'fixture', 'route_code' => 'B18', 'token_hash' => hash('sha256','fixture')]);
        $this->withToken('fixture')->getJson('/api/bplus/sync/ping')->assertOk()->assertJsonPath('ok',true);
    }
}
