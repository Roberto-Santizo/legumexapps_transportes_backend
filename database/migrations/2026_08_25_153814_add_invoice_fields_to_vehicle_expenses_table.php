<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive on purpose: `is_invoiced` carries a default so the rows captured
     * before this migration survive without a backfill — false and null are
     * exactly what they meant until today. That default is filler, not
     * business: the API demands the flag on every new expense.
     *
     * No new index: the `isInvoiced` filter always travels along the mandatory
     * `vehicle_id`, already covered by the (vehicle_id, expense_date) index.
     */
    public function up(): void
    {
        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->boolean('is_invoiced')->default(false)->after('description');
            $table->string('invoice')->nullable()->after('is_invoiced');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->dropColumn(['is_invoiced', 'invoice']);
        });
    }
};
