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
        Schema::create('carrier_pilot_salary_histories', function (Blueprint $table) {
            $table->id();
            /** Cascadea como el resto de carrier_pilots: sin vínculo no hay historial que guardar. */
            $table->foreignId('carrier_pilot_id')->constrained('carrier_pilots')->cascadeOnDelete();
            /** Null solo en la primera asignación: antes de ella no había salario. */
            $table->decimal('previous_salary', 10, 2)->nullable();
            $table->decimal('new_salary', 10, 2);
            /** Sin cascade a propósito: borrar al autor del cambio no puede borrar el rastro. */
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();

            /** Única consulta de la tabla: el historial de un piloto ordenado por id. */
            $table->index(['carrier_pilot_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_pilot_salary_histories');
    }
};
