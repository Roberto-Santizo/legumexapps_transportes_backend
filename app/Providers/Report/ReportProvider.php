<?php

namespace App\Providers\Report;

use App\Interfaces\Report\ReportServiceInterface;
use App\Interfaces\Report\SpreadsheetWriterInterface;
use App\Services\Report\OpenSpoutSpreadsheetWriter;
use App\Services\Report\ReportService;
use Illuminate\Support\ServiceProvider;

class ReportProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportServiceInterface::class, ReportService::class);
        $this->app->bind(SpreadsheetWriterInterface::class, OpenSpoutSpreadsheetWriter::class);
    }

    public function boot(): void
    {
        //
    }
}
