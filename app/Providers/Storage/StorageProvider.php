<?php

namespace App\Providers\Storage;

use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\Storage\ImageProcessorServiceInterface;
use App\Services\Storage\ImageProcessorService;
use App\Services\Storage\S3FileStorageService;
use Illuminate\Support\ServiceProvider;

class StorageProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FileStorageServiceInterface::class, S3FileStorageService::class);
        $this->app->bind(ImageProcessorServiceInterface::class, ImageProcessorService::class);
    }

    public function boot(): void
    {
        //
    }
}
