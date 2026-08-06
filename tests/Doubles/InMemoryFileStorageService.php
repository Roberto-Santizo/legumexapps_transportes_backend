<?php

namespace Tests\Doubles;

use App\Errors\BadRequestError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use Illuminate\Support\Str;
use Override;

/**
 * A storage contract implementation that never touches S3 nor a disk.
 *
 * It exists to prove the substitutability the spec asks for: the domain suite
 * has to pass with this bound in the container, without a single domain test
 * being modified.
 */
class InMemoryFileStorageService implements FileStorageServiceInterface
{
    /**
     * Stored contents, keyed by the key handed back to the caller.
     *
     * @var array<string, string>
     */
    private array $files = [];

    /**
     * @param  bool  $failing  Makes store() reject everything, as a bucket that is down would.
     */
    public function __construct(private readonly bool $failing = false) {}

    #[Override]
    public function store(string $contents, string $directory, string $extension): string
    {
        if ($this->failing) {
            throw new BadRequestError('No se pudo almacenar la imagen');
        }

        $key = $directory.'/'.Str::uuid().'.'.$extension;

        $this->files[$key] = $contents;

        return $key;
    }

    #[Override]
    public function delete(?string $key): bool
    {
        if ($key === null || ! array_key_exists($key, $this->files)) {
            return false;
        }

        unset($this->files[$key]);

        return true;
    }

    #[Override]
    public function url(?string $key): ?string
    {
        return $key === null ? null : 'https://doble.test/'.$key;
    }

    /**
     * Keys currently held, so a test can assert what was uploaded.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->files);
    }
}
