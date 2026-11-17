<?php

namespace App\Services\Report;

use App\Errors\BadRequestError;
use App\Interfaces\Report\SpreadsheetWriterInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
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
 *
 * The header row is styled —dark fill, white bold text, centered, bordered and taller—,
 * stays frozen while scrolling and carries an autofilter over the whole table; every
 * column is sized to its longest value, within `MIN_COLUMN_WIDTH`/`MAX_COLUMN_WIDTH`.
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

    /**
     * Fill of the header row (dark blue).
     */
    private const string HEADER_BACKGROUND = '1F4E78';

    /**
     * Color of the border around every header cell.
     */
    private const string HEADER_BORDER = 'BFBFBF';

    /**
     * Height of the header row, in points.
     */
    private const float HEADER_HEIGHT = 30.0;

    /**
     * Narrowest and widest a column gets, in characters.
     */
    private const float MIN_COLUMN_WIDTH = 10.0;

    private const float MAX_COLUMN_WIDTH = 50.0;

    /**
     * Characters added to the longest value of a column, so the text and the filter
     * arrow of the header do not touch the edge.
     */
    private const float COLUMN_PADDING = 4.0;

    #[Override]
    public function write(array $headers, iterable $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), self::TEMP_PREFIX);

        if ($path === false) {
            throw new BadRequestError(self::WRITE_ERROR_MESSAGE);
        }

        /** The widths need every value up front; the callers already hand over arrays. */
        $rows = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);

        try {
            $writer = new Writer;
            $writer->openToFile($path);

            $sheet = $writer->getCurrentSheet();
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            if ($headers !== []) {
                $sheet->setAutoFilter(new AutoFilter(0, 1, count($headers) - 1, count($rows) + 1));
            }

            foreach ($this->columnWidths($headers, $rows) as $column => $width) {
                $sheet->setColumnWidth($width, $column);
            }

            $writer->addRow(Row::fromValuesWithStyle($headers, self::headerStyle(), self::HEADER_HEIGHT));

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

    /**
     * Style shared by every header cell.
     */
    private static function headerStyle(): Style
    {
        $border = new Border(...array_map(
            static fn (BorderName $name): BorderPart => new BorderPart($name, self::HEADER_BORDER, BorderWidth::THIN),
            BorderName::cases(),
        ));

        /** Same font as the data rows (the writer's fallback), so the sheet reads as one. */
        return new Style(
            fontBold: true,
            fontSize: Options::DEFAULT_FONT_SIZE,
            fontColor: Color::WHITE,
            fontName: Options::DEFAULT_FONT_NAME,
            cellAlignment: CellAlignment::CENTER,
            cellVerticalAlignment: CellVerticalAlignment::CENTER,
            shouldWrapText: true,
            border: $border,
            backgroundColor: self::HEADER_BACKGROUND,
        );
    }

    /**
     * Width of each column, keyed by its 1-based index: the longest header or value
     * plus `COLUMN_PADDING`, clamped to `[MIN_COLUMN_WIDTH, MAX_COLUMN_WIDTH]`.
     *
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     * @return array<int, float>
     */
    private function columnWidths(array $headers, array $rows): array
    {
        $lengths = array_map(static fn (string $header): int => mb_strlen($header), array_values($headers));

        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $lengths[$index] = max($lengths[$index] ?? 0, mb_strlen((string) $value));
            }
        }

        $widths = [];

        foreach ($lengths as $index => $length) {
            $widths[$index + 1] = min(self::MAX_COLUMN_WIDTH, max(self::MIN_COLUMN_WIDTH, $length + self::COLUMN_PADDING));
        }

        return $widths;
    }
}
