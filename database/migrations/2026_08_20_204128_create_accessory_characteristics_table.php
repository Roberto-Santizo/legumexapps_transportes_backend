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
        Schema::create('accessory_characteristics', function (Blueprint $table) {
            $table->id();
            /**
             * Sin cascade a propósito: el `DELETE` de accesorios es baja lógica y nunca
             * dispararía. Si algún día alguien borra un accesorio de verdad, la restricción
             * debe fallar ruidosamente en vez de llevarse filas por delante en silencio.
             */
            $table->foreignId('accessory_id')->constrained('accessories');
            /** Siempre en mayúsculas, así que la unicidad no depende del collation. */
            $table->string('name');
            /** Dato corto —una placa, un tipo de combustible—: el límite coincide con el de la validación. */
            $table->string('value', 500);
            /** Sin cascade: borrar a quien capturó la característica no puede borrarla. */
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();

            /**
             * La unicidad de verdad. Dos accesorios distintos pueden tener ambos «PLACA»;
             * que uno tenga dos «PLACA» es un error de captura. No hace falta un índice
             * suelto sobre `accessory_id`: este lo lleva de primera columna y sirve al
             * filtro del listado, que es la única consulta del dominio.
             */
            $table->unique(['accessory_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accessory_characteristics');
    }
};
