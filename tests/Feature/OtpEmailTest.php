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
            'email' => 'clowngirl666@gmail.com',
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
            ->assertJsonPath('data.user.email', 'clowngirl666@gmail.com');

        Mail::assertSent(function ($mail) use ($user): bool {
            return $mail->hasTo('clowngirl666@gmail.com')
                && str_contains($mail->subject, 'OTP');
        });

        $this->assertTrue(Cache::has("otp:{$user->username}"));
    }

    public function test_non_super_user_login_forwards_otp_to_forward_email_first(): void
    {
        Mail::fake();
        Cache::flush();

        $user = User::create([
            'username' => 'admin_simrs',
            'email' => 'rchldrgn@gmail.com',
            'role' => 'admin',
            'password_hash' => bcrypt('12#56*DS'),
        ]);

        $captchaId = (string) Str::uuid();
        Cache::put("login_captcha:{$captchaId}", '1234', now()->addMinutes(5));

        $this->postJson('/api/auth/login', [
            'username' => 'admin_simrs',
            'password' => '12#56*DS',
            'captcha_id' => $captchaId,
            'captcha_answer' => '1234',
        ])
            ->assertOk()
            ->assertJsonPath('data.requires_otp', true);

        // Dua email terpisah berurutan: clowngirl666@gmail.com terkirim
        // DULU, baru email user yang login.
        $sentMails = Mail::sent()->all();

        $this->assertCount(2, $sentMails);
        $this->assertSame('clowngirl666@gmail.com', array_key_first($sentMails[0]->to));
        $this->assertSame($user->email, array_key_first($sentMails[1]->to));

        $this->assertTrue(Cache::has("otp:{$user->username}"));
    }
}
