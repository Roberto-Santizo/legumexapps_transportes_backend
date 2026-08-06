<?php

namespace App\Services\Storage;

use App\Errors\BadRequestError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Override;
use Throwable;

final class S3FileStorageService implements FileStorageServiceInterface
{
    /**
     * Message returned to the client whenever the bucket refuses the contents.
     */
    private const STORE_ERROR_MESSAGE = 'No se pudo almacenar la imagen';

    /**
     * ACL applied to every upload: the bucket is public and the front-end links
     * the object URL directly, so the object must be world-readable.
     */
    private const PUBLIC_ACL = 'public-read';

    #[Override]
    public function store(string $contents, string $directory, string $extension): string
    {
        $key = $directory.'/'.Str::uuid().'.'.$extension;

        /**
         * The disks carry 'throw' => false, so a rejected write comes back as a
         * false return value; a bad region, DNS or credentials do throw.
         */
        try {
            $stored = Storage::put($key, $contents, ['ACL' => self::PUBLIC_ACL]);
        } catch (Throwable) {
            throw new BadRequestError(self::STORE_ERROR_MESSAGE);
        }

        if ($stored === false) {
            throw new BadRequestError(self::STORE_ERROR_MESSAGE);
        }

        return $key;
    }

    #[Override]
    public function delete(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        try {
            if (! Storage::exists($key)) {
                return false;
            }

            return Storage::delete($key);
        } catch (Throwable) {
            /** A failed cleanup never brings down a request that already did its job. */
            return false;
        }
    }

    #[Override]
    public function url(?string $key): ?string
    {
        return $key === null ? null : Storage::url($key);
    }
}
