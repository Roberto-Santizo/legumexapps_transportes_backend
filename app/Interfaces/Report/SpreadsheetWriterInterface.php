<?php

namespace App\Interfaces\Report;

use App\Errors\BadRequestError;

interface SpreadsheetWriterInterface
{
    /**
     * Build a single-sheet spreadsheet and return its bytes.
     *
     * The first row is always the header, even when there are no data rows, so the
     * resulting file is never empty. Cells keep the PHP type they arrive with: an
     * int or float becomes a numeric cell Excel can sum, a string stays text, and
     * `null` leaves the cell blank. The caller decides the extension of the key the
     * bytes are stored under; the implementation only guarantees they are a valid
     * `.xlsx` document.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     * @return string The spreadsheet bytes, ready to be persisted.
     *
     * @throws BadRequestError when the document cannot be written.
     */
    public function write(array $headers, iterable $rows): string;
}
