<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DummyAuthTest extends TestCase
{
    public function test_dummy_super_user_can_login_with_captcha_and_otp_flow(): void
    {
        Cache::flush();
        Mail::fake();

        $captchaResponse = $this->postJson('/api/auth/captcha');
        $captchaResponse->assertCreated();

        $captchaId = $captchaResponse->json('data.captcha.id');
        $captchaAnswer = $captchaResponse->json('data.captcha.answer');

        $this->assertNotEmpty($captchaId);
        $this->assertNotEmpty($captchaAnswer);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'super_user',
            'password' => '77#88*SU',
            'captcha_id' => $captchaId,
            'captcha_answer' => $captchaAnswer,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.user.username', 'super_user');

        $this->assertNotEmpty($response->json('data.otp'));

        $verifyResponse = $this->postJson('/api/auth/verify-otp', [
            'username' => 'super_user',
            'otp' => $response->json('data.otp'),
        ]);

        $verifyResponse->assertCreated()
            ->assertJsonPath('data.user.username', 'super_user');
    }
}
