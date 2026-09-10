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
        Schema::create('trip_fuels', function (Blueprint $table) {
            $table->id();

            /**
             * Sin cascade, como el resto del proyecto: borrar el viaje es baja lógica
             * y no toca el rastro de sus cargas.
             */
            $table->foreignId('trip_id')->constrained('trips');

            /** Galones asignados en esta carga. Siempre > 0: no hay cargas negativas ni correcciones. */
            $table->decimal('gallons', 8, 2);

            /**
             * Uno de los cuatro casos de FuelType (SPEC 06). Por CARGA, no por viaje: dos cargas
             * del mismo viaje pueden diferir. Es una etiqueta, no una llave foránea: no se
             * comprueba contra `fuel_prices` y no se guarda ningún precio.
             */
            $table->string('fuel_type');

            /**
             * El único nullable de la tabla, y el que define el ciclo de vida: null = sin confirmar.
             * Lo pone el servidor con now() al confirmar, nunca el dispositivo, y no se pisa jamás.
             */
            $table->timestamp('loaded_at')->nullable();

            /** El piloto que confirmó. Nace null y se escribe junto a loaded_at, nunca por separado. */
            $table->foreignId('confirmed_by')->nullable()->constrained('users');

            /** El usuario de la empresa que registró la carga. En la primera fila, el que asignó el viaje. */
            $table->foreignId('registered_by')->constrained('users');

            $table->timestamps();

            /** La única consulta del dominio: las cargas de un viaje, en orden de registro. */
            $table->index('trip_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_fuels');
    }
};
