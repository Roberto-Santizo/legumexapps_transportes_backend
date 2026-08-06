<?php

namespace Tests\Doubles;

use App\Errors\BadRequestError;
use App\Interfaces\Storage\ImageProcessorServiceInterface;
use Illuminate\Http\UploadedFile;
use Override;

/**
 * An image processor that decodes nothing and always answers the same bytes.
 *
 * It exists to prove the substitutability the spec asks for: the domain suite
 * has to pass without ever running GD, because recortar is not the domain's
 * business.
 */
class StaticImageProcessorService implements ImageProcessorServiceInterface
{
    /**
     * @param  bool  $failing  Makes normalizeSquare() reject everything, as an undecodable file would.
     */
    public function __construct(private readonly bool $failing = false) {}

    #[Override]
    public function normalizeSquare(UploadedFile $file): array
    {
        if ($this->failing) {
            throw new BadRequestError('No se pudo procesar la imagen');
        }

        return [
            'contents' => 'bytes-procesados',
            'extension' => str_ends_with($file->getClientOriginalName(), '.png') ? 'png' : 'jpg',
        ];
    }
}
