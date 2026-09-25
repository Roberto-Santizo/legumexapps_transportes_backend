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
        Schema::create('trip_finished_products', function (Blueprint $table) {
            $table->id();

            /** Inmutable y sin cascade: borrar el viaje es baja lógica y no toca sus líneas. */
            $table->foreignId('trip_id')->constrained('trips');

            /** Inmutable y sin cascade: un producto terminado borrado sigue mostrándose en la línea. */
            $table->foreignId('finished_product_id')->constrained('finished_products');

            /** Cajas físicas, siempre >= 1. El único campo editable de la fila. */
            $table->integer('boxes');

            /** Sale del usuario autenticado y no se reescribe en el PATCH. */
            $table->foreignId('registered_by')->constrained('users');

            $table->timestamps();

            /**
             * Un producto aparece una sola vez por viaje. Respalda la regla del service ante
             * dos altas simultáneas y sirve, por su prefijo, a la única consulta del listado.
             */
            $table->unique(['trip_id', 'finished_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_finished_products');
    }
};
