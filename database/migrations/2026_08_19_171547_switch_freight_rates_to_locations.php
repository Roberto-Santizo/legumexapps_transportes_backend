<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Migración DESTRUCTIVA y consciente: las tarifas cotizadas por zona se descartan y
     * no hay backfill. Una zona es un polígono y no tiene un punto que la represente,
     * así que cualquier destino inventado para conservarlas sería un dato fabricado con
     * apariencia de real en la tabla que decide precios.
     */
    public function up(): void
    {
        /** Va antes de tocar el esquema: location_id es not null y una tabla con filas no lo admitiría. */
        DB::table('freight_rates')->delete();

        Schema::table('freight_rates', function (Blueprint $table) {
            /** Se borra primero porque incluye zone_id, y se rehace idéntico más abajo. */
            $table->dropIndex(['zone_id', 'product_id', 'fuel_type', 'fuel_min']);
            $table->dropConstrainedForeignId('zone_id');

            /** Sin cascade, igual que la FK anterior: borrar un destino con tarifas debe frenarse. */
            $table->foreignId('location_id')->after('id')->constrained('locations');

            /**
             * La consulta caliente de la cotización de punta a punta: filtra por los tres
             * primeros y ordena por el cuarto. Sigue sin ser único a propósito — con
             * deleted_at, un índice único impediría recotizar un fuel_min que se borró.
             */
            $table->index(['location_id', 'product_id', 'fuel_type', 'fuel_min']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * También vacía la tabla: una migración destructiva no se revierte con datos.
     */
    public function down(): void
    {
        DB::table('freight_rates')->delete();

        Schema::table('freight_rates', function (Blueprint $table) {
            $table->dropIndex(['location_id', 'product_id', 'fuel_type', 'fuel_min']);
            $table->dropConstrainedForeignId('location_id');

            $table->foreignId('zone_id')->after('id')->constrained('zones');

            $table->index(['zone_id', 'product_id', 'fuel_type', 'fuel_min']);
        });
    }
};
