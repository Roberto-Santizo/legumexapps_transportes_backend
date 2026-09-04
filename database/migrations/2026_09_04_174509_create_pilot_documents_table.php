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
        Schema::create('pilot_documents', function (Blueprint $table) {
            $table->id();
            /**
             * Único: un piloto tiene como mucho una fila de documentos. Sin cascade, como en
             * el resto del proyecto: no existe baja de usuario, y si algún día alguien borra
             * uno a mano, la restricción debe fallar ruidosamente en vez de llevarse la fila
             * por delante y dejar dos objetos huérfanos en el bucket.
             */
            $table->foreignId('user_id')->unique()->constrained('users');
            /** La key completa, «pilot-documents/{uuid}.jpg», nunca la URL. */
            $table->string('dpi_image');
            /** Ídem. Las dos son NOT NULL: si hay fila, hay los dos archivos. */
            $table->string('license_image');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pilot_documents');
    }
};
