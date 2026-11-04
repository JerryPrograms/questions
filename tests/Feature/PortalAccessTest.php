<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/manager')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in to your account');
    }

    public function test_ajax_login_uses_the_correct_role_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);

        $this->loginWithTwoFactor($admin)
            ->assertOk()->assertJsonPath('redirect', route('admin.imports.create'));
        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Review the number of imported questions')
            ->assertSee('Toggle navigation');
        $this->postJson('/logout')->assertOk()->assertJsonPath('redirect', route('login'));

        $this->loginWithTwoFactor($manager)
            ->assertOk()->assertJsonPath('redirect', route('manager.dashboard'));
        $this->get('/dashboard')->assertRedirect(route('manager.dashboard'));
        $this->get('/manager')
            ->assertOk()
            ->assertSee('Review the number of imported questions')
            ->assertSee(route('manager.dashboard.data'));
        $this->get('/admin')->assertForbidden();
        $this->get(route('admin.manager-locations.index'))->assertForbidden();
        $this->getJson(route('admin.manager-locations.data'))->assertForbidden();
        $this->get(route('admin.questions.export-answered'))->assertForbidden();
        $this->get(route('admin.questions.index', ['location' => 'Main Store']))->assertForbidden();
    }

    public function test_manager_sees_and_answers_questions_for_assigned_locations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $file = "LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS\nMain Store,Local Bank,2026-10-01,,Supplier,Expenses,10,,Supplies,Central\nNorth Store,Local Bank,2026-10-02,,Supplier,Expenses,20,,Supplies,Central\n";

        $this->actingAs($admin)->post('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('locations.csv', $file),
        ])->assertRedirect();
        DB::table('accounts')->insert([
            ['account' => 'Office Supplies', 'created_at' => now(), 'updated_at' => now()],
            ['account' => 'Travel Expense', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('location_question_options')->insert([
            ['location' => 'Main Store', 'type' => 'account', 'value' => 'Office Supplies', 'created_at' => now(), 'updated_at' => now()],
            ['location' => 'Main Store', 'type' => 'account', 'value' => 'Travel Expense', 'created_at' => now(), 'updated_at' => now()],
            ['location' => 'Main Store', 'type' => 'paid_to_by', 'value' => 'Preferred Supplier', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $dataTableParameters = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'search' => ['value' => 'Main Store'],
            'order' => [['column' => 0, 'dir' => 'asc']],
        ]);
        $questionTableParameters = str_replace('order%5B0%5D%5Bcolumn%5D=0', 'order%5B0%5D%5Bcolumn%5D=1', $dataTableParameters);
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.dashboard.data'))
            ->assertSee(route('admin.questions.index'))
            ->assertSee('data-stat-value="locations">2', false)
            ->assertSee('data-stat-value="questions">2', false)
            ->assertSee('data-stat-value="answered">0', false)
            ->assertSee('data-stat-value="pending">2', false);
        $this->getJson(route('admin.dashboard.data').'?'.$questionTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.location', 'Main Store')
            ->assertJsonPath('data.0.total_questions', 1)
            ->assertJsonPath('data.0.answered_questions', 0)
            ->assertJsonPath('data.0.pending_questions', 1);
        $this->get(route('admin.questions.index', ['location' => 'Main Store']))
            ->assertOk()
            ->assertSee('Main Store')
            ->assertSee('Amount')
            ->assertSee('Detail')
            ->assertSee('answer-history-modal')
            ->assertSee('data-question-status-legend', false)
            ->assertSee('Answer selected questions')
            ->assertSee('Office Supplies')
            ->assertSee('Travel Expense')
            ->assertSee('Preferred Supplier')
            ->assertSee(route('admin.questions.account', '__id__'))
            ->assertSee(route('admin.questions.paid-to-by', '__id__'))
            ->assertSee(route('admin.questions.bulk-answer'))
            ->assertSee('data-bulk-answer-form hidden', false)
            ->assertSee(route('admin.questions.export-answered'))
            ->assertSee(route('admin.questions.data', ['locations' => ['Main Store']]));
        $this->get(route('admin.questions.index', ['locations' => ['Main Store', 'North Store']]))
            ->assertOk()
            ->assertSee('2 locations')
            ->assertSee('North Store')
            ->assertSee('data-location-filter="Main Store"', false);
        $this->getJson(route('admin.questions.data', ['locations' => ['Main Store', 'North Store']]).'&'.$questionTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.location', 'Main Store');
        $locationFilterParameters = str_replace('Main+Store', 'Supplies', $questionTableParameters).'&location_filter=North%20Store';
        $this->getJson(route('admin.questions.data', ['locations' => ['Main Store', 'North Store']]).'&'.$locationFilterParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.location', 'North Store');
        $this->getJson(route('admin.questions.data', ['location' => 'Main Store']).'&'.$questionTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.check_no', null)
            ->assertJsonPath('data.0.amount', 10)
            ->assertJsonPath('data.0.credit_debit', 'Credit')
            ->assertJsonPath('data.0.description', 'Supplies')
            ->assertJsonPath('data.0.answer', null);
        $this->getJson(route('admin.questions.data', ['location' => 'Main Store']).'&'.$questionTableParameters.'&status=pending')
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);
        $entryId = DB::table('accounting_entries')->where('location', 'Main Store')->value('id');
        $this->postJson(route('admin.questions.account', $entryId), ['account' => 'Unknown Account'])
            ->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->postJson(route('admin.questions.account', $entryId), ['account' => 'Office Supplies'])
            ->assertOk()->assertJsonPath('message', 'Account saved.');
        $this->postJson(route('admin.questions.paid-to-by', $entryId), ['paid_to_by' => 'Preferred Supplier'])
            ->assertOk()->assertJsonPath('message', 'Paid to/by saved.');
        $this->postJson(route('admin.questions.answer', $entryId), ['answer' => 'Approved for payment.'])
            ->assertOk()
            ->assertJsonPath('message', 'Answer saved.');
        $this->postJson(route('admin.questions.answer', $entryId), ['answer' => 'Follow-up note.'])
            ->assertOk()
            ->assertJsonPath('message', 'Answer saved.');
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $entryId,
            'answer' => "Approved for payment.\nFollow-up note.",
            'answered_by' => $admin->id,
            'account' => 'Office Supplies',
            'paid_to_by' => 'Preferred Supplier',
        ]);
        DB::table('accounting_entries')->where('id', $entryId)->update(['answer_exported_at' => now()]);
        $this->postJson(route('admin.questions.account', $entryId), ['account' => 'Travel Expense'])
            ->assertOk();
        $this->assertDatabaseHas('accounting_entries', ['id' => $entryId, 'account' => 'Travel Expense', 'answer_exported_at' => null]);
        $this->getJson(route('admin.dashboard.data').'?'.$questionTableParameters)
            ->assertOk()
            ->assertJsonPath('data.0.answered_questions', 1)
            ->assertJsonPath('data.0.pending_questions', 0);
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-stat-value="answered">1', false)
            ->assertSee('data-stat-value="pending">1', false);
        $this->getJson(route('admin.questions.data', ['location' => 'Main Store']).'&'.$questionTableParameters.'&status=pending')
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 0);
        $answeredQuestions = $this->getJson(route('admin.questions.data', ['location' => 'Main Store']).'&'.$questionTableParameters.'&status=answered')
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.answer', "Approved for payment.\nFollow-up note.")
            ->assertJsonPath('data.0.answered_by_name', $admin->name)
            ->assertJsonPath('data.0.answers.0.answer', 'Follow-up note.')
            ->assertJsonPath('data.0.answers.0.answered_by_name', $admin->name)
            ->assertJsonPath('data.0.answers.1.answer', 'Approved for payment.')
            ->assertJsonPath('data.0.answers.1.answered_by_name', $admin->name);
        $this->assertNotNull($answeredQuestions->json('data.0.answered_at'));
        $this->assertNotNull($answeredQuestions->json('data.0.answers.0.answered_at'));
        $this->assertNotNull($answeredQuestions->json('data.0.answers.1.answered_at'));
        $this->get(route('admin.manager-locations.index'))
            ->assertOk()
            ->assertSee($manager->name)
            ->assertSee('Choose a manager')
            ->assertSee('Select a manager');
        $this->get(route('admin.manager-locations.index', ['manager' => $manager->id]))
            ->assertOk()
            ->assertSee('Search locations')
            ->assertSee('Main Store');
        $this->post(route('admin.managers.locations.update', $manager), [
            'locations' => ['Main Store'],
        ])->assertRedirect(route('admin.manager-locations.index', ['manager' => $manager->id]));
        $this->assertDatabaseHas('manager_locations', [
            'user_id' => $manager->id,
            'location' => 'Main Store',
        ]);
        $this->getJson(route('admin.manager-locations.data').'?'.$dataTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', $manager->name)
            ->assertJsonPath('data.0.locations', ['Main Store']);

        $this->post('/logout');
        $this->loginWithTwoFactor($manager)
            ->assertOk()->assertJsonPath('redirect', route('manager.dashboard'));
        $this->get('/manager')
            ->assertOk()
            ->assertSee(route('manager.dashboard.data'))
            ->assertSee(route('manager.questions.index', ['all' => 1]));
        $this->getJson(route('manager.dashboard.data').'?'.$questionTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.location', 'Main Store');
        $this->get(route('manager.questions.index'))
            ->assertOk()
            ->assertSee(route('manager.questions.data'))
            ->assertSee(route('manager.questions.answer', '__id__'))
            ->assertSee(route('manager.questions.account', '__id__'))
            ->assertSee(route('manager.questions.paid-to-by', '__id__'))
            ->assertSee(route('manager.questions.bulk-answer'))
            ->assertDontSee(route('admin.questions.export-answered'));
        $this->getJson(route('manager.questions.data').'?'.$questionTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.location', 'Main Store');
        $northEntryId = DB::table('accounting_entries')->where('location', 'North Store')->value('id');
        $this->postJson(route('manager.questions.account', $northEntryId), ['account' => 'Office Supplies'])
            ->assertForbidden();
        $this->postJson(route('manager.questions.account', $entryId), ['account' => 'Office Supplies'])
            ->assertOk();
        $this->postJson(route('manager.questions.answer', $northEntryId), ['answer' => 'Wrong location'])
            ->assertForbidden();
        $this->postJson(route('manager.questions.answer', $entryId), ['answer' => 'Manager follow-up'])
            ->assertOk()->assertJsonPath('message', 'Answer saved.');
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $entryId,
            'answer' => "Approved for payment.\nFollow-up note.\nManager follow-up",
            'answered_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('accounting_entry_answers', [
            'accounting_entry_id' => $entryId,
            'answer' => 'Manager follow-up',
            'answered_by' => $manager->id,
        ]);

        $this->actingAs($admin)->post(route('admin.managers.locations.update', $manager), [
            'locations' => ['Main Store', 'North Store'],
        ]);
        $selectedLocationDataUrl = route('manager.questions.data', ['locations' => ['Main Store']]);
        $this->actingAs($manager)->get(route('manager.questions.index', ['location' => 'Main Store']))
            ->assertOk()
            ->assertSee($selectedLocationDataUrl);
        $this->getJson($selectedLocationDataUrl.'&'.$questionTableParameters)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.location', 'Main Store');

        $this->actingAs($admin)->post(route('admin.managers.locations.update', $manager));
        $this->actingAs($manager)->get('/manager')
            ->assertOk()
            ->assertSee('data-stat-value="locations">0', false);
        $this->assertDatabaseMissing('manager_locations', ['user_id' => $manager->id]);
    }

    public function test_only_admins_can_create_managers(): void
    {
        $manager = User::factory()->create();
        $data = [
            'name' => 'New Manager',
            'email' => 'manager@example.com',
            'password' => 'long-password-123',
            'password_confirmation' => 'long-password-123',
        ];

        $this->actingAs($manager)->postJson('/admin/managers', $data)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->postJson('/admin/managers', $data)->assertOk();

        $created = User::where('email', $data['email'])->firstOrFail();
        $response->assertJsonPath('redirect', route('admin.manager-locations.index', ['manager' => $created->id]));
        $this->assertSame('manager', $created->role);
        $this->assertTrue(Hash::check($data['password'], $created->password));
    }

    public function test_only_super_admins_can_create_admins(): void
    {
        $data = [
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'long-password-123',
            'password_confirmation' => 'long-password-123',
        ];

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('admin.admins.store'), $data)->assertForbidden();

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($superAdmin)->get(route('admin.manager-locations.index'))
            ->assertOk()
            ->assertSee(route('admin.admins.store'));
        $this->postJson(route('admin.admins.store'), $data)->assertOk();

        $created = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('admin', $created->role);
        $this->assertTrue(Hash::check($data['password'], $created->password));
    }

    public function test_super_admin_inherits_admin_access(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('manager.dashboard'))->assertForbidden();
    }

    public function test_admin_can_answer_selected_or_all_pending_questions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = "LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS\nMain Store,Local Bank,2026-10-01,,Supplier,Expenses,10,,First question,Central\nMain Store,Local Bank,2026-10-02,,Supplier,Expenses,20,,Second question,Central\nMain Store,Local Bank,2026-10-03,,Supplier,Expenses,30,,Third question,Central\nMain Store,Local Bank,2026-10-04,,Supplier,Expenses,40,,Fourth question,Central\n";

        $this->actingAs($admin)->post('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('bulk.csv', $file),
        ])->assertRedirect();
        $entryIds = DB::table('accounting_entries')->orderBy('id')->pluck('id');

        $this->postJson(route('admin.questions.bulk-answer'), [
            'location' => 'Main Store',
            'scope' => 'selected',
            'answer' => 'Selected answer',
            'entries' => $entryIds->take(2)->all(),
        ])->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame(2, DB::table('accounting_entries')->where('answer', 'Selected answer')->count());

        $this->postJson(route('admin.questions.bulk-answer'), [
            'location' => 'Main Store',
            'scope' => 'all_pending',
            'answer' => 'Remaining answer',
        ])->assertOk()->assertJsonPath('updated', 2);
        $this->postJson(route('admin.questions.bulk-answer'), [
            'location' => 'Main Store',
            'scope' => 'selected',
            'answer' => 'Bulk follow-up',
            'entries' => [$entryIds[3]],
        ])->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $entryIds[3],
            'answer' => "Remaining answer\nBulk follow-up",
        ]);
        $this->assertDatabaseMissing('accounting_entries', ['answered_at' => null]);
        $this->assertSame(4, DB::table('accounting_entries')->where('answered_by', $admin->id)->count());
        $this->assertSame(5, DB::table('accounting_entry_answers')->where('answered_by', $admin->id)->count());

        $this->postJson(route('admin.questions.archive', $entryIds[0]))
            ->assertConflict()
            ->assertJsonPath('warning', 'unexported_answer');
        $this->postJson(route('admin.questions.archive', $entryIds[0]), ['confirm_unexported' => true])
            ->assertOk()->assertJsonPath('message', 'Question archived.');
        $this->postJson(route('admin.questions.bulk-archive'), [
            'location' => 'Main Store',
            'scope' => 'selected',
            'entries' => [$entryIds[1], $entryIds[2]],
        ])->assertConflict()->assertJsonPath('unexported', 2);
        $this->postJson(route('admin.questions.bulk-archive'), [
            'location' => 'Main Store',
            'scope' => 'selected',
            'entries' => [$entryIds[1], $entryIds[2]],
            'confirm_unexported' => true,
        ])->assertOk()->assertJsonPath('updated', 2);
        $this->postJson(route('admin.questions.bulk-archive'), [
            'location' => 'Main Store',
            'scope' => 'all_answered',
        ])->assertConflict()->assertJsonPath('unexported', 1);
        $this->postJson(route('admin.questions.bulk-archive'), [
            'location' => 'Main Store',
            'scope' => 'all_answered',
            'confirm_unexported' => true,
        ])->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame(4, DB::table('accounting_entries')->where('archived_by', $admin->id)->whereNotNull('archived_at')->count());

        $questionTableParameters = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 50,
            'order' => [['column' => 1, 'dir' => 'asc']],
        ]);
        $this->getJson(route('admin.questions.data', ['location' => 'Main Store']).'&'.$questionTableParameters.'&status=all')
            ->assertOk()->assertJsonPath('recordsFiltered', 4);
        $this->getJson(route('admin.questions.data', ['location' => 'Main Store']).'&'.$questionTableParameters.'&status=archived')
            ->assertOk()->assertJsonPath('recordsFiltered', 4);

        $this->postJson(route('admin.questions.status', $entryIds[0]), ['status' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson(route('admin.questions.status', $entryIds[0]), ['status' => 'answered'])
            ->assertOk()->assertJsonPath('message', 'Question moved to Answered.');
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $entryIds[0],
            'answer' => 'Selected answer',
            'archived_at' => null,
        ]);
        $this->postJson(route('admin.questions.status', $entryIds[1]), ['status' => 'pending'])
            ->assertOk()->assertJsonPath('message', 'Question moved to Pending.');
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $entryIds[1],
            'answer' => 'Selected answer',
            'answered_by' => null,
            'answered_at' => null,
            'archived_at' => null,
        ]);
        $this->postJson(route('admin.questions.status', $entryIds[0]), ['status' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('question');
    }

    public function test_admin_can_upload_a_detail_file_named_after_the_question_id(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $file = "LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS\nMain Store,Local Bank,2026-10-01,,Supplier,Expenses,10,,Question,Central\n";

        $this->actingAs($admin)->post('/admin/imports', [
            'file' => UploadedFile::fake()->createWithContent('details.csv', $file),
        ])->assertRedirect();
        $entryId = DB::table('accounting_entries')->value('id');

        $this->postJson(route('admin.questions.detail', $entryId), [
            'detail' => UploadedFile::fake()->create($entryId.'.pdf', 10, 'application/pdf'),
        ])->assertOk()->assertJsonPath('file_name', $entryId.'.pdf');

        $path = 'question-details/'.$entryId.'.pdf';
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('accounting_entries', ['id' => $entryId, 'detail_file_path' => $path]);
        $this->get(route('admin.questions.detail.view', $entryId))->assertOk();

        $this->postJson(route('admin.questions.detail', $entryId), [
            'detail' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('detail');
    }

    public function test_ajax_errors_do_not_create_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($admin)->postJson('/admin/managers', [
            'name' => 'New Manager',
            'email' => 'manager@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'manager@example.com']);
    }

    public function test_admin_can_be_created_from_the_console(): void
    {
        $this->artisan('app:create-admin', ['name' => 'Portal Admin', 'email' => 'admin@example.com'])
            ->expectsQuestion('Password', 'long-password-123')
            ->expectsQuestion('Confirm password', 'long-password-123')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('long-password-123', $admin->password));
    }

    public function test_super_admin_can_be_created_from_the_console(): void
    {
        $this->artisan('app:create-admin', ['name' => 'Super Admin', 'email' => 'super@example.com', '--super' => true])
            ->expectsQuestion('Password', 'long-password-123')
            ->expectsQuestion('Confirm password', 'long-password-123')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'super@example.com', 'role' => 'super_admin']);
    }

    private function loginWithTwoFactor(User $user): TestResponse
    {
        Mail::fake();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', route('login.verify'));
        $code = null;
        Mail::assertSent(LoginVerificationCode::class, function (LoginVerificationCode $mail) use ($user, &$code): bool {
            $code = $mail->code;

            return $mail->hasTo($user->email);
        });

        return $this->postJson(route('login.verify.submit'), ['code' => $code]);
    }
}
