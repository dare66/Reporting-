<?php

namespace Tests\Unit;

use App\Domain\Data\Connectors;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class ExcelIngestionTest extends TestCase
{
    public function test_reads_values_and_cached_formula_results(): void
    {
        $rows = (new Connectors)->readFile($this->workbook([
            ['region', 'units', 'doubled'],
            ['North', 4, '=B2*2'],
            ['South', 7, '=B3*2'],
            [null, null, null],
        ], precalculate: true));

        $this->assertSame([
            ['region' => 'North', 'units' => 4, 'doubled' => 8],
            ['region' => 'South', 'units' => 7, 'doubled' => 14],
        ], $rows);
    }

    public function test_never_evaluates_formulas_from_uploaded_files(): void
    {
        $rows = (new Connectors)->readFile($this->workbook([
            ['name', 'remote'],
            ['probe', '=WEBSERVICE("http://169.254.169.254/latest/meta-data/")'],
        ], precalculate: false));

        $this->assertSame([['name' => 'probe', 'remote' => null]], $rows);
    }

    /** @param list<list<mixed>> $cells */
    private function workbook(array $cells, bool $precalculate): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($cells, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new Xlsx($book))->setPreCalculateFormulas($precalculate)->save($path);

        return new UploadedFile($path, 'book.xlsx', null, null, true);
    }
}
