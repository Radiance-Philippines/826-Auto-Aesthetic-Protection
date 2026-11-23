<?php

namespace App\Mail;

use App\Models\User;
use App\Support\AdminUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Invites a team member to the admin, with a link to choose their password.
 */
class Invitation extends Mailable
{
    public function __construct(
        public User $user,
        public string $setPasswordUrl,
        public int $expiresInHours,
        public ?string $invitedBy = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to the ".config('app.name').' admin');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'adminUrl' => AdminUrl::for($this->user)->home(),
            'studio' => config('app.name'),
        ]);
    }
}
