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
        Schema::create('shipping_lines', function (Blueprint $table) {
            $table->id();
            /** Siempre en mayúsculas y con los espacios interiores ya colapsados. */
            $table->string('name')->unique();
            /** Sin cascade: borrar al usuario que dio de alta la naviera debe frenarse. */
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();
            /**
             * El índice único convive con el borrado lógico a propósito: una fila con
             * `deleted_at` sigue ocupando su entrada, así que una naviera borrada no
             * libera su nombre. Es lo contrario de `freight_rates`, que no lleva índice
             * único justo para poder reutilizar un `fuel_min` ya borrado.
             */
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_lines');
    }
};
