<?php

namespace Tests\Feature;

use App\Mail\LoginVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_require_emailed_code_before_login(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', route('login.verify'));

        $this->assertGuest();
        $pending = session('login_two_factor');
        $this->assertSame($admin->id, $pending['user_id']);
        $this->assertSame(0, $pending['attempts']);
        $this->assertEqualsWithDelta(now()->addMinutes(2)->timestamp, $pending['expires_at'], 2);
        $this->get(route('login.verify'))
            ->assertOk()
            ->assertSee('Enter your 6-digit code')
            ->assertSee('Trust this device for 30 days')
            ->assertDontSee($admin->email);

        $code = $this->sentCodeFor($admin);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, $pending['code_hash']);
        $this->assertTrue(Hash::check($code, $pending['code_hash']));

        $this->postJson(route('login.verify.submit'), ['code' => $code])
            ->assertOk()
            ->assertJsonPath('redirect', route('admin.imports.create'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('login_two_factor'));
    }

    public function test_trusted_device_skips_email_verification_for_thirty_days(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $verificationResponse = $this->postJson(route('login.verify.submit'), [
            'code' => $this->sentCodeFor($admin),
            'trust_device' => true,
        ])->assertOk()->assertCookie('trusted_device')->assertCookieNotExpired('trusted_device');

        $trustedDeviceCookie = $verificationResponse->getCookie('trusted_device')->getValue();
        $this->assertDatabaseHas('trusted_devices', ['user_id' => $admin->id]);
        $this->post('/logout')->assertRedirect(route('login'));

        $this->withCredentials()->withCookie('trusted_device', $trustedDeviceCookie)
            ->postJson('/login', ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertGuest();

        $this->postJson('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', route('admin.imports.create'));

        $this->assertAuthenticatedAs($admin);
        Mail::assertSent(LoginVerificationCode::class, 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'auth.login_succeeded',
            'summary' => 'Signed in successfully from a trusted device.',
        ]);
    }

    public function test_expired_trusted_device_still_requires_email_verification(): void
    {
        Mail::fake();
        $manager = User::factory()->create(['role' => 'manager']);

        $this->postJson('/login', ['email' => $manager->email, 'password' => 'password'])->assertOk();
        $verificationResponse = $this->postJson(route('login.verify.submit'), [
            'code' => $this->sentCodeFor($manager),
            'trust_device' => true,
        ])->assertOk();
        $trustedDeviceCookie = $verificationResponse->getCookie('trusted_device')->getValue();
        $this->post('/logout')->assertRedirect(route('login'));

        $this->travel(31)->days();
        $this->withCredentials()->withCookie('trusted_device', $trustedDeviceCookie)
            ->postJson('/login', ['email' => $manager->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('redirect', route('login.verify'));

        $this->assertGuest();
        Mail::assertSent(LoginVerificationCode::class, 2);
        $this->assertDatabaseMissing('trusted_devices', ['user_id' => $manager->id]);
    }

    public function test_code_expires_after_two_minutes_and_can_be_resent(): void
    {
        Mail::fake();
        $manager = User::factory()->create(['role' => 'manager']);
        $this->postJson('/login', ['email' => $manager->email, 'password' => 'password'])->assertOk();
        $code = $this->sentCodeFor($manager);

        $this->travel(121)->seconds();
        $this->postJson(route('login.verify.submit'), ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertGuest();

        $this->post(route('login.verify.resend'))->assertRedirect(route('login.verify'));
        Mail::assertSent(LoginVerificationCode::class, 2);
        $this->assertEqualsWithDelta(now()->addMinutes(2)->timestamp, session('login_two_factor.expires_at'), 2);
    }

    public function test_five_incorrect_codes_cancel_the_pending_login(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $code = $this->sentCodeFor($admin);
        $incorrectCode = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(route('login.verify.submit'), ['code' => $incorrectCode])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        }

        $this->assertGuest();
        $this->assertNull(session('login_two_factor'));
    }

    private function sentCodeFor(User $user): string
    {
        $code = null;
        Mail::assertSent(LoginVerificationCode::class, function (LoginVerificationCode $mail) use ($user, &$code): bool {
            $code = $mail->code;

            return $mail->hasTo($user->email);
        });

        return $code;
    }
}
