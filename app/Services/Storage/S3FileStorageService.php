<?php

namespace App\Services\Storage;

use App\Errors\BadRequestError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use Illuminate\Http\UploadedFile;
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
     * Message returned to the client whenever the bucket refuses an upload.
     *
     * Kept apart from STORE_ERROR_MESSAGE because storeUpload() also carries
     * documents: telling the user his PDF is an image would be a lie.
     */
    private const UPLOAD_ERROR_MESSAGE = 'No se pudo almacenar el archivo';

    /**
     * Extensions guessed from the MIME type that the project renames.
     *
     * Symfony maps image/jpeg to 'jpeg' first; the project has always written
     * 'jpg', and the Resource derives invoiceType from this very extension.
     *
     * @var array<string, string>
     */
    private const EXTENSION_ALIASES = ['jpeg' => 'jpg'];

    /**
     * Extension used when the upload carries no recognizable MIME type.
     */
    private const FALLBACK_EXTENSION = 'bin';

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
    public function storeUpload(UploadedFile $file, string $directory): string
    {
        $name = Str::uuid().'.'.$this->resolveExtension($file);

        /**
         * putFileAs streams the upload untouched and, like put(), answers with
         * false when the disk refuses it instead of throwing.
         */
        try {
            $key = Storage::putFileAs($directory, $file, $name, ['ACL' => self::PUBLIC_ACL]);
        } catch (Throwable) {
            throw new BadRequestError(self::UPLOAD_ERROR_MESSAGE);
        }

        if ($key === false) {
            throw new BadRequestError(self::UPLOAD_ERROR_MESSAGE);
        }

        return $key;
    }

    /**
     * Resolve the extension an upload is stored with.
     *
     * Guessed from the real contents, never from the client-supplied name: a
     * 'factura.exe' holding a JPEG lands on disk as '.jpg'.
     */
    private function resolveExtension(UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?? self::FALLBACK_EXTENSION;

        return self::EXTENSION_ALIASES[$extension] ?? $extension;
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
