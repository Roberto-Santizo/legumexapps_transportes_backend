<?php

use App\Errors\BadRequestError;
use App\Services\Storage\S3FileStorageService;
use Illuminate\Support\Facades\Storage;

function fileStorage(): S3FileStorageService
{
    return new S3FileStorageService;
}

/*
|--------------------------------------------------------------------------
| store()
|--------------------------------------------------------------------------
*/

it('devuelve una key con el directorio, un uuid y la extensión recibida', function () {
    $key = fileStorage()->store('bytes', 'carriers', 'png');

    expect($key)->toMatch('#^carriers/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$#');
});

it('escribe en el disco exactamente los bytes recibidos', function () {
    $contents = random_bytes(64);

    $key = fileStorage()->store($contents, 'carriers', 'png');

    Storage::assertExists($key);

    expect(Storage::get($key))->toBe($contents);
});

it('respeta la extensión que se le pasa', function (string $extension) {
    expect(fileStorage()->store('bytes', 'vehicles', $extension))->toEndWith('.'.$extension);
})->with(['jpg', 'jpeg', 'png']);

it('no deja rastro del nombre original del archivo en la key', function () {
    $key = fileStorage()->store('bytes', 'carriers', 'png');

    expect($key)->not->toContain('mi-foto-personal');
});

it('genera keys distintas para el mismo contenido', function () {
    $storage = fileStorage();

    $first = $storage->store('mismos bytes', 'carriers', 'png');
    $second = $storage->store('mismos bytes', 'carriers', 'png');

    expect($first)->not->toBe($second);

    Storage::assertExists($first);
    Storage::assertExists($second);
});

it('traduce a BadRequestError el false de retorno del disco', function () {
    Storage::shouldReceive('put')->once()->andReturnFalse();

    fileStorage()->store('bytes', 'carriers', 'png');
})->throws(BadRequestError::class, 'No se pudo almacenar la imagen');

it('traduce a BadRequestError cualquier Throwable del disco', function () {
    Storage::shouldReceive('put')->once()->andThrow(new RuntimeException('region inválida'));

    fileStorage()->store('bytes', 'carriers', 'png');
})->throws(BadRequestError::class, 'No se pudo almacenar la imagen');

/*
|--------------------------------------------------------------------------
| delete()
|--------------------------------------------------------------------------
*/

it('borra una key existente y devuelve true', function () {
    $storage = fileStorage();

    $key = $storage->store('bytes', 'carriers', 'png');

    expect($storage->delete($key))->toBeTrue();

    Storage::assertMissing($key);
});

it('devuelve false sin lanzar cuando la key es null', function () {
    expect(fileStorage()->delete(null))->toBeFalse();
});

it('devuelve false sin lanzar cuando el archivo no existe', function () {
    expect(fileStorage()->delete('carriers/no-existe.png'))->toBeFalse();
});

it('devuelve false sin lanzar cuando el disco revienta al borrar', function () {
    Storage::shouldReceive('exists')->once()->andReturnTrue();
    Storage::shouldReceive('delete')->once()->andThrow(new RuntimeException('sin credenciales'));

    expect(fileStorage()->delete('carriers/x.png'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| url()
|--------------------------------------------------------------------------
*/

it('devuelve null cuando la key es null', function () {
    expect(fileStorage()->url(null))->toBeNull();
});

it('devuelve una URL absoluta que termina en la key', function () {
    expect(fileStorage()->url('carriers/x.png'))
        ->toStartWith('http')
        ->toEndWith('carriers/x.png');
});
