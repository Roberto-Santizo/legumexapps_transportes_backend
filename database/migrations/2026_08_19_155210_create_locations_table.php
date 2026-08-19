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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            /** Siempre en mayúsculas, así que el índice único no depende del collation. */
            $table->string('name')->unique();
            $table->text('description')->nullable();
            /** Identificador opaco de Google, guardado tal cual llega: es sensible a mayúsculas. */
            $table->string('google_place_id')->unique();
            /**
             * Ocho decimales dan precisión de poco más de un milímetro, y la parte entera
             * justa para ±90 y ±180. Nada de flotantes en una coordenada que decide un precio.
             */
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->boolean('status')->default(true);
            /** Sin cascade: borrar al usuario que dio de alta el destino debe frenarse. */
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
