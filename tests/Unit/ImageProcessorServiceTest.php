<?php

use App\Errors\BadRequestError;
use App\Interfaces\Storage\ImageProcessorServiceInterface;
use App\Services\Storage\ImageProcessorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

function imageProcessor(): ImageProcessorService
{
    return new ImageProcessorService;
}

/**
 * Build a real, decodable image on disk and wrap it in an UploadedFile.
 *
 * The default painting is a coarse pattern instead of a blank canvas, so the
 * original weighs what a real photo weighs and recompression can be measured.
 *
 * @param  callable(GdImage, int, int): void|null  $paint  Custom painting of the canvas.
 */
function imageFile(string $name, int $width, int $height, ?callable $paint = null): UploadedFile
{
    $canvas = imagecreatetruecolor($width, $height);

    if ($paint !== null) {
        $paint($canvas, $width, $height);
    } else {
        for ($x = 0; $x < $width; $x += 16) {
            for ($y = 0; $y < $height; $y += 16) {
                $color = imagecolorallocate($canvas, ($x * 7) % 256, ($y * 13) % 256, (($x + $y) * 3) % 256);

                imagefilledrectangle($canvas, $x, $y, $x + 15, $y + 15, $color);
            }
        }
    }

    $path = sys_get_temp_dir().'/legumex-img-'.Str::uuid();

    str_ends_with($name, '.png') ? imagepng($canvas, $path) : imagejpeg($canvas, $path, 90);

    imagedestroy($canvas);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Paint the central square red and the side bands blue.
 *
 * A centered crop keeps only the red; squeezing the whole image into the square
 * would drag the blue bands in with it.
 */
function paintCenteredSquare(GdImage $canvas, int $width, int $height): void
{
    imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 0, 0, 255));

    $offset = (int) (($width - $height) / 2);

    imagefilledrectangle($canvas, $offset, 0, $offset + $height, $height, imagecolorallocate($canvas, 255, 0, 0));
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/legumex-img-*') ?: [] as $path) {
        @unlink($path);
    }
});

it('resuelve la implementación registrada en el provider', function () {
    expect(app(ImageProcessorServiceInterface::class))->toBeInstanceOf(ImageProcessorService::class);
});

/*
|--------------------------------------------------------------------------
| Dimensiones
|--------------------------------------------------------------------------
*/

it('normaliza cualquier entrada a un cuadrado del lado canónico', function (string $name, int $width, int $height) {
    $result = imageProcessor()->normalizeSquare(imageFile($name, $width, $height));

    $size = getimagesizefromstring($result['contents']);

    expect($size[0])->toBe(800)
        ->and($size[1])->toBe(800);
})->with([
    'apaisada' => ['foto.jpg', 1600, 900],
    'vertical' => ['logo.png', 600, 1200],
    'más pequeña que el lado canónico' => ['mini.png', 200, 200],
    'ya cuadrada y del lado canónico' => ['exacta.jpg', 800, 800],
]);

it('devuelve bytes decodificables cuando la entrada ya era 800x800', function () {
    $result = imageProcessor()->normalizeSquare(imageFile('exacta.png', 800, 800));

    expect(imagecreatefromstring($result['contents']))->toBeInstanceOf(GdImage::class);
});

it('recorta los bordes laterales en vez de deformar la imagen', function () {
    $result = imageProcessor()->normalizeSquare(imageFile('patron.png', 1600, 800, paintCenteredSquare(...)));

    $image = imagecreatefromstring($result['contents']);

    /** Un recorte centrado deja rojo hasta el borde; un encaje deformado traería el azul de las bandas. */
    foreach ([5, 400, 795] as $x) {
        $color = imagecolorsforindex($image, imagecolorat($image, $x, 400));

        expect($color['red'])->toBeGreaterThan(200)
            ->and($color['blue'])->toBeLessThan(55);
    }
});

/*
|--------------------------------------------------------------------------
| Formato
|--------------------------------------------------------------------------
*/

it('conserva el formato de entrada y lo anuncia en la extensión', function (string $name, string $extension, string $mime) {
    $result = imageProcessor()->normalizeSquare(imageFile($name, 1000, 500));

    expect($result['extension'])->toBe($extension)
        ->and(getimagesizefromstring($result['contents'])['mime'])->toBe($mime);
})->with([
    'jpeg' => ['foto.jpg', 'jpg', 'image/jpeg'],
    'png' => ['logo.png', 'png', 'image/png'],
]);

/*
|--------------------------------------------------------------------------
| Peso y efectos colaterales
|--------------------------------------------------------------------------
*/

it('devuelve una foto grande pesando menos que el original', function () {
    $file = imageFile('foto.jpg', 4000, 3000);

    $result = imageProcessor()->normalizeSquare($file);

    expect(strlen($result['contents']))->toBeLessThan($file->getSize());
});

it('no modifica el archivo original en disco', function () {
    $file = imageFile('foto.jpg', 1600, 900);

    $before = md5_file($file->getRealPath());

    imageProcessor()->normalizeSquare($file);

    expect(md5_file($file->getRealPath()))->toBe($before);
});

it('lanza BadRequestError cuando el archivo no es una imagen decodificable', function () {
    imageProcessor()->normalizeSquare(UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf'));
})->throws(BadRequestError::class, 'No se pudo procesar la imagen');
