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
            'name' => $this->safeName(),
            'signInUrl' => url('/login'),
            'usesPassword' => $this->guest->hasPassword(),
        ]);
    }

    /**
     * The name goes into a Markdown email, and a sign-up form lets anyone
     * type any name for any address: greet by the first word only, made of
     * letters, marks, ' and - , so a name can never add links, formatting,
     * lines or a sentence of its own.
     */
    private function safeName(): string
    {
        $first = preg_split('/\s+/u', trim((string) $this->guest->name))[0] ?? '';
        $first = preg_replace('/[^\pL\pM\'-]+/u', '', $first) ?? '';

        return $first === '' ? 'there' : mb_substr($first, 0, 30);
    }
}
