<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class DeveloperOptionController extends Controller
{
    /** @var array<string, array{command: string, parameters: array<string, bool>, label: string}> */
    private const ACTIONS = [
        'migrate' => [
            'command' => 'migrate',
            'parameters' => ['--force' => true, '--no-interaction' => true],
            'label' => 'Run migrations',
        ],
        'cache-clear' => [
            'command' => 'cache:clear',
            'parameters' => ['--no-interaction' => true],
            'label' => 'Clear application cache',
        ],
        'seed' => [
            'command' => 'db:seed',
            'parameters' => ['--force' => true, '--no-interaction' => true],
            'label' => 'Run database seeders',
        ],
    ];

    public function index(): View
    {
        $this->ensureEnabled();

        return view('admin.developer-options.index', [
            'twoFactorEnabled' => $this->twoFactorEnabled(),
        ]);
    }

    public function run(Request $request): RedirectResponse|JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
        ]);
        $action = self::ACTIONS[$data['action']];

        try {
            $exitCode = Artisan::call($action['command'], $action['parameters']);
            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                AuditLog::record($request, 'developer.command_failed', $action['label'].' failed.', 'developer_command', null, null, null, [
                    'command' => $action['command'],
                    'exit_code' => $exitCode,
                ]);

                return $this->errorResponse($request, $action['label'].' failed. Review the command output.', $output);
            }

            AuditLog::record($request, 'developer.command_succeeded', $action['label'].' completed.', 'developer_command', null, null, null, [
                'command' => $action['command'],
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse($request, $action['label'].' completed.', $output);
        } catch (Throwable $exception) {
            report($exception);
            AuditLog::record($request, 'developer.command_failed', $action['label'].' failed with an exception.', 'developer_command', null, null, null, [
                'command' => $action['command'],
                'exception' => $exception::class,
            ]);

            return $this->errorResponse($request, $action['label'].' failed. Check the application log.', '');
        }
    }

    public function updateTwoFactor(Request $request): RedirectResponse|JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $previousValue = $this->twoFactorEnabled();
        $enabled = (bool) $data['enabled'];

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'two_factor_enabled'],
            ['value' => $enabled ? '1' : '0'],
        );
        AuditLog::record($request, 'developer.two_factor_updated', 'Two-factor authentication '.($enabled ? 'enabled.' : 'disabled.'), 'system_setting', null, null, [
            'enabled' => $previousValue,
        ], [
            'enabled' => $enabled,
        ]);

        $message = 'Two-factor authentication '.($enabled ? 'enabled.' : 'disabled.');
        $redirect = redirect()->route('admin.developer-options.index');

        return $request->expectsJson()
            ? response()->json(['redirect' => $redirect->getTargetUrl(), 'message' => $message])
            : $redirect->with('status', $message);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('app.devoption'), 404);
    }

    private function twoFactorEnabled(): bool
    {
        return DB::table('system_settings')->where('key', 'two_factor_enabled')->value('value') !== '0';
    }

    private function successResponse(Request $request, string $message, string $output): RedirectResponse|JsonResponse
    {
        $request->session()->flash('status', $message);
        $request->session()->flash('command_output', $output);
        $redirect = redirect()->route('admin.developer-options.index');

        return $request->expectsJson() ? response()->json(['redirect' => $redirect->getTargetUrl()]) : $redirect;
    }

    private function errorResponse(Request $request, string $message, string $output): RedirectResponse|JsonResponse
    {
        $request->session()->flash('command_output', $output);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 500)
            : redirect()->route('admin.developer-options.index')->withErrors(['command' => $message]);
    }
}
