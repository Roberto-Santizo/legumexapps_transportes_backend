<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the estimated distance and duration of the planned route, sent by
     * the frontend together with the polyline (both come from the same
     * GET /api/places/directions response). Nullable and without default:
     * null means the trip was created before these columns existed. No
     * backfill. The precision matches the FormRequest max rules so an
     * overflow is a 422, never a database error.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('estimated_kilometers', 8, 2)->nullable()->after('polyline');
            $table->decimal('estimated_hours', 6, 2)->nullable()->after('estimated_kilometers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['estimated_kilometers', 'estimated_hours']);
        });
    }
};
