<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the encoded polyline of the real route reported through
     * trip_positions. Nullable and without default: null means the trip has
     * not finished yet, finished without a single position, or finished
     * before this column existed. No backfill.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->text('traveled_polyline')->nullable()->after('polyline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('traveled_polyline');
        });
    }
};
