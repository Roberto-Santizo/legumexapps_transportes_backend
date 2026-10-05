<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the cargo insurance in GTQ the carrier company sets when it takes the trip
     * on PATCH /api/trips/{trip}/assignment, next to the bonus. A single amount per
     * trip —reassigning overwrites it—, with no confirmation by the pilot. Nullable
     * and without default: null means the trip has not been assigned yet or was
     * assigned before this column existed (no backfill).
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('cargo_insurance', 10, 2)->nullable()->after('bonus');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('cargo_insurance');
        });
    }
};
