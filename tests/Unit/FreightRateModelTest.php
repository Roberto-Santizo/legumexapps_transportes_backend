<?php

use App\Enums\FuelType;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;

it('crea una tarifa con su destino, su producto y su administrador propios', function () {
    $rate = FreightRate::factory()->create();

    expect($rate->location)->toBeInstanceOf(Location::class)
        ->and($rate->product)->toBeInstanceOf(Product::class)
        ->and($rate->registeredBy)->toBeInstanceOf(User::class);
});

it('castea el tipo de combustible y conserva los decimales de cada precio', function () {
    $rate = FreightRate::factory()->create([
        'fuel_type' => FuelType::Diesel,
        'fuel_min' => 30,
        'price_per_pound' => 0.454120,
    ]);

    $rate->refresh();

    expect($rate->fuel_type)->toBe(FuelType::Diesel)
        ->and($rate->fuel_min)->toBe('30.00')
        ->and($rate->price_per_pound)->toBe('0.454120');
});

it('borra en lógico dejando la fila viva con deleted_at', function () {
    $rate = FreightRate::factory()->create();

    $rate->delete();

    expect(FreightRate::whereKey($rate->id)->exists())->toBeFalse()
        ->and(FreightRate::withTrashed()->whereKey($rate->id)->first()->deleted_at)->not->toBeNull();
});
