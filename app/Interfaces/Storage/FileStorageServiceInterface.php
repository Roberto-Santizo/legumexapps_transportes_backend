<?php

namespace App\Interfaces\Storage;

use App\Errors\BadRequestError;

interface FileStorageServiceInterface
{
    /**
     * Store raw file contents under the given directory and return its key.
     *
     * The directory comes without leading nor trailing slash and the extension
     * without a dot; the returned key is the full path, never null.
     *
     * The stored file must end up publicly readable: url() promises an address
     * the browser resolves without credentials.
     *
     * @param  string  $contents  Raw bytes to persist, already processed.
     * @param  string  $directory  Domain prefix, e.g. 'carriers'.
     * @param  string  $extension  Extension without dot, e.g. 'png'.
     * @return string Full key, e.g. 'carriers/9f3a....png'.
     *
     * @throws BadRequestError when the underlying storage rejects the contents.
     */
    public function store(string $contents, string $directory, string $extension): string;

    /**
     * Delete a stored file by key.
     *
     * Never throws: a failed cleanup must not bring down a request that already
     * did its job.
     *
     * @return bool False when the key is null or the file is missing.
     */
    public function delete(?string $key): bool;

    /**
     * Resolve the publicly reachable URL of a stored file.
     *
     * @return string|null Null when the key is null.
     */
    public function url(?string $key): ?string;
}
