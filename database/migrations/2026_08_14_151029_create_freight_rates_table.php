<?php

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
        Schema::create('freight_rates', function (Blueprint $table) {
            $table->id();
            /** Ninguna FK cascadea: borrar una zona o un producto con tarifas debe frenarse. */
            $table->foreignId('zone_id')->constrained('zones');
            $table->foreignId('product_id')->constrained('products');
            $table->string('fuel_type');
            /** GTQ por galón desde el cual rige la tarifa; misma forma que fuel_prices.price. */
            $table->decimal('fuel_min', 8, 2);
            /** GTQ por libra, con seis decimales para que 0.454120 no pierda precisión. */
            $table->decimal('price_per_pound', 12, 6);
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            /**
             * La consulta caliente de la cotización de punta a punta: filtra por los tres
             * primeros y ordena por el cuarto. No es único a propósito — con deleted_at,
             * un índice único impediría recotizar un fuel_min que se borró.
             */
            $table->index(['zone_id', 'product_id', 'fuel_type', 'fuel_min']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freight_rates');
    }
};
