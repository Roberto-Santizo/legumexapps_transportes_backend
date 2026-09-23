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
        Schema::create('user_device_tokens', function (Blueprint $table) {
            $table->id();

            /**
             * La primera FK con cascade del proyecto: un token sin usuario no vale nada,
             * a diferencia de un viaje o un gasto, que son historial.
             */
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /**
             * Token FCM tal cual llega: identificador opaco y sensible a mayúsculas.
             * Único global, porque un token es de un solo dispositivo y el POST lo reasigna.
             */
            $table->string('token', 512)->unique();

            /** Uno de los casos de DevicePlatform: android | ios. */
            $table->string('platform');

            /** now() del servidor en cada POST; base de una purga futura. */
            $table->timestamp('last_seen_at');

            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};
