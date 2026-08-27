<?php

use App\Enums\TripStatus;
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
        Schema::create('trips', function (Blueprint $table) {
            $table->id();

            /** Referencia comercial del viaje. MAYÚSCULAS, espacios colapsados, NO única. */
            $table->string('order');

            /** Catálogos obligatorios. Sin cascade: borrarlos con viajes colgando se frena en el service. */
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('shipping_line_id')->constrained('shipping_lines');
            $table->foreignId('departure_point_id')->constrained('departure_points');
            /** Solo locations de tipo `port` y activas; la regla vive en el service, no en el esquema. */
            $table->foreignId('location_id')->constrained('locations');

            /** Destino final en el extranjero. Texto libre, solo trim: no lo respalda ningún catálogo. */
            $table->string('destination');
            /** MAYÚSCULAS, espacios colapsados, NO único. */
            $table->string('container');
            /** Medio o empresa de transporte, tal como se teclea. */
            $table->string('transport');

            /** Planificado en el alta, siempre a futuro y con hora. */
            $table->timestamp('recolection_date');
            $table->timestamp('ship_date');
            /** Ejecución real: los pone el servidor en /start y /finish, nunca el body. */
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();

            /** Polilínea codificada de Google, tal como la manda el front. Puede pasar de 255. */
            $table->text('polyline');
            $table->text('observations');

            $table->string('status')->default(TripStatus::Pending->value);

            /** Los tres nullables del dominio: un viaje nace sin dueño operativo. */
            $table->foreignId('pilot_id')->nullable()->constrained('users');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $table->foreignId('assigned_by')->nullable()->constrained('users');

            $table->foreignId('registered_by')->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            /** El orden fijo del listado y las dos columnas del filtro de ámbito. */
            $table->index('recolection_date');
            $table->index('assigned_by');
            $table->index('pilot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
