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
        Schema::table('carrier_pilots', function (Blueprint $table) {
            /**
             * Salario base mensual en GTQ. Nullable y sin default a propósito: un piloto
             * recién unido nace con null — "todavía no se lo han asignado" —, que no es
             * lo mismo que 0.00, "gana cero".
             */
            $table->decimal('salary', 10, 2)->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_pilots', function (Blueprint $table) {
            $table->dropColumn('salary');
        });
    }
};
