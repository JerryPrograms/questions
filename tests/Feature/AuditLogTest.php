<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_important_activity_is_logged_and_admins_can_filter_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);

        $this->postJson('/login', ['email' => $admin->email, 'password' => 'wrong-password'])->assertUnprocessable();
        $this->actingAs($admin);
        $transactions = "LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS\nMain Store,Local Bank,2026-10-01,,Supplier,Ask My Accountant,10,,Question,Central\n";
        $this->post(route('admin.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('transactions.csv', $transactions),
        ])->assertRedirect();
        $entryId = DB::table('accounting_entries')->value('id');

        $this->postJson(route('admin.questions.answer', $entryId), ['answer' => 'Approved after receipt review.', 'action' => 'answer'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'question.answered',
            'actor_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_role' => 'admin',
            'subject_type' => 'question',
            'subject_id' => $entryId,
            'location' => 'Main Store',
        ]);
        $answerLog = DB::table('audit_logs')->where('action', 'question.answered')->first();
        $this->assertSame(['answer' => 'Approved after receipt review.'], json_decode($answerLog->new_values, true));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
        $this->assertStringNotContainsString('wrong-password', DB::table('audit_logs')->pluck('new_values')->implode(' '));

        $this->get(route('admin.audit-logs.index', [
            'action' => 'question.answered',
            'actor' => $admin->id,
            'role' => 'admin',
            'location' => 'Main Store',
            'subject_type' => 'question',
            'subject_id' => $entryId,
            'search' => 'receipt review',
        ]))
            ->assertOk()
            ->assertSee('Activity logs')
            ->assertSee('Approved after receipt review.')
            ->assertSee($admin->email)
            ->assertDontSee('Imported transactions.csv');

        $this->actingAs($manager)->get(route('admin.audit-logs.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.audit-logs.index', ['to' => now()->toDateString()]))->assertOk();
    }

    public function test_login_and_logout_record_the_authenticated_user_without_secrets(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', route('login.verify'));
        $code = null;
        Mail::assertSent(LoginVerificationCode::class, function (LoginVerificationCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $this->postJson(route('login.verify.submit'), ['code' => $code])->assertOk();
        $this->postJson('/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_sent', 'subject_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_succeeded', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_id' => $admin->id]);
        $this->assertStringNotContainsString('password', DB::table('audit_logs')->pluck('new_values')->implode(' '));
    }

    public function test_existing_answer_history_is_backfilled_when_the_audit_table_is_created(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $transactions = "LOCATION,BANK NAME,DATE,CHECK NO,PAID TO/BY,ACCOUNT,CREDIT,DEBIT,DESCRIPTION,CLASS\nMain Store,Local Bank,2026-10-01,,Supplier,Ask My Accountant,10,,Question,Central\n";
        $this->post(route('admin.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('transactions.csv', $transactions),
        ]);
        $entryId = DB::table('accounting_entries')->value('id');
        $this->postJson(route('admin.questions.answer', $entryId), ['answer' => 'Historical answer.', 'action' => 'answer'])->assertOk();

        Schema::drop('audit_logs');
        $migration = require database_path('migrations/2026_11_02_215734_create_audit_logs_table.php');
        $migration->up();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'question.answered',
            'actor_id' => $admin->id,
            'subject_id' => $entryId,
            'location' => 'Main Store',
        ]);
        $this->assertSame(['answer' => 'Historical answer.'], json_decode(DB::table('audit_logs')->value('new_values'), true));
    }
}
