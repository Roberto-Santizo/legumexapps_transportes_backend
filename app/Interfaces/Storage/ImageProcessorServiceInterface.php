<?php

namespace App\Interfaces\Storage;

use App\Errors\BadRequestError;
use Illuminate\Http\UploadedFile;

interface ImageProcessorServiceInterface
{
    /**
     * Crop the image to a centered square, resize it to the canonical side and re-encode it.
     *
     * The output is always square and of the canonical side, whatever the input
     * was: an already small or already square image is processed all the same.
     * The returned extension is one of jpg, jpeg or png and always matches the
     * real format of the returned bytes, because it decides the key extension.
     * The uploaded file is never modified: processing happens in memory.
     *
     * @return array{contents: string, extension: string} Processed bytes and the extension they were encoded as.
     *
     * @throws BadRequestError when the file cannot be decoded as an image.
     */
    public function normalizeSquare(UploadedFile $file): array;
}
