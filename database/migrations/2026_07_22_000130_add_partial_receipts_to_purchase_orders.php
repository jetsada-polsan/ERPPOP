<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('received_qty', 18, 4)->default(0)->after('qty');
        });

        Schema::create('purchase_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('document_id')->unique()->constrained('documents')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['purchase_order_id', 'received_at']);
        });

        $receivedOrders = DB::table('purchase_orders')->where('status', 'received')->get();
        foreach ($receivedOrders as $order) {
            DB::table('purchase_order_items')->where('purchase_order_id', $order->id)
                ->update(['received_qty' => DB::raw('qty')]);
            if ($order->received_document_id) {
                DB::table('purchase_order_receipts')->insertOrIgnore([
                    'purchase_order_id' => $order->id,
                    'document_id' => $order->received_document_id,
                    'received_by' => null,
                    'received_at' => $order->updated_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipts');
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('received_qty');
        });
    }
};
