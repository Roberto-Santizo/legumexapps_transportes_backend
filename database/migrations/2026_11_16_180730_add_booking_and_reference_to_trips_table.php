<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the shipping line's booking and an optional commercial reference
     * (SPEC 41). Both nullable, without default, index nor backfill: a null
     * booking means the trip was created before these columns existed, since
     * the API demands it on creation; a null reference means the trip has
     * none. Same length as order and container, so the FormRequest max:255
     * turns an overflow into a 422, never a database error.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('booking')->nullable()->after('container');
            $table->string('reference')->nullable()->after('booking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['booking', 'reference']);
        });
    }
};
