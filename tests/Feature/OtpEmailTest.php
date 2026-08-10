<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OtpEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_user_login_sends_otp_and_requires_verification(): void
    {
        Mail::fake();
        Cache::flush();

        $user = User::create([
            'username' => 'super_user',
            'email' => 'girlclown666@gmail.com',
            'role' => 'super_user',
            'password_hash' => bcrypt('77#88*SU'),
        ]);

        $captchaId = (string) Str::uuid();
        Cache::put("login_captcha:{$captchaId}", '1234', now()->addMinutes(5));

        $response = $this->postJson('/api/auth/login', [
            'username' => 'super_user',
            'password' => '77#88*SU',
            'captcha_id' => $captchaId,
            'captcha_answer' => '1234',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.user.email', 'girlclown666@gmail.com');

        Mail::assertSent(function ($mail) use ($user): bool {
            return $mail->hasTo('girlclown666@gmail.com')
                && str_contains($mail->subject, 'OTP');
        });

        $this->assertTrue(Cache::has("otp:{$user->username}"));
    }
}
