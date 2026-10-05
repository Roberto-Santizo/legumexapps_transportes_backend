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
        Schema::create('trip_emergency_expenses', function (Blueprint $table) {
            $table->id();

            /**
             * Sin cascade, como el resto del proyecto: borrar el viaje es baja lógica
             * y no toca sus gastos emergentes. Inmutable: el PATCH no lo reescribe.
             */
            $table->foreignId('trip_id')->constrained('trips');

            /** Monto gastado en el imprevisto, en GTQ. Siempre > 0. */
            $table->decimal('amount', 10, 2);

            /** Qué pasó, tal como se teclea (solo trim). Obligatoria: no hay categorías. */
            $table->string('description');

            /** Key completa del comprobante en el bucket (trip-emergency-expenses/{uuid}.pdf), nunca la URL. */
            $table->string('receipt')->nullable();

            /** El usuario que registró el gasto. El PATCH no lo reescribe. */
            $table->foreignId('registered_by')->constrained('users');

            $table->timestamps();

            /** La única consulta del dominio: los gastos emergentes de un viaje, en orden de registro. */
            $table->index('trip_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_emergency_expenses');
    }
};
