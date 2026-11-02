<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeveloperOptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_developer_options_are_hidden_and_unreachable_when_disabled(): void
    {
        config(['app.devoption' => false]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.imports.create'))
            ->assertOk()
            ->assertDontSee('Developer options');
        $this->get(route('admin.developer-options.index'))->assertNotFound();
        $this->postJson(route('admin.developer-options.run'), ['action' => 'migrate'])->assertNotFound();
    }

    public function test_only_admins_can_view_enabled_developer_options(): void
    {
        config(['app.devoption' => true]);
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($manager)->get(route('admin.developer-options.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.developer-options.index'))
            ->assertOk()
            ->assertSee('Developer options')
            ->assertSee('php artisan migrate')
            ->assertSee('php artisan cache:clear')
            ->assertSee('php artisan db:seed');
        $this->get(route('admin.imports.create'))->assertSee('Developer options');
    }

    /** @param array<string, bool> $parameters */
    #[DataProvider('developerActions')]
    public function test_admin_can_run_allowlisted_developer_commands(string $action, string $command, array $parameters): void
    {
        config(['app.devoption' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        Artisan::shouldReceive('call')->once()->with($command, $parameters)->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Command completed.');

        $this->actingAs($admin)->postJson(route('admin.developer-options.run'), ['action' => $action])
            ->assertOk()
            ->assertJsonPath('redirect', route('admin.developer-options.index'));

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'developer.command_succeeded',
            'subject_type' => 'developer_command',
        ]);
    }

    public function test_arbitrary_developer_commands_are_rejected(): void
    {
        config(['app.devoption' => true]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('admin.developer-options.run'), ['action' => 'config:show'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
    }

    /** @return array<string, array{string, string, array<string, bool>}> */
    public static function developerActions(): array
    {
        return [
            'migrate' => ['migrate', 'migrate', ['--force' => true, '--no-interaction' => true]],
            'clear cache' => ['cache-clear', 'cache:clear', ['--no-interaction' => true]],
            'seed' => ['seed', 'db:seed', ['--force' => true, '--no-interaction' => true]],
        ];
    }
}
