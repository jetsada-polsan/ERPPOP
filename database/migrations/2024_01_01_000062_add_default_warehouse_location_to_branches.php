<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BPlus has no branch->warehouse foreign key (WAREHOUSE.WH_ADDB/ADDRBOOK.ADDB_BRANCH
// are both blank in the source data). In practice, this business runs 2 physical
// warehouses ("HO" and "SHOP") where individual WARELOCATION rows under "SHOP" each
// correspond 1:1 by name to a branch (e.g. WARELOCATION "ห้วยวังนอง" = branch
// "สาขา-ห้วยวังนอง"). MasterDataEtlService resolves this by name-matching and stores
// the result here, so POS posting knows which warehouse_location to move stock
// against for a given branch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('default_warehouse_location_id')->nullable()
                ->after('is_active')->constrained('warehouse_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_warehouse_location_id');
        });
    }
};
