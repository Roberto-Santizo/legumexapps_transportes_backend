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
        Schema::create('accessories', function (Blueprint $table) {
            $table->id();
            /** Siempre en mayúsculas, así que el índice único no depende del collation. */
            $table->string('name')->unique();
            /**
             * El identificador que teclea el usuario —código interno o número de serie—,
             * distinto del `id` de la base. Único global: a diferencia de la placa de un
             * vehículo, un accesorio dado de baja no libera su código.
             */
            $table->string('code')->unique();
            $table->text('description')->nullable();
            /** Dinero en GTQ, hasta 99 999 999.99. Nada de flotantes. */
            $table->decimal('price', 10, 2);
            /** Interesa el día de la compra; los timestamps guardan cuándo se capturó. */
            $table->date('purchase_date');
            /** Porcentaje anual de depreciación lineal, validado en [0, 100]. */
            $table->decimal('annual_depreciation', 5, 2)->default(0);
            /** `string`, no enum de Postgres: añadir un caso es tocar PHP, no migrar. */
            $table->string('status')->default('active');
            /** Sin cascade: borrar al usuario que registró el accesorio no puede borrarlo. */
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accessories');
    }
};
