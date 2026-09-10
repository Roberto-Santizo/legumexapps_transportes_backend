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
        Schema::create('trip_positions', function (Blueprint $table) {
            $table->id();

            /**
             * El viaje al que pertenece el punto. Sin cascade, como toda FK del proyecto:
             * el DELETE del viaje es baja lógica y no debe llevarse el rastro por delante.
             */
            $table->foreignId('trip_id')->constrained('trips');

            /**
             * Quién lo reportó. Redundante hoy —siempre es el `pilot_id` del viaje—, pero la
             * asignación de SPEC 24 se puede cambiar mientras el viaje sigue `pending`, y el
             * rastro debe seguir diciendo quién conducía cuando se grabó cada punto.
             */
            $table->foreignId('pilot_id')->constrained('users');

            /** Misma precisión que `locations` (SPEC 15): milimétrica y sin flotantes. */
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            /**
             * Lo pone el servidor con now(), nunca el dispositivo: no hay forma de mentir
             * sobre cuándo se estuvo dónde, y el orden del rastro es siempre el de llegada.
             */
            $table->timestamp('recorded_at');

            $table->timestamps();

            /**
             * El índice compuesto sirve a las dos únicas consultas del dominio: el listado
             * ordenado del rastro y el «último punto de este viaje» del piso de 15 segundos.
             */
            $table->index(['trip_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_positions');
    }
};
