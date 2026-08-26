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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            /**
             * Tecleado por el administrador y siempre en mayúsculas, así que el índice único
             * no depende del collation. Nunca contiene espacios: el FormRequest los rechaza.
             */
            $table->string('code', 15)->unique();
            /** Siempre en mayúsculas y con los espacios interiores ya colapsados. */
            $table->string('name')->unique();
            /** Sin cascade: borrar al usuario que dio de alta el cliente debe frenarse. */
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();
            /**
             * Los dos índices únicos conviven con el borrado lógico a propósito: una fila
             * con `deleted_at` sigue ocupando su entrada, así que un cliente borrado no
             * libera su código ni su nombre. Es lo contrario de `freight_rates`, que no
             * lleva índice único justo para poder reutilizar un `fuel_min` ya borrado.
             */
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
