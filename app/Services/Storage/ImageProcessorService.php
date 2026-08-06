<?php

namespace App\Services\Storage;

use App\Errors\BadRequestError;
use App\Interfaces\Storage\ImageProcessorServiceInterface;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Override;
use Throwable;

final class ImageProcessorService implements ImageProcessorServiceInterface
{
    /**
     * Side of the final square, in pixels.
     */
    private const SIDE = 800;

    /**
     * Quality of the JPEG re-encoding, from 0 to 100.
     */
    private const JPEG_QUALITY = 80;

    /**
     * Message returned to the client whenever the file cannot be decoded.
     */
    private const PROCESS_ERROR_MESSAGE = 'No se pudo procesar la imagen';

    #[Override]
    public function normalizeSquare(UploadedFile $file): array
    {
        try {
            $image = (new ImageManager(new Driver))->decodePath($file->getRealPath());

            /** cover() rescales to the larger side and trims the rest from the edges, never distorting. */
            $image->cover(self::SIDE, self::SIDE);

            /**
             * The input format is kept: turning a PNG into a JPEG would put a
             * black background behind every logo with transparency.
             */
            $isPng = $image->origin()->format() === Format::PNG;

            $encoded = $isPng
                ? $image->encode(new PngEncoder)
                : $image->encode(new JpegEncoder(self::JPEG_QUALITY));
        } catch (Throwable) {
            throw new BadRequestError(self::PROCESS_ERROR_MESSAGE);
        }

        return [
            'contents' => $encoded->toString(),
            'extension' => $isPng ? 'png' : 'jpg',
        ];
    }
}
