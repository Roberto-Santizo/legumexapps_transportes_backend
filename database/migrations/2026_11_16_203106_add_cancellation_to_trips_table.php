<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the cancellation marks of a trip (SPEC 42). Both nullable, without
     * default nor backfill, and always written together by PATCH /{trip}/cancel:
     * null means the trip was not cancelled, and there is no other reading. The
     * foreign key carries no cascade, like every other one on trips.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('end_date');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });
    }
};
