<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a booking's history: a status move, a ready-date or unit
 * change, a size or payment action, with who did it (blank for the
 * customer or the system). Written by the Booking model; read-only.
 *
 * @property CarbonImmutable $created_at
 */
class BookingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['booking_id', 'user_id', 'kind', 'from', 'to', 'note'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * "Nov 12, 3:04 PM · Dran: Status: Booked → Arrived".
     */
    public function line(): string
    {
        $what = match ($this->kind) {
            'status' => 'Status',
            'ready_on' => 'Ready date',
            'unit' => 'Unit',
            'size' => 'Size class',
            'paid' => 'Fee paid',
            'payment_rejected' => 'Screenshot rejected',
            'payment' => 'Amount paid',
            'discount' => 'Discount',
            'note' => 'Note',
            'assigned' => 'Assigned to',
            default => ucfirst($this->kind),
        };
        $change = collect([$this->from, $this->to])->filter(fn ($value) => filled($value))->implode(' → ');

        return $this->created_at->format('M j, g:i A').' · '.($this->user?->name ?? 'Customer or system').': '.$what.($change !== '' ? ": {$change}" : '').($this->note ? " ({$this->note})" : '');
    }
}
