<?php

namespace App\Console\Commands;

use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Services\Mssql\InteractsWithMssql;
use Illuminate\Console\Command;

/**
 * Sync real BPlus selling prices from MSSQL into PostgreSQL.
 *
 * Source: ARPLU (price rows per price table x sales code) joined to
 * GOODSMASTER (barcode + unit) and SKUMASTER (product code). SKU_PRICE /
 * GOODS_PRICE are NOT usable (SKU_PRICE is 1.00 everywhere in this DB) -
 * the real ราคาขายปลีกผ่านเครื่อง POS lives in ARPLU table 0.
 *
 * Targets:
 *  - product_prices (product_id, price_table_id, unit_id) <- ARPLU_U_PRC
 *  - product_barcodes.price <- table-0 price for that exact barcode (POS
 *    scan-price logic reads this first)
 */
class BplusPriceSync extends Command
{
    use InteractsWithMssql;

    protected $signature = 'bplus:sync-prices {--dry-run : แสดงผลอย่างเดียว ไม่เขียนลงฐานข้อมูล}';

    protected $description = 'Sync ราคาขายจริงจาก BPlus MSSQL (ARPRICETAB/ARPLU) เข้า price_tables/product_prices';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // ขั้นแรก: เติมนิยามตารางราคาที่ยังไม่มีใน PG จาก ARPRICETAB
        $tabRows = $this->fetchAll('SELECT ARPRB_KEY, ARPRB_NAME, ARPRB_ENABLE FROM ARPRICETAB');
        $createdTables = 0;
        foreach ($tabRows as $tab) {
            $code = (string) $tab['ARPRB_KEY'];
            if (! PriceTable::where('code', $code)->exists()) {
                if (! $dry) {
                    PriceTable::create([
                        'code' => $code,
                        'name' => trim((string) $tab['ARPRB_NAME']) ?: ('ตารางราคา BPlus '.$code),
                        'is_default' => false,
                        'is_active' => ($tab['ARPRB_ENABLE'] ?? 'Y') === 'Y',
                    ]);
                }
                $createdTables++;
            }
        }
        $this->info('ตารางราคาใหม่จาก ARPRICETAB: '.$createdTables.($dry ? ' (dry-run)' : ''));

        $rows = $this->fetchAll(
            'SELECT p.ARPLU_ARPRB, p.ARPLU_U_PRC, g.GOODS_CODE, s.SKU_CODE
             FROM ARPLU p
             JOIN GOODSMASTER g ON g.GOODS_KEY = p.ARPLU_GOODS
             JOIN SKUMASTER s ON s.SKU_KEY = g.GOODS_SKU'
        );
        $this->info('อ่านราคาจาก MSSQL: '.count($rows).' แถว');

        // PG lookups (โหลดครั้งเดียว)
        $tableIdByCode = PriceTable::pluck('id', 'code');
        $barcodeRows = ProductBarcode::get(['barcode', 'product_id', 'unit_id', 'id'])->keyBy('barcode');
        $productIdBySku = Product::pluck('id', 'sku_code');

        $synced = 0;
        $barcodePriceUpdated = 0;
        $skippedNoTable = 0;
        $skippedNoProduct = 0;

        foreach ($rows as $row) {
            $tableId = $tableIdByCode[(string) $row['ARPLU_ARPRB']] ?? null;
            if (! $tableId) {
                $skippedNoTable++;

                continue;
            }

            $price = (float) $row['ARPLU_U_PRC'];
            $goodsCode = trim((string) $row['GOODS_CODE']);
            $barcode = $barcodeRows[$goodsCode] ?? null;

            $productId = $barcode->product_id ?? ($productIdBySku[trim((string) $row['SKU_CODE'])] ?? null);
            if (! $productId) {
                $skippedNoProduct++;

                continue;
            }

            if (! $dry) {
                ProductPrice::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'price_table_id' => $tableId,
                        'unit_id' => $barcode->unit_id ?? null,
                    ],
                    ['price' => $price, 'is_active' => true]
                );

                // ราคาระดับบาร์โค้ด: ใช้ตาราง 0 (ราคาขายปลีกผ่านเครื่อง POS)
                if ((string) $row['ARPLU_ARPRB'] === '0' && $barcode && $price > 0) {
                    ProductBarcode::whereKey($barcode->id)->update(['price' => $price]);
                    $barcodePriceUpdated++;
                }
            }
            $synced++;
        }

        $this->table(['ผลลัพธ์', 'จำนวน'], [
            ['sync ราคาเข้า product_prices'.($dry ? ' (dry-run)' : ''), number_format($synced)],
            ['อัปเดตราคาบนบาร์โค้ด (ตาราง 0)', number_format($barcodePriceUpdated)],
            ['ข้าม - ไม่พบตารางราคาใน PG', number_format($skippedNoTable)],
            ['ข้าม - ไม่พบสินค้า/บาร์โค้ดใน PG', number_format($skippedNoProduct)],
        ]);

        if (! $dry) {
            $this->info('product_prices ทั้งหมดตอนนี้: '.number_format(ProductPrice::count()).' แถว');
        }

        return self::SUCCESS;
    }
}
