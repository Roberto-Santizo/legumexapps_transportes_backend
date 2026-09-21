<?php

namespace App\Services\Report;

use App\Errors\BadRequestError;
use App\Interfaces\Report\SpreadsheetWriterInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Override;
use Throwable;

/**
 * The only file that knows the spreadsheet is produced by OpenSpout.
 *
 * OpenSpout writes through `ZipArchive`, which needs a real path, so the document is
 * assembled in a temporary file that is read back and removed before returning: the
 * caller receives bytes and never a path, like `ImageProcessorService` returns bytes
 * and never an image object.
 */
final class OpenSpoutSpreadsheetWriter implements SpreadsheetWriterInterface
{
    /**
     * Prefix of the temporary file the document is assembled in.
     */
    private const string TEMP_PREFIX = 'report';

    /**
     * Message returned to the client whenever the document cannot be written.
     */
    private const string WRITE_ERROR_MESSAGE = 'No se pudo generar el reporte';

    #[Override]
    public function write(array $headers, iterable $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), self::TEMP_PREFIX);

        if ($path === false) {
            throw new BadRequestError(self::WRITE_ERROR_MESSAGE);
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValuesWithStyle($headers, new Style(fontBold: true)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();

            $contents = file_get_contents($path);
        } catch (Throwable) {
            throw new BadRequestError(self::WRITE_ERROR_MESSAGE);
        } finally {
            @unlink($path);
        }

        if ($contents === false) {
            throw new BadRequestError(self::WRITE_ERROR_MESSAGE);
        }

        return $contents;
    }
}
