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
        Schema::create('trip_timeouts', function (Blueprint $table) {
            $table->id();

            /** Las tres FK van sin cascade, como en `trip_positions`: una parada es historial. */
            $table->foreignId('trip_id')->constrained('trips');

            /**
             * Quién conducía cuando el camión se detuvo. Redundante con el viaje hoy, pero la
             * asignación de SPEC 24 se puede cambiar mientras el viaje sigue `pending`, así que
             * la parada debe seguir diciendo quién estaba al volante.
             */
            $table->foreignId('pilot_id')->constrained('users');

            /** El punto donde el camión ya estaba parado: el ancla contra la que se mide el cierre. */
            $table->foreignId('start_position_id')->constrained('trip_positions');

            /**
             * El punto que cerró la parada. Queda null cuando la cerró el finish del viaje,
             * y ese null es la única forma de distinguir las dos causas de cierre.
             */
            $table->foreignId('end_position_id')->nullable()->constrained('trip_positions');

            /** Coordenadas del ancla, duplicadas para que el mapa pinte el pin sin join. */
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            /** Sirve al listado (started_at asc) y a la búsqueda de la parada abierta del viaje. */
            $table->index(['trip_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_timeouts');
    }
};
