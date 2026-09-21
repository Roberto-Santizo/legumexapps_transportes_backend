<?php

use App\Interfaces\Report\SpreadsheetWriterInterface;
use App\Services\Report\OpenSpoutSpreadsheetWriter;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Read every row of a sheet the writer produced, as plain arrays of cell values.
 *
 * @return list<list<mixed>>
 */
function readSheet(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'sheet');
    file_put_contents($path, $contents);

    $reader = new Reader;
    $reader->open($path);

    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    return $rows;
}

it('está bindeado en el contenedor como implementación del contrato', function () {
    expect(app(SpreadsheetWriterInterface::class))->toBeInstanceOf(OpenSpoutSpreadsheetWriter::class);
});

it('escribe la cabecera y las filas conservando el tipo de cada celda', function () {
    $contents = new OpenSpoutSpreadsheetWriter()->write(
        ['Id', 'Nombre', 'Monto'],
        [
            [1, 'Viaje uno', 12.5],
            [2, null, 0.0],
        ],
    );

    expect(str_starts_with($contents, 'PK'))->toBeTrue()
        ->and(readSheet($contents))->toBe([
            ['Id', 'Nombre', 'Monto'],
            [1, 'Viaje uno', 12.5],
            [2, '', 0],
        ]);
});

it('produce un documento con solo la cabecera cuando no hay filas', function () {
    $contents = new OpenSpoutSpreadsheetWriter()->write(['Id', 'Nombre'], []);

    expect(readSheet($contents))->toBe([['Id', 'Nombre']]);
});

it('acepta cualquier iterable como origen de filas', function () {
    $rows = (function (): Generator {
        yield ['a', 1];
        yield ['b', 2];
    })();

    expect(readSheet(new OpenSpoutSpreadsheetWriter()->write(['Letra', 'Número'], $rows)))->toHaveCount(3);
});
