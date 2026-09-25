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
        Schema::create('finished_products', function (Blueprint $table) {
            $table->id();
            /**
             * Siempre en mayúsculas y sin espacios: el FormRequest los rechaza. El índice
             * único convive con el borrado lógico, así que un SKU borrado no libera su código.
             */
            $table->string('code', 15)->unique();
            /** Solo en mayúsculas, sin trim ni colapso de espacios, y deliberadamente sin índice único. */
            $table->string('name');
            $table->decimal('presentation', 10, 2);
            $table->decimal('boxes_per_pallet', 10, 2);
            /** Sin cascade: borrar un cliente es lógico y la FK sigue siendo válida. */
            $table->foreignId('client_id')->index()->constrained('clients');
            /** Sin cascade: borrar al usuario que dio de alta el producto debe frenarse. */
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finished_products');
    }
};
