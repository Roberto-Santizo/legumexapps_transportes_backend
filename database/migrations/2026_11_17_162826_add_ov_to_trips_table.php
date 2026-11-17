<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the optional sales order ("orden de venta", SPEC 43). Nullable,
     * without default, index nor backfill: null means the trip has no sales
     * order, legitimate on any trip, before or after this column existed.
     * Not unique, since one sales order may cover several trips. Same length
     * as order, container, booking and reference, so the FormRequest max:255
     * turns an overflow into a 422, never a database error.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('ov')->nullable()->after('reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('ov');
        });
    }
};
