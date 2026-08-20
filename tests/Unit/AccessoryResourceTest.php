<?php

use App\Http\Resources\Accessory\AccessoryResource;
use App\Models\Accessory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Build the resource array of an unsaved accessory.
 *
 * The model is never persisted: `currentValue` is arithmetic over attributes already
 * in memory, so the calculation is testable without touching the database.
 *
 * @return array<string, mixed>
 */
function accessoryResourceArray(string $price, string $annualDepreciation, string $purchaseDate): array
{
    $accessory = new Accessory([
        'name' => 'GATO HIDRÁULICO 20 TON',
        'code' => 'ACC-0012',
        'price' => $price,
        'annual_depreciation' => $annualDepreciation,
        'purchase_date' => $purchaseDate,
    ]);

    return (new AccessoryResource($accessory))->toArray(Request::create('/api/accessories'));
}

/**
 * Resolve just the derived value of an accessory bought a given number of days ago.
 */
function currentValueAfterDays(string $price, string $annualDepreciation, int $days): string
{
    return accessoryResourceArray($price, $annualDepreciation, now()->subDays($days)->toDateString())['currentValue'];
}

it('devuelve todas las claves en camelCase', function () {
    $accessory = Accessory::factory()->create();

    expect(array_keys((new AccessoryResource($accessory))->toArray(Request::create('/api/accessories'))))->toBe([
        'id', 'name', 'code', 'description', 'price', 'purchaseDate',
        'annualDepreciation', 'currentValue', 'status', 'registeredBy', 'createdAt',
    ]);
});

it('el día de la compra el valor actual es el precio, porque la antigüedad es cero', function () {
    expect(currentValueAfterDays('10000.00', '20.00', 0))->toBe('10000.00');
});

it('deprecia linealmente: 20% anual sobre 10000 durante dos años deja 6000', function () {
    expect(currentValueAfterDays('10000.00', '20.00', 730))->toBe('6000.00');
});

it('cuenta la antigüedad en fracción de años por días, no en años cumplidos', function () {
    /** Media anualidad: 10000 − 10000 × 0.20 × (182/365) = 9002.74, no los 10000 de un año sin cumplir. */
    expect(currentValueAfterDays('10000.00', '20.00', 182))->toBe('9002.74');
});

it('no baja de cero por mucha antigüedad que acumule', function () {
    expect(currentValueAfterDays('10000.00', '20.00', 365 * 6))->toBe('0.00');
});

it('con depreciación 0 el valor actual es el precio para siempre', function () {
    expect(currentValueAfterDays('10000.00', '0.00', 365 * 10))->toBe('10000.00');
});

it('devuelve el valor actual como cadena de dos decimales, igual que el precio', function () {
    $resource = accessoryResourceArray('1234.56', '13.00', now()->subDays(97)->toDateString());

    expect($resource['currentValue'])->toBeString()
        ->and($resource['currentValue'])->toMatch('/^\d+\.\d{2}$/')
        ->and($resource['price'])->toBe('1234.56');
});

it('no guarda el valor actual en ninguna columna: se deriva en cada lectura', function () {
    $accessory = Accessory::factory()->create([
        'price' => '10000.00',
        'annual_depreciation' => '20.00',
        /** En días, no en años: un subYear() sobre un febrero bisiesto daría 366 y otro número. */
        'purchase_date' => now()->subDays(365)->toDateString(),
    ]);

    expect(Schema::getColumnListing('accessories'))->not->toContain('current_value');

    /** El mismo accesorio, leído un año después, vale menos sin que nadie escriba en la tabla. */
    $today = (new AccessoryResource($accessory))->toArray(Request::create('/api/accessories'))['currentValue'];

    $this->travel(365)->days();

    $later = (new AccessoryResource($accessory->fresh()))->toArray(Request::create('/api/accessories'))['currentValue'];

    expect($today)->toBe('8000.00')->and($later)->toBe('6000.00');
});

it('formatea la fecha de compra como d-m-Y', function () {
    expect(accessoryResourceArray('500.00', '10.00', '2024-08-20')['purchaseDate'])->toBe('20-08-2024');
});
