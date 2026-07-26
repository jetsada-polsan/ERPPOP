<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('default_price', 20, 8)->nullable()->change();
            $table->decimal('average_cost', 20, 8)->default(0)->change();
            $table->decimal('last_purchase_cost', 20, 8)->default(0)->change();
            $table->decimal('reorder_point', 20, 8)->nullable()->change();
            $table->decimal('minimum_stock', 20, 8)->nullable()->change();
            $table->decimal('maximum_stock', 20, 8)->nullable()->change();
        });
        Schema::table('product_prices', function (Blueprint $table) {
            $table->decimal('price', 20, 8)->default(0)->change();
            $table->decimal('cost_price', 20, 8)->default(0)->change();
        });
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->decimal('unit_factor', 20, 8)->default(1)->change();
            $table->decimal('price', 20, 8)->nullable()->change();
        });
        Schema::table('product_units', fn (Blueprint $table) => $table->decimal('qty_per_base_unit', 20, 8)->default(1)->change());
        Schema::table('product_prices', fn (Blueprint $table) => $table->decimal('min_qty', 20, 8)->default(1)->change());
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->decimal('on_hand_qty', 20, 8)->default(0)->change();
            $table->decimal('reserved_qty', 20, 8)->default(0)->change();
        });
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->decimal('initial_qty', 20, 8)->change();
            $table->decimal('remaining_qty', 20, 8)->change();
            $table->decimal('unit_cost', 20, 8)->default(0)->change();
        });
        Schema::table('stock_movements', fn (Blueprint $table) => $table->decimal('qty', 20, 8)->change());
        Schema::table('stock_documents', fn (Blueprint $table) => $table->decimal('total_qty', 20, 8)->default(0)->change());
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->decimal('qty', 20, 8)->change();
            $table->decimal('system_qty', 20, 8)->nullable()->change();
            $table->decimal('counted_qty', 20, 8)->nullable()->change();
            $table->decimal('unit_price', 20, 8)->default(0)->change();
            $table->decimal('unit_cost', 20, 8)->nullable()->change();
            $table->decimal('cost_amount', 20, 8)->nullable()->change();
            $table->decimal('vat_amount', 20, 8)->default(0)->change();
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('total_amount', 20, 8)->default(0)->change();
            $table->decimal('subtotal_amount', 20, 8)->nullable()->change();
            $table->decimal('vat_amount', 20, 8)->default(0)->change();
        });
        Schema::table('inventory_cost_closes', function (Blueprint $table) {
            $table->decimal('opening_qty', 20, 8)->default(0)->change();
            $table->decimal('received_qty', 20, 8)->default(0)->change();
            $table->decimal('issued_qty', 20, 8)->default(0)->change();
            $table->decimal('ending_qty', 20, 8)->default(0)->change();
            $table->decimal('average_cost', 20, 8)->default(0)->change();
            $table->decimal('ending_value', 20, 8)->default(0)->change();
        });
        Schema::table('production_batches', function (Blueprint $table) {
            foreach (['input_weight_qty', 'output_weight_qty', 'loss_weight_qty', 'total_input_cost', 'output_unit_cost', 'selling_unit_price', 'net_selling_unit_price', 'estimated_profit_per_unit'] as $column) {
                $table->decimal($column, 20, 8)->default(0)->change();
            }
            $table->decimal('yield_percent', 13, 8)->default(0)->change();
            $table->decimal('estimated_margin_percent', 13, 8)->default(0)->change();
        });
        Schema::table('production_batch_packages', function (Blueprint $table) {
            foreach (['weight_qty', 'unit_price', 'total_price'] as $column) {
                $table->decimal($column, 20, 8)->change();
            }
        });
        Schema::table('stock_lot_lineages', fn (Blueprint $table) => $table->decimal('input_qty', 20, 8)->change());
        Schema::table('production_recipes', fn (Blueprint $table) => $table->decimal('output_qty', 20, 8)->default(1)->change());
        Schema::table('production_recipe_items', fn (Blueprint $table) => $table->decimal('qty', 20, 8)->change());
        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('planned_qty', 20, 8)->change();
            $table->decimal('produced_qty', 20, 8)->default(0)->change();
        });
        Schema::table('production_order_items', function (Blueprint $table) {
            $table->decimal('planned_qty', 20, 8)->change();
            $table->decimal('used_qty', 20, 8)->default(0)->change();
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('qty', 20, 8)->change();
            $table->decimal('received_qty', 20, 8)->default(0)->change();
            $table->decimal('unit_price', 20, 8)->default(0)->change();
        });
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->decimal('qty', 20, 8)->change();
            $table->decimal('unit_price', 20, 8)->change();
        });
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->decimal('system_qty', 20, 8)->default(0)->change();
            $table->decimal('counted_qty', 20, 8)->nullable()->change();
        });
        Schema::table('pos_receipt_items', function (Blueprint $table) {
            $table->decimal('qty', 20, 8)->change();
            $table->decimal('unit_price', 20, 8)->change();
        });
        Schema::table('pos_receipt_return_items', function (Blueprint $table) {
            $table->decimal('qty', 20, 8)->change();
            $table->decimal('unit_price', 20, 8)->change();
        });
        Schema::table('purchase_plans', function (Blueprint $table) {
            $table->decimal('suggested_qty', 20, 8)->default(0)->change();
            $table->decimal('target_stock_qty', 20, 8)->default(0)->change();
        });
        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->decimal('last_purchase_price', 20, 8)->nullable()->change();
            $table->decimal('minimum_order_qty', 20, 8)->nullable()->change();
        });
        Schema::table('promotions', fn (Blueprint $table) => $table->decimal('min_qty', 20, 8)->nullable()->change());
        Schema::table('qty_promotions', function (Blueprint $table) {
            $table->decimal('min_qty', 20, 8)->default(1)->change();
            $table->decimal('free_qty', 20, 8)->nullable()->change();
        });
        Schema::table('recall_contacts', fn (Blueprint $table) => $table->decimal('qty', 20, 8)->default(0)->change());
        Schema::table('imported_receipt_items', function (Blueprint $table) {
            $table->decimal('qty', 20, 8)->change();
            $table->decimal('unit_price', 20, 8)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('default_price', 18, 4)->nullable()->change();
            $table->decimal('average_cost', 18, 4)->default(0)->change();
            $table->decimal('last_purchase_cost', 18, 4)->default(0)->change();
            $table->decimal('reorder_point', 18, 4)->nullable()->change();
            $table->decimal('minimum_stock', 18, 4)->nullable()->change();
            $table->decimal('maximum_stock', 18, 4)->nullable()->change();
        });
        Schema::table('product_prices', function (Blueprint $table) {
            $table->decimal('price', 18, 4)->default(0)->change();
            $table->decimal('cost_price', 18, 4)->default(0)->change();
        });
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->decimal('unit_factor', 18, 4)->default(1)->change();
            $table->decimal('price', 18, 4)->nullable()->change();
        });
        Schema::table('product_units', fn (Blueprint $table) => $table->decimal('qty_per_base_unit', 18, 4)->default(1)->change());
        Schema::table('product_prices', fn (Blueprint $table) => $table->decimal('min_qty', 18, 4)->default(1)->change());
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->decimal('on_hand_qty', 18, 4)->default(0)->change();
            $table->decimal('reserved_qty', 18, 4)->default(0)->change();
        });
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->decimal('initial_qty', 18, 4)->change();
            $table->decimal('remaining_qty', 18, 4)->change();
            $table->decimal('unit_cost', 18, 4)->default(0)->change();
        });
        Schema::table('stock_movements', fn (Blueprint $table) => $table->decimal('qty', 18, 4)->change());
        Schema::table('stock_documents', fn (Blueprint $table) => $table->decimal('total_qty', 18, 4)->default(0)->change());
        Schema::table('stock_document_items', function (Blueprint $table) {
            foreach (['qty', 'system_qty', 'counted_qty', 'unit_price', 'unit_cost', 'cost_amount', 'vat_amount'] as $column) {
                $table->decimal($column, 18, 4)->nullable()->change();
            }
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('total_amount', 18, 4)->default(0)->change();
            $table->decimal('subtotal_amount', 18, 4)->nullable()->change();
            $table->decimal('vat_amount', 18, 4)->default(0)->change();
        });
        Schema::table('inventory_cost_closes', function (Blueprint $table) {
            foreach (['opening_qty', 'received_qty', 'issued_qty', 'ending_qty', 'average_cost', 'ending_value'] as $column) {
                $table->decimal($column, 18, 4)->default(0)->change();
            }
        });
        Schema::table('production_batches', function (Blueprint $table) {
            foreach (['input_weight_qty', 'output_weight_qty', 'loss_weight_qty', 'total_input_cost', 'output_unit_cost', 'selling_unit_price', 'net_selling_unit_price', 'estimated_profit_per_unit'] as $column) {
                $table->decimal($column, 18, 4)->default(0)->change();
            }
            $table->decimal('yield_percent', 9, 4)->default(0)->change();
            $table->decimal('estimated_margin_percent', 9, 4)->default(0)->change();
        });
        Schema::table('production_batch_packages', function (Blueprint $table) {
            foreach (['weight_qty', 'unit_price', 'total_price'] as $column) {
                $table->decimal($column, 18, 4)->change();
            }
        });
        Schema::table('stock_lot_lineages', fn (Blueprint $table) => $table->decimal('input_qty', 18, 4)->change());
        Schema::table('production_recipes', fn (Blueprint $table) => $table->decimal('output_qty', 18, 4)->default(1)->change());
        Schema::table('production_recipe_items', fn (Blueprint $table) => $table->decimal('qty', 18, 4)->change());
        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('planned_qty', 18, 4)->change();
            $table->decimal('produced_qty', 18, 4)->default(0)->change();
        });
        Schema::table('production_order_items', function (Blueprint $table) {
            $table->decimal('planned_qty', 18, 4)->change();
            $table->decimal('used_qty', 18, 4)->default(0)->change();
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->decimal('received_qty', 18, 4)->default(0)->change();
            $table->decimal('unit_price', 18, 4)->default(0)->change();
        });
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->decimal('unit_price', 18, 4)->change();
        });
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->decimal('system_qty', 18, 4)->default(0)->change();
            $table->decimal('counted_qty', 18, 4)->nullable()->change();
        });
        Schema::table('pos_receipt_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->decimal('unit_price', 18, 4)->change();
        });
        Schema::table('pos_receipt_return_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->decimal('unit_price', 18, 4)->change();
        });
        Schema::table('purchase_plans', function (Blueprint $table) {
            $table->decimal('suggested_qty', 18, 4)->default(0)->change();
            $table->decimal('target_stock_qty', 18, 4)->default(0)->change();
        });
        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->decimal('last_purchase_price', 18, 4)->nullable()->change();
            $table->decimal('minimum_order_qty', 18, 4)->nullable()->change();
        });
        Schema::table('promotions', fn (Blueprint $table) => $table->decimal('min_qty', 18, 4)->nullable()->change());
        Schema::table('qty_promotions', function (Blueprint $table) {
            $table->decimal('min_qty', 18, 4)->default(1)->change();
            $table->decimal('free_qty', 18, 4)->nullable()->change();
        });
        Schema::table('recall_contacts', fn (Blueprint $table) => $table->decimal('qty', 18, 4)->default(0)->change());
        Schema::table('imported_receipt_items', function (Blueprint $table) {
            $table->decimal('qty', 18, 4)->change();
            $table->decimal('unit_price', 18, 4)->change();
        });
    }
};
