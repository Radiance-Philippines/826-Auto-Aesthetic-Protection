<?php

namespace App\Mail;

use App\Models\Guest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The three emails of a customer's own account: "verify" (a link to
 * confirm the address), "existing" (someone tried to sign up with an address
 * that already has an account, sent to its owner instead of saying so on
 * the page) and "reset" (a link to choose a new password).
 */
class CustomerAccountMail extends Mailable
{
    public const KINDS = ['verify', 'existing', 'reset'];

    public function __construct(public string $kind, public Guest $guest, public ?string $url = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'verify' => 'Verify your email for '.config('app.name'),
            'reset' => 'Choose a new password for '.config('app.name'),
            default => 'You already have an account with '.config('app.name'),
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.customer-account', with: [
            'brand' => config('app.name'),
            'signInUrl' => url('/login'),
            'usesPassword' => $this->guest->hasPassword(),
        ]);
    }
}
