<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#3388FF');
            $table->geography('area', 'polygon', 4326);
            $table->boolean('status')->default(true);
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();
        });

        DB::statement('CREATE INDEX zones_area_gist_index ON zones USING GIST (area)');
    }

    /**
     * Reverse the migrations.
     *
     * The postgis extension is intentionally left in place: other tables will use it.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
