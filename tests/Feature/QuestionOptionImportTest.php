<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QuestionOptionImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_location_specific_account_and_paid_to_by_choices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $transactions = "LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS\nMain Store,Local Bank,2026-10-01,,Supplier,Ask My Accountant,10,,Question,Central\nNorth Store,Local Bank,2026-10-02,,Supplier,Ask My Accountant,20,,Question,Central\n";

        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('transactions.csv', $transactions),
        ])->assertRedirect();

        $this->actingAs($manager)->get(route('admin.question-options.index'))->assertForbidden();
        $this->actingAs($manager)->postJson(route('admin.question-options.store'))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.question-options.index'))
            ->assertOk()
            ->assertSee('Accounts &amp; payees', false)
            ->assertSee('Main Store')
            ->assertSee('North Store')
            ->assertSee('ACCOUNT, PAID TO/BY');

        $choices = "ACCOUNT,PAID TO/BY\nOffice Supplies,Preferred Supplier\nTravel Expense,Local Vendor\n";
        $this->postJson(route('admin.question-options.store'), [
            'file' => UploadedFile::fake()->createWithContent('choices.csv', $choices),
        ])->assertUnprocessable()->assertJsonValidationErrors('locations');

        $this->postJson(route('admin.question-options.store'), [
            'locations' => ['Main Store'],
            'file' => UploadedFile::fake()->createWithContent('choices.csv', $choices),
        ])->assertOk()
            ->assertJsonPath('redirect', route('admin.question-options.index'));

        $this->assertDatabaseHas('accounts', ['account' => 'Office Supplies']);
        $this->assertDatabaseHas('location_question_options', ['location' => 'Main Store', 'type' => 'account', 'value' => 'Travel Expense']);
        $this->assertDatabaseHas('location_question_options', ['location' => 'Main Store', 'type' => 'paid_to_by', 'value' => 'Preferred Supplier']);
        $this->assertDatabaseMissing('location_question_options', ['location' => 'North Store', 'value' => 'Office Supplies']);

        $this->get(route('admin.questions.index', ['location' => 'Main Store']))
            ->assertOk()
            ->assertSee('Office Supplies')
            ->assertSee('Preferred Supplier');
        $this->get(route('admin.questions.index', ['location' => 'North Store']))
            ->assertOk()
            ->assertDontSee('Office Supplies')
            ->assertDontSee('Preferred Supplier');

        $mainEntry = DB::table('accounting_entries')->where('location', 'Main Store')->value('id');
        $northEntry = DB::table('accounting_entries')->where('location', 'North Store')->value('id');
        $this->postJson(route('admin.questions.account', $mainEntry), ['account' => 'Office Supplies'])->assertOk();
        $this->postJson(route('admin.questions.paid-to-by', $mainEntry), ['paid_to_by' => 'Preferred Supplier'])->assertOk();
        $this->postJson(route('admin.questions.account', $northEntry), ['account' => 'Office Supplies'])
            ->assertUnprocessable()->assertJsonValidationErrors('account');

        $this->postJson(route('admin.question-options.store'), [
            'locations' => ['Main Store', 'North Store'],
            'file' => UploadedFile::fake()->createWithContent('choices.csv', $choices),
        ])->assertOk();
        $this->assertSame(8, DB::table('location_question_options')->count());

        $accountOption = DB::table('location_question_options')
            ->where('location', 'Main Store')
            ->where('type', 'account')
            ->where('value', 'Office Supplies')
            ->value('id');
        $payeeOption = DB::table('location_question_options')
            ->where('location', 'Main Store')
            ->where('type', 'paid_to_by')
            ->where('value', 'Preferred Supplier')
            ->value('id');

        $this->actingAs($manager)->patchJson(route('admin.question-options.update', $accountOption), [
            'value' => 'Technology Expense',
        ])->assertForbidden();
        $this->actingAs($manager)->deleteJson(route('admin.question-options.destroy', $payeeOption))->assertForbidden();

        $this->actingAs($admin)->patchJson(route('admin.question-options.update', $accountOption), [
            'value' => 'Travel Expense',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->patchJson(route('admin.question-options.update', $accountOption), [
            'value' => 'Technology Expense',
        ])->assertOk()->assertJsonPath('redirect', route('admin.question-options.index'));

        $this->assertDatabaseMissing('location_question_options', ['id' => $accountOption, 'value' => 'Office Supplies']);
        $this->assertDatabaseHas('location_question_options', ['id' => $accountOption, 'location' => 'Main Store', 'type' => 'account', 'value' => 'Technology Expense']);
        $this->assertDatabaseHas('accounts', ['account' => 'Technology Expense']);
        $this->assertDatabaseHas('location_question_options', ['location' => 'North Store', 'type' => 'account', 'value' => 'Office Supplies']);

        $this->deleteJson(route('admin.question-options.destroy', $payeeOption))
            ->assertOk()
            ->assertJsonPath('redirect', route('admin.question-options.index'));
        $this->assertDatabaseMissing('location_question_options', ['id' => $payeeOption]);
        $this->assertDatabaseHas('location_question_options', ['location' => 'North Store', 'type' => 'paid_to_by', 'value' => 'Preferred Supplier']);
        $this->assertSame(7, DB::table('location_question_options')->count());

        $this->postJson(route('admin.question-options.store'), [
            'locations' => ['Main Store'],
            'file' => UploadedFile::fake()->createWithContent('invalid.csv', "ACCOUNT,WRONG\nOffice Supplies,Vendor\n"),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(7, DB::table('location_question_options')->count());
    }

    public function test_admin_can_upload_excel_question_choices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $transactions = "LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS\nMain Store,Local Bank,2026-10-01,,Supplier,Ask My Accountant,10,,Question,Central\n";
        $this->actingAs($admin)->post(route('admin.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('transactions.csv', $transactions),
        ]);
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->fromArray([
            ['ACCOUNT', 'PAID TO/BY'],
            ['Office Supplies', 'Preferred Supplier'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'question-options-');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        try {
            $this->postJson(route('admin.question-options.store'), [
                'locations' => ['Main Store'],
                'file' => new UploadedFile($path, 'choices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertOk();
        } finally {
            unlink($path);
        }

        $this->assertDatabaseHas('location_question_options', [
            'location' => 'Main Store',
            'type' => 'paid_to_by',
            'value' => 'Preferred Supplier',
        ]);
    }
}
