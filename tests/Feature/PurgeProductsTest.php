<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ลบสินค้าทั้งหมดก่อนนำเข้าชุดใหม่ — ต้องล้างธุรกรรมก่อน มี backup สด และลบรวมตัวที่ซ่อนไว้
 */
class PurgeProductsTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDirectory = storage_path('app/backups');
        File::ensureDirectoryExists($this->backupDirectory);
        File::cleanDirectory($this->backupDirectory);
    }

    protected function tearDown(): void
    {
        File::cleanDirectory($this->backupDirectory);
        parent::tearDown();
    }

    public function test_it_deletes_products_with_barcodes_including_hidden_ones_but_keeps_units(): void
    {
        $this->freshBackup();
        [$unit, $product] = $this->seedCatalog();
        $hidden = Product::create($this->productData('OLD-2', $unit));
        $hidden->delete();

        $this->artisan('erp:purge-products', ['--confirm-database' => $this->database(), '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('product_barcodes')->count());
        $this->assertSame(0, DB::table('stock_balances')->count());
        $this->assertTrue(ProductUnit::whereKey($unit->id)->exists());
        // รหัสเดิมว่างแล้ว นำเข้าซ้ำได้
        Product::create($this->productData('OLD-1', $unit));
        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->seedCatalog();

        $this->artisan('erp:purge-products', ['--confirm-database' => $this->database(), '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(1, DB::table('product_barcodes')->count());
    }

    public function test_it_refuses_while_transactions_still_reference_products(): void
    {
        $this->freshBackup();
        [, $product, $location] = $this->seedCatalog();
        DB::table('stock_movements')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'movement_type' => 'in', 'qty' => 1, 'movement_date' => now()->toDateString(),
        ]);

        $this->artisan('erp:purge-products', ['--confirm-database' => $this->database(), '--force' => true])
            ->expectsOutputToContain('erp:reset-transactions')
            ->assertFailed();

        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_it_refuses_without_a_fresh_backup_or_the_right_database_name(): void
    {
        $this->seedCatalog();

        $this->artisan('erp:purge-products', ['--confirm-database' => 'wrong', '--force' => true])->assertFailed();
        $this->artisan('erp:purge-products', ['--confirm-database' => $this->database(), '--force' => true])->assertFailed();

        $this->assertSame(1, DB::table('products')->count());
    }

    /** @return array{0:ProductUnit, 1:Product, 2:WarehouseLocation} */
    private function seedCatalog(): array
    {
        $branch = Branch::create(['code' => 'PG', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-PG', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $unit = ProductUnit::create(['code' => 'EA-PG', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create($this->productData('OLD-1', $unit));
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'barcode' => '8850000000001', 'unit_id' => $unit->id]);
        DB::table('stock_balances')->insert(['product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => 0, 'reserved_qty' => 0]);

        return [$unit, $product, $location];
    }

    private function productData(string $sku, ProductUnit $unit): array
    {
        return [
            'sku_code' => $sku, 'name_th' => 'สินค้าเก่า '.$sku, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
        ];
    }

    private function database(): string
    {
        return DB::connection()->getDatabaseName();
    }

    private function freshBackup(): void
    {
        $path = $this->backupDirectory.'/erp-db-'.now()->format('Ymd-His').'.sql.gz';
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
