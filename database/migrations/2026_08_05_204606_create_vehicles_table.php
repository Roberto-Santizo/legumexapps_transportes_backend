<?php

use App\Enums\VehicleStatus;
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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('plate');
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('year');
            $table->decimal('capacity', 10, 2);
            $table->string('type');
            $table->string('image')->nullable();
            $table->string('status')->default(VehicleStatus::Active->value);
            $table->timestamps();

            $table->index('plate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
