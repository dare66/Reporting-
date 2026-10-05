<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Organisation;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Files as people really have them: any name and headings, separators and
 * encodings from different programs, title rows above the table, totals at the
 * bottom, numbers with currency signs and dates in local formats. Each must load
 * with sensible column names and types. Commits for real and cleans up.
 */
class RealWorldFilesTest extends TestCase
{
    private const NAMES = ['Customer Orders (upload)', 'Vendas 2025 (upload)', 'Hr Export (upload)', 'Branch Performance (upload)'];

    protected function setUp(): void
    {
        parent::setUp();
        if (! TenantScopeBypass::run(fn () => Organisation::where('slug', 'emgs')->exists())) {
            Artisan::call('migrate:fresh', ['--seed' => true]);
        }
    }

    protected function tearDown(): void
    {
        TenantScopeBypass::run(function () {
            $sources = DataSource::withoutGlobalScope('project')->whereIn('name', self::NAMES)->get();
            foreach (Dataset::withoutGlobalScope('project')->whereIn('data_source_id', $sources->pluck('id'))->get() as $d) {
                DB::statement('DROP TABLE IF EXISTS analytics."'.$d->physical_table.'"');
                $d->delete();
            }
            $sources->each(fn ($s) => $s->delete());
        });
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function upload(string $filename, string $content): array
    {
        return $this->as('engineer@emgs.demo')->post('/api/v1/data/upload', ['file' => UploadedFile::fake()->createWithContent($filename, $content)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
    }

    /** @param  array<string, mixed>  $dataset */
    private function types(array $dataset): array
    {
        return collect($dataset['fields'])->pluck('data_type', 'name')->sortKeys()->all();
    }

    public function test_csv_with_formatted_numbers_and_day_first_dates(): void
    {
        $csv = "Order No.,Customer Name,Region / Area,Order Date,Total (RM),Qty,Status\n";
        foreach (range(1, 40) as $i) {
            $csv .= "ORD-{$i},Customer {$i},North,".sprintf('%02d/%02d/2024', ($i % 28) + 1, ($i % 12) + 1).',"RM '.number_format(1000 + $i * 37.5, 2).'",'.($i % 7 + 1).",Paid\n";
        }
        $csv .= "Total,,,,\"RM 99,999.00\",,\n";
        $d = $this->upload('Customer Orders.csv', $csv)['dataset'];
        $this->assertSame(['customer_name' => 'string', 'order_date' => 'date', 'order_no' => 'string', 'qty' => 'integer', 'region_area' => 'string', 'status' => 'string', 'total_rm' => 'decimal'], $this->types($d));
        $this->assertSame(40, $d['row_count'], 'the total row is not data');
        $row = DB::connection('analytics')->table('analytics.'.$d['physical_table'])->where('order_no', 'ORD-13')->first();
        $this->assertSame('2024-02-14', (string) $row->order_date, 'day first: 14/02/2024');
        $this->assertEqualsWithDelta(1487.5, (float) $row->total_rm, 0.001);

        // The same file again is a new version of the same source, not a second source.
        $again = $this->upload('Customer Orders.csv', $csv);
        $this->assertSame($d['data_source_id'], $again['source']['id']);
        $this->assertSame($d['id'], $again['dataset']['id']);
        $this->assertSame(1, TenantScopeBypass::run(fn () => DataSource::withoutGlobalScope('project')->where('name', 'Customer Orders (upload)')->count()));
    }

    public function test_windows_csv_with_semicolons_and_month_first_dates(): void
    {
        $lines = ['Kód;Café Name;Valor €;Data'];
        foreach (range(1, 30) as $i) {
            $lines[] = "K{$i};Café {$i};€ {$i}0.50;".sprintf('%02d/%02d/2025', 1, $i);  // 01/13/2025 can only be month first
        }
        $d = $this->upload('vendas 2025.csv', (string) mb_convert_encoding(implode("\r\n", $lines), 'Windows-1252', 'UTF-8'))['dataset'];
        $this->assertSame(['cafe_name' => 'string', 'data' => 'date', 'kod' => 'string', 'valor_eur' => 'decimal'], $this->types($d));
        $row = DB::connection('analytics')->table('analytics.'.$d['physical_table'])->where('kod', 'K13')->first();
        $this->assertSame(['Café 13', '2025-01-13', 130.5], [$row->cafe_name, (string) $row->data, (float) $row->valor_eur]);
    }

    public function test_unicode_tab_text_from_excel(): void
    {
        $lines = ["Staff ID\tDepartment\tJoin Date\tSalary\tAttendance %"];
        foreach (range(1, 25) as $i) {
            $lines[] = "S{$i}\tOps\t".sprintf('%d Mar 2019', $i)."\tRM".number_format(3000 + $i * 100)."\t".(70 + $i).'%';
        }
        $d = $this->upload('HR export.txt', "\xFF\xFE".mb_convert_encoding(implode("\r\n", $lines), 'UTF-16LE', 'UTF-8'))['dataset'];
        $this->assertSame(['attendance' => 'decimal', 'department' => 'string', 'join_date' => 'date', 'salary' => 'integer', 'staff_id' => 'string'], $this->types($d));
        $row = DB::connection('analytics')->table('analytics.'.$d['physical_table'])->where('staff_id', 'S5')->first();
        $this->assertSame(['2019-03-05', 3500, 0.75], [(string) $row->join_date, (int) $row->salary, (float) $row->attendance]);
    }

    public function test_workbook_with_title_rows_repeated_headings_and_totals(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('Monthly Sales');
        $s->setCellValue('A1', 'ACME Sdn Bhd — Sales Report');
        $s->setCellValue('A2', 'Generated 1 Oct 2025');
        $s->fromArray(['Branch', 'Product', 'Product', 'Units Sold', 'Revenue (RM)', 'Sale Date'], null, 'A4');
        foreach (range(1, 60) as $i) {
            $s->fromArray([['KL', 'Penang', 'JB'][$i % 3], "P{$i}", 'Variant '.($i % 2), $i, $i * 10.5], null, 'A'.($i + 4));
            $s->setCellValue('F'.($i + 4), ExcelDate::PHPToExcel(mktime(0, 0, 0, 1, $i, 2025)));
            $s->getStyle('F'.($i + 4))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        }
        $s->fromArray(['Grand Total', null, null, 1830], null, 'A65');
        $book->createSheet()->setTitle('Empty');
        $path = tempnam(sys_get_temp_dir(), 'aixbi').'.xlsx';
        (new Xlsx($book))->save($path);

        $up = $this->as('engineer@emgs.demo')->post('/api/v1/data/upload', ['file' => new UploadedFile($path, 'Branch Performance.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $this->assertCount(1, $up['datasets'], 'the empty sheet is skipped');
        $d = $up['datasets'][0];
        $this->assertSame(['branch' => 'string', 'product' => 'string', 'product_2' => 'string', 'revenue_rm' => 'decimal', 'sale_date' => 'date', 'units_sold' => 'integer'], $this->types($d));
        $this->assertSame(60, $d['row_count']);
        $this->assertSame(1830, (int) DB::connection('analytics')->table('analytics.'.$d['physical_table'])->sum('units_sold'), 'not counted twice');
    }

    public function test_files_that_cannot_be_used_say_why_and_leave_nothing_behind(): void
    {
        $before = TenantScopeBypass::run(fn () => DataSource::withoutGlobalScope('project')->count());
        $this->as('engineer@emgs.demo')->post('/api/v1/data/upload', ['file' => UploadedFile::fake()->createWithContent('template.csv', "a,b,c\n")], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'The file has no rows of data under its headings.');
        $this->as('engineer@emgs.demo')->post('/api/v1/data/upload', ['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not a workbook')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'This file could not be read. It may be damaged, password-protected, or not really a XLSX file.');
        $this->as('engineer@emgs.demo')->post('/api/v1/data/upload', ['file' => UploadedFile::fake()->createWithContent('photo.png', 'x')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Upload a CSV, TSV, Excel (.xlsx or .xls) or JSON file.');
        $this->assertSame($before, TenantScopeBypass::run(fn () => DataSource::withoutGlobalScope('project')->count()));
    }
}
