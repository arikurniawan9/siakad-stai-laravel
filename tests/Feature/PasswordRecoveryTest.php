<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_uses_privacy_safe_response_and_sends_link(): void
    {
        Notification::fake(); $user = User::factory()->create();
        $this->get(route('password.request'))->assertOk();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->post(route('password.email'), ['email' => 'unknown@example.test'])->assertSessionHas('success');
    }

    public function test_valid_token_resets_password_and_cannot_be_reused(): void
    {
        $user = User::factory()->create(['password' => 'old-password']); $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'New-secure-password-2026', 'password_confirmation' => 'New-secure-password-2026'];
        $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('New-secure-password-2026', $user->fresh()->password));
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
    }
}
