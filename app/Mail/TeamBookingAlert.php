<?php

namespace App\Mail;

use App\Models\Booking;
use App\Support\AdminUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a branch's team that something happened to a booking: a new
 * reservation or hold, a unit that needs its size, a payment screenshot to check, a home-service
 * request, a customer cancelling or moving one, or operations changing a
 * unit's ready date or details (a heads-up for sales). Replying answers the
 * customer when they left an email. It goes to an address, not an account,
 * so its link is the old /admin one, which lands in the opener's own panel.
 */
class TeamBookingAlert extends Mailable
{
    public const KINDS = ['reserved', 'hold', 'size_review', 'payment', 'home_request', 'cancelled', 'rescheduled', 'review', 'heads_up'];

    public function __construct(public Booking $booking, public string $kind, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        $booking = $this->booking;
        $when = $booking->drop_off_on?->format('D, M j') ?? $booking->preferred_date?->format('D, M j');
        $branch = $booking->branch?->name;

        return new Envelope(
            replyTo: $booking->email && $this->kind !== 'heads_up' ? [new Address($booking->email, $booking->name)] : [],
            subject: match ($this->kind) {
                'reserved' => "New reservation at {$branch}: {$booking->name}, {$when} ({$booking->reference})",
                'hold' => "New hold at {$branch}, waiting for the fee: {$booking->name}, {$when}",
                'size_review' => "Size to set: {$booking->vehicleLabel()} at {$branch}, {$when} ({$booking->reference})",
                'payment' => "Screenshot to check: {$booking->name} ({$booking->reference})",
                'home_request' => "Home service request: {$booking->name}, {$when}",
                'heads_up' => "Heads-up on {$booking->name} ({$booking->reference}): {$this->note}",
                'review' => "New review waiting for approval: {$booking->name} at {$branch}",
                'cancelled' => "Cancelled by the customer: {$booking->name}, {$when} ({$booking->reference})",
                default => "Moved by the customer: {$booking->name}, now {$when} ({$booking->reference})",
            },
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-booking-alert', with: [
            'adminUrl' => $this->kind === 'review'
                ? AdminUrl::legacy('reviews')
                : AdminUrl::legacyBooking($this->booking),
        ]);
    }
}
