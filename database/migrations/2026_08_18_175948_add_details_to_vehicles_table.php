<?php

use App\Enums\VehicleCondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('condition')->default(VehicleCondition::Used->value)->after('type');
            $table->decimal('kilometers_per_gallon', 6, 2)->default(1)->after('condition');
            $table->decimal('purchase_price', 12, 2)->default(1)->after('kilometers_per_gallon');
            $table->decimal('monthly_insurance_cost', 10, 2)->default(1)->after('purchase_price');
            $table->unsignedInteger('mileage')->default(1)->after('monthly_insurance_cost');
            $table->string('engine_number', 50)->nullable()->after('mileage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'condition',
                'kilometers_per_gallon',
                'purchase_price',
                'monthly_insurance_cost',
                'mileage',
                'engine_number',
            ]);
        });
    }
};
