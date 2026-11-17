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

/**
 * The raw XML of one part of the document the writer produced.
 */
function sheetPart(string $contents, string $part): string
{
    $path = tempnam(sys_get_temp_dir(), 'sheet');
    file_put_contents($path, $contents);

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName($part);
    $zip->close();
    unlink($path);

    return $xml;
}

it('congela la cabecera, le pone autofiltro sobre toda la tabla y ajusta el ancho de cada columna', function () {
    $contents = new OpenSpoutSpreadsheetWriter()->write(
        ['Id', 'Descripción'],
        [[1, str_repeat('x', 30)], [2, str_repeat('y', 200)]],
    );

    $sheet = sheetPart($contents, 'xl/worksheets/sheet1.xml');

    expect($sheet)->toContain('ySplit="1"')
        ->and($sheet)->toContain('<autoFilter ref="A1:B3"')
        ->and($sheet)->toContain('<col min="1" max="1" width="10"')
        ->and($sheet)->toContain('<col min="2" max="2" width="50"')
        ->and($sheet)->toMatch('/<row r="1"[^>]*ht="30"[^>]*customHeight="1"/');
});

it('da a la cabecera relleno oscuro, texto blanco en negrita, centrado y con borde', function () {
    $styles = sheetPart(new OpenSpoutSpreadsheetWriter()->write(['Id'], []), 'xl/styles.xml');

    expect($styles)->toContain('<fgColor rgb="1F4E78"/>')
        ->and($styles)->toContain('<name val="Calibri"/><b/>')
        ->and($styles)->toContain('<b/>')
        ->and($styles)->toContain('FFFFFFFF')
        ->and($styles)->toContain('horizontal="center"')
        ->and($styles)->toContain('<left style="thin"');
});
