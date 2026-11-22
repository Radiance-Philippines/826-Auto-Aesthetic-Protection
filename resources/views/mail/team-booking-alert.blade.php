<x-mail::message>
@switch ($kind)
@case ('reserved')
# New reservation
@break
@case ('hold')
# New hold, waiting for the fee
@break
@case ('size_review')
# Unit to review: set its size
@break
@case ('payment')
# Payment screenshot to check
@break
@case ('home_request')
# Home service request
@break
@case ('review')
# New review waiting for approval
@break
@case ('heads_up')
# Heads-up from operations
@break
@case ('cancelled')
# Cancelled by the customer
@break
@default
# Moved by the customer
@endswitch

**{{ $booking->name }}** · {{ $booking->reference }} · {{ $booking->branch?->name }}

- **When:** {{ $booking->whenLabel() }}
- **Services:** {{ $booking->servicesLabel() }}
- **Vehicle:** {{ $booking->vehicleLabel() ?: '—' }}
- **Mobile:** {{ $booking->phone }}
@if ($booking->email)
- **Email:** {{ $booking->email }}
@endif
@if ($booking->total_price !== null)
- **Price:** ₱{{ number_format($booking->total_price) }}
@endif
@if ($booking->amount_due)
- **Reservation fee:** ₱{{ number_format($booking->amount_due) }}{{ $booking->hold_expires_at && $booking->status === 'awaiting_payment' ? ', held until '.$booking->hold_expires_at->format('g:i A') : '' }}
@endif
@if ($booking->isHome())
- **Address:** {{ $booking->address }}{{ $booking->area ? " ({$booking->area})" : '' }}
@if ($booking->landmark)
- **Landmark:** {{ $booking->landmark }}
@endif
- **Water / power / shade:** {{ collect(['has_water', 'has_power', 'has_shade'])->map(fn ($field) => $booking->{$field} === null ? '?' : ($booking->{$field} ? 'yes' : 'no'))->implode(' / ') }}
@endif

@if ($kind === 'review' && $booking->review)
<x-mail::panel>
{{ str_repeat('★', $booking->review->rating) }}{{ str_repeat('☆', 5 - $booking->review->rating) }}

{!! nl2br(e($booking->review->body)) !!}
</x-mail::panel>
@elseif ($booking->message && $kind !== 'heads_up')
<x-mail::panel>
{!! nl2br(e($booking->message)) !!}
</x-mail::panel>
@endif

@if ($kind === 'heads_up')
{{ $note }}

Let the customer know if it matters to them.
@elseif ($kind === 'size_review')
Look at the unit, then set its size in the admin: that prices the booking and emails the customer.
@elseif ($kind === 'payment')
Check the screenshot against your GCash or Maya, then mark it paid in the admin.
@elseif ($kind === 'review')
Read it, then approve it to show it on the site, or reject it.
@elseif ($kind === 'home_request')
Call or text {{ $booking->name }} to agree on a time, then schedule it in the admin.
@endif

<x-mail::button :url="$adminUrl">
Open in the admin
</x-mail::button>
</x-mail::message>
