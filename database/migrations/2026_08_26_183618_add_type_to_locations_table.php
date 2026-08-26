<?php

use App\Enums\LocationType;
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
        Schema::table('locations', function (Blueprint $table) {
            /**
             * Etiqueta de catálogo, no regla de negocio: no interviene en ninguna tarifa.
             * El default es el valor real de todas las filas anteriores a esta spec, no relleno.
             */
            $table->string('type')->default(LocationType::Destination->value)->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
