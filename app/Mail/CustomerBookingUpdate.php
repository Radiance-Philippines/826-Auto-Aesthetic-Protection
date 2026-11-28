<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a customer their booking moved on. Each links to their booking
 * page, which opens without signing in.
 */
class CustomerBookingUpdate extends Mailable
{
    public const KINDS = ['reserved', 'hold', 'paid', 'rejected', 'expired', 'arrived', 'ready', 'priced', 'cancelled', 'rescheduled', 'reminder', 'home_received', 'home_scheduled', 'declined'];

    public function __construct(public Booking $booking, public string $kind) {}

    public function envelope(): Envelope
    {
        $booking = $this->booking;
        $when = $booking->drop_off_on?->format('D, M j') ?? $booking->preferred_date?->format('D, M j');
        $branch = $booking->branch?->name;

        return new Envelope(subject: match ($this->kind) {
            'reserved' => "You’re booked at 826 {$branch} on {$when}",
            'hold' => "Pay the reservation fee to keep {$when}",
            'paid' => "Payment received: you’re booked on {$when}",
            'rejected' => 'Your payment screenshot needs another look',
            'expired' => "Your hold for {$when} ran out",
            'arrived' => 'We have your car at 826 '.$branch,
            'ready' => 'Your car is ready for pickup',
            'priced' => "We reviewed your car: your price for {$when}",
            'cancelled' => "Your {$when} booking is cancelled",
            'rescheduled' => "Your booking is now on {$when}",
            'reminder' => $this->booking->isHome() ? "Your home service with 826 {$branch} is tomorrow" : "See you tomorrow at 826 {$branch}",
            'home_received' => 'We got your home service request',
            'home_scheduled' => "Your home service is set for {$when}",
            default => 'About your home service request',
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.customer-booking-update', with: [
            'bookingUrl' => $this->booking->customerUrl(),
            'brand' => config('app.name'),
        ]);
    }
}
