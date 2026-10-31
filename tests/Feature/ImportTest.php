<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS';

    public function test_only_admin_can_import_csv_and_duplicate_files_are_rejected(): void
    {
        $file = self::HEADER."\nMain Store,Local Bank,2026-10-01,,Supplier,Ask My Accountant,123.45,,Supplies,Central\n";
        $manager = User::factory()->create();
        $this->actingAs($manager)->postJson('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('ledger.csv', $file),
        ])->assertForbidden();
        $this->assertDatabaseCount('imports', 0);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('ledger.csv', $file),
        ])->assertOk()->assertJsonPath('message', '1 rows imported.');
        $importId = DB::table('imports')->value('id');
        $this->assertDatabaseHas('accounting_entries', [
            'location' => 'Main Store', 'transaction_date' => '2026-10-01', 'credit' => '123.45', 'debit' => null,
        ]);
        $this->get(route('admin.imports.export', $importId))->assertDownload('ledger-export.csv');

        $this->postJson('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('ledger.csv', $file),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('accounting_entries', 1);
    }

    public function test_excel_sheets_and_money_rounding_are_imported(): void
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setTitle('TRANSFERS');
        $workbook->getActiveSheet()->fromArray([
            explode(',', self::HEADER),
            ['Main Store', 'Local Bank', Date::PHPToExcel(new \DateTimeImmutable('2026-10-01')), '', 'Supplier', 'Ask My Accountant', '', 2617.4499999999998, 'Payment', 'Central'],
        ]);
        $workbook->createSheet()->setTitle('REST OTHERS')->fromArray([
            explode(',', self::HEADER),
            ['North Store', 'Other Bank', Date::PHPToExcel(new \DateTimeImmutable('2026-10-02')), '1001', 'Customer', 'Ask My Accountant', 0, '', 'Transfer', 'North'],
        ]);
        $workbook->getSheetByName('REST OTHERS')->setCellValue('G2', 0);
        $path = tempnam(sys_get_temp_dir(), 'questions-import-');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $admin = User::factory()->create(['role' => 'admin']);
            $this->actingAs($admin)->postJson('/admin/imports', [
                'file' => new UploadedFile($path, 'ledger.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertOk()->assertJsonPath('message', '2 rows imported.');
        } finally {
            unlink($path);
        }

        $this->assertDatabaseHas('accounting_entries', [
            'sheet_name' => 'TRANSFERS', 'transaction_date' => '2026-10-01', 'debit' => '2617.45',
        ]);
        $this->assertDatabaseHas('accounting_entries', [
            'sheet_name' => 'REST OTHERS', 'transaction_date' => '2026-10-02', 'credit' => '0.00',
        ]);
        $this->assertSame(2, DB::table('imports')->value('row_count'));
        $this->get(route('admin.imports.export', DB::table('imports')->value('id')))->assertDownload('ledger-export.xlsx');

        DB::table('accounting_entries')->where('location', 'Main Store')->update([
            'answer' => "Approved for payment.\n\nFollow-up note.",
            'answered_by' => $admin->id,
            'answered_at' => now(),
        ]);
        $response = $this->get(route('admin.questions.export-answered'))->assertDownload('answered-questions.xlsx');
        $export = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $export->getActiveSheet();
        $this->assertSame('LOCATION', $sheet->getCell('A1')->getValue());
        $this->assertSame('ANSWER', $sheet->getCell('K1')->getValue());
        $this->assertSame('ANSWERED BY', $sheet->getCell('L1')->getValue());
        $this->assertSame('Main Store', $sheet->getCell('A2')->getValue());
        $this->assertSame("Approved for payment.\nFollow-up note.", $sheet->getCell('K2')->getValue());
        $this->assertTrue($sheet->getStyle('K2')->getAlignment()->getWrapText());
        $this->assertSame($admin->name, $sheet->getCell('L2')->getValue());
        $this->assertSame(2, $sheet->getHighestDataRow());
        $export->disconnectWorksheets();
    }

    public function test_invalid_rows_do_not_partially_import(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = self::HEADER."\nMain Store,Local Bank,2026-10-01,,Supplier,Ask My Accountant,123.45,,Supplies,Central\n".
            "North Store,Local Bank,2026-10-02,,Supplier,Ask My Accountant,10,20,Supplies,North\n";

        $this->actingAs($admin)->postJson('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('invalid.csv', $file),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_admin_can_view_uploaded_files_and_each_files_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = self::HEADER."\nMain Store,Local Bank,2026-10-01,,Supplier,Ask My Accountant,123.45,,Supplies,Central\n";

        $this->actingAs($admin)->post('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('ledger.csv', $file),
        ])->assertRedirect();

        $importId = DB::table('imports')->value('id');
        $dataTableParameters = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'search' => ['value' => 'ledger'],
            'order' => [['column' => 2, 'dir' => 'desc']],
        ]);

        $this->get(route('admin.imports.index'))
            ->assertOk()
            ->assertSee(route('admin.imports.data'));
        $this->getJson(route('admin.imports.data').'?'.$dataTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.file_name', 'ledger.csv');
        $this->get(route('admin.imports.show', $importId))
            ->assertOk()
            ->assertSee(route('admin.imports.show-data', $importId));
        $this->getJson(route('admin.imports.show-data', $importId).'?'.str_replace('ledger', 'Supplies', $dataTableParameters))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.description', 'Supplies')
            ->assertJsonPath('data.0.credit', 123.45);
        $this->get(route('admin.imports.show', 999999))->assertNotFound();
        $this->getJson(route('admin.imports.show-data', 999999).'?'.$dataTableParameters)->assertNotFound();
    }
}
