<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiCrudTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSACTIONS = [
        'import_key' => 'ledger-2026-10',
        'file_name' => 'ledger-api',
        'rows' => [[
            'txn_id' => 'TXN-001',
            'location' => 'Main Store',
            'bank_name' => 'Local Bank',
            'transaction_date' => '2026-10-01',
            'check_no' => null,
            'paid_to_by' => 'Supplier',
            'account' => 'Ask My Accountant',
            'credit' => 123.45,
            'debit' => null,
            'description' => 'Supplies',
            'class_name' => 'Central',
        ]],
    ];

    public function test_import_api_is_admin_only_and_upserts_duplicate_files(): void
    {
        $this->getJson('/api/v1/imports')->assertUnauthorized();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->authenticateApi($admin);
        $this->postJson('/api/v1/imports', self::TRANSACTIONS)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.row_count', 1);

        $importId = (int) DB::table('imports')->value('id');
        DB::table('accounting_entries')->where('import_id', $importId)->update([
            'answer' => 'Approved',
            'answered_by' => $admin->id,
            'answered_at' => now(),
        ]);
        $updatedTransactions = self::TRANSACTIONS;
        $updatedTransactions['rows'][0]['paid_to_by'] = 'Updated Supplier';
        $updatedTransactions['rows'][0]['account'] = 'Updated Account';
        $this->postJson('/api/v1/imports', $updatedTransactions)
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $importId);

        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseCount('accounting_entries', 1);
        $this->assertDatabaseHas('accounting_entries', [
            'import_id' => $importId,
            'paid_to_by' => 'Updated Supplier',
            'account' => 'Updated Account',
            'answer' => 'Approved',
        ]);
        $this->getJson('/api/v1/imports')->assertOk()->assertJsonPath('data.0.id', $importId);
        $this->getJson("/api/v1/imports/{$importId}")->assertOk()->assertJsonPath('data.file_name', 'ledger-api');
        $this->getJson("/api/v1/imports/{$importId}/entries")
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Supplies')
            ->assertJsonPath('data.0.txn_id', 'TXN-001');
        $this->getJson('/api/v1/accounting-entries?txn_id=TXN-001&answered=1')
            ->assertOk()
            ->assertJsonPath('data.0.import_file_name', 'ledger-api')
            ->assertJsonPath('data.0.txn_id', 'TXN-001')
            ->assertJsonPath('data.0.paid_to_by', 'Updated Supplier')
            ->assertJsonPath('data.0.account', 'Updated Account')
            ->assertJsonPath('data.0.answer', 'Approved')
            ->assertJsonPath('data.0.answered_by_email', $admin->email);
        $this->patchJson("/api/v1/imports/{$importId}", ['file_name' => 'renamed.csv'])
            ->assertOk()
            ->assertJsonPath('data.file_name', 'renamed.csv');
        Storage::fake('local');
        Storage::disk('local')->put('question-details/1.pdf', 'detail');
        DB::table('accounting_entries')->where('import_id', $importId)->update(['detail_file_path' => 'question-details/1.pdf']);
        $this->deleteJson("/api/v1/imports/{$importId}")->assertNoContent();

        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('accounting_entries', 0);
        Storage::disk('local')->assertMissing('question-details/1.pdf');
    }

    public function test_only_current_admins_can_create_and_use_api_tokens(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/api/v1/auth/token', [
            'email' => $manager->email,
            'password' => 'password',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('api_tokens', 0);

        $this->authenticateApi($admin);
        $admin->forceFill(['role' => 'manager'])->save();
        $this->getJson('/api/v1/imports')->assertForbidden();
    }

    public function test_admin_can_create_and_revoke_a_hashed_api_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/api/v1/auth/token', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $token = $this->authenticateApi($admin);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $token]);
        $this->getJson('/api/v1/imports')->assertOk();
        $this->deleteJson('/api/v1/auth/token')->assertOk();
        $this->assertDatabaseCount('api_tokens', 0);
        $this->getJson('/api/v1/imports')->assertUnauthorized();
    }

    public function test_expired_api_tokens_are_rejected_and_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authenticateApi($admin);

        $this->travel(31)->days();
        $this->getJson('/api/v1/imports')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'API token has expired.');
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_question_option_api_supports_crud_and_idempotent_uploads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authenticateApi($admin);
        $this->postJson('/api/v1/imports', self::TRANSACTIONS)
            ->assertCreated();

        $this->postJson('/api/v1/question-options', [
            'location' => 'Main Store',
            'type' => 'account',
            'value' => 'Office Supplies',
        ])->assertCreated()->assertJsonPath('created', true);
        $optionId = (int) DB::table('location_question_options')->value('id');

        $this->postJson('/api/v1/question-options', [
            'location' => 'Main Store',
            'type' => 'account',
            'value' => 'Office Supplies',
        ])->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('location_question_options', 1);
        $this->getJson('/api/v1/question-options?location=Main%20Store&type=account')
            ->assertOk()
            ->assertJsonPath('data.0.id', $optionId);
        $this->getJson("/api/v1/question-options/{$optionId}")
            ->assertOk()
            ->assertJsonPath('data.value', 'Office Supplies');
        $this->patchJson("/api/v1/question-options/{$optionId}", ['value' => 'Technology Expense'])
            ->assertOk()
            ->assertJsonPath('data.value', 'Technology Expense');
        $this->assertDatabaseHas('accounts', ['account' => 'Technology Expense']);

        $choices = [
            'locations' => ['Main Store'],
            'accounts' => ['Travel Expense'],
            'paid_to_by' => ['Preferred Supplier'],
        ];

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/question-options/import', $choices)->assertOk();
        }

        $this->assertDatabaseCount('location_question_options', 3);
        $this->assertDatabaseHas('location_question_options', [
            'location' => 'Main Store',
            'type' => 'paid_to_by',
            'value' => 'Preferred Supplier',
        ]);
        $this->deleteJson("/api/v1/question-options/{$optionId}")->assertNoContent();

        $this->assertDatabaseMissing('location_question_options', ['id' => $optionId]);
    }

    private function authenticateApi(User $admin): string
    {
        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertCreated()
            ->assertJsonPath('token_type', 'Bearer');
        $token = $response->json('access_token');
        $this->assertIsString($token);
        $this->withToken($token);

        return $token;
    }
}
