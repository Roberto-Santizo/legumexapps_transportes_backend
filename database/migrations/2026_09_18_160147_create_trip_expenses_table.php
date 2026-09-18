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
        Schema::create('trip_expenses', function (Blueprint $table) {
            $table->id();

            /**
             * Sin cascade, como el resto del proyecto: borrar el viaje es baja lógica
             * y no toca el rastro de sus viáticos.
             */
            $table->foreignId('trip_id')->constrained('trips');

            /** Monto entregado en esta fila, en GTQ. Siempre > 0: no hay viáticos negativos ni correcciones. */
            $table->decimal('amount', 10, 2);

            /** Concepto libre, tal como se teclea (solo trim). Vacío se guarda como null. */
            $table->string('description')->nullable();

            /**
             * Define el ciclo de vida: null = el piloto aún no confirmó haber recibido el dinero.
             * Lo pone el servidor con now() al confirmar, nunca el dispositivo, y no se pisa jamás.
             */
            $table->timestamp('received_at')->nullable();

            /** El piloto que confirmó. Nace null y se escribe junto a received_at, nunca por separado. */
            $table->foreignId('confirmed_by')->nullable()->constrained('users');

            /** El usuario de la empresa que registró el viático. En la primera fila, el que asignó el viaje. */
            $table->foreignId('registered_by')->constrained('users');

            $table->timestamps();

            /** La única consulta del dominio: los viáticos de un viaje, en orden de registro. */
            $table->index('trip_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_expenses');
    }
};
