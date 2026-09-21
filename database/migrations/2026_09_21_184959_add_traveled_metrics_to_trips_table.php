<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the real distance and duration of the trip, computed by the API on
     * PATCH /api/trips/{trip}/finish: the Haversine sum of the trip_positions
     * trail and end_date - start_date. Same types as estimated_kilometers and
     * estimated_hours so both pairs share scale and output format. Nullable
     * and without default: null means the trip has not finished yet or
     * finished before these columns existed (no backfill); a trip that
     * finished without positions stores 0.00, not null.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('traveled_kilometers', 8, 2)->nullable()->after('traveled_polyline');
            $table->decimal('traveled_hours', 6, 2)->nullable()->after('traveled_kilometers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['traveled_kilometers', 'traveled_hours']);
        });
    }
};
