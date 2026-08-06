<?php

use App\Enums\FuelPriceStatus;
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
        Schema::create('fuel_prices', function (Blueprint $table) {
            $table->id();
            $table->string('fuel_type');
            $table->decimal('price', 8, 2);
            $table->string('status')->default(FuelPriceStatus::Active->value);
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();

            $table->index(['fuel_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fuel_prices');
    }
};
