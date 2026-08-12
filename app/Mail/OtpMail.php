<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public string $username,
        public int $expiresIn = 5,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode OTP Login SSO Anda',
        );
    }

    public function content(): Content
    {
        // Jika view tersedia, gunakan view
        if (view()->exists('emails.otp')) {
            return new Content(
                view: 'emails.otp',
                with: [
                    'otp' => $this->otp,
                    'username' => $this->username,
                    'expiresIn' => $this->expiresIn,
                ],
            );
        }

        // Fallback ke plain text
        $plainText = "Halo {$this->username},\n\nKode OTP Anda adalah: {$this->otp}\n\nKode ini berlaku selama {$this->expiresIn} menit.";
        return new Content(
            htmlString: nl2br($plainText),
            text: $plainText,
        );
    }

    public function attachments(): array
    {
        return [];
    }
}