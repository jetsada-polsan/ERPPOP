<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('transport_jobs', function (Blueprint $t) {
        $t->id(); $t->foreignId('booking_id')->nullable()->unique()->constrained('sale_bookings')->nullOnDelete();
        $t->foreignId('vehicle_id')->nullable()->constrained('fleet_vehicles')->nullOnDelete();
        $t->string('status', 20)->default('booked'); // booked, loaded, in_transit, delivered, paid, cancelled
        $t->timestamp('loaded_at')->nullable(); $t->timestamp('delivered_at')->nullable(); $t->timestamp('paid_at')->nullable();
        $t->string('payment_method', 12)->nullable(); $t->decimal('paid_amount', 14, 2)->nullable(); $t->string('transfer_last4', 4)->nullable();
        $t->text('note')->nullable(); $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
    }); }
    public function down(): void { Schema::dropIfExists('transport_jobs'); }
};
