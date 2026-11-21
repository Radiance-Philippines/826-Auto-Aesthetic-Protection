<?php

namespace App\Filament\Resources\Bookings;

use App\Models\Booking;
use App\Models\PaymentSetting;
use App\Models\VehicleSize;
use App\Support\Notifier;
use App\Support\PaymentProof;
use App\Support\Reservations;
use App\Support\Schedule\OpenCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;

/**
 * What the team does with a booking, from the booking page and the
 * queue: check the fee, review the unit's size, move it along the queue
 * (arrived to exited), move or cancel it, schedule a home visit, and
 * reach the customer.
 */
final class BookingActions
{
    /**
     * The screenshot matches: the booking is confirmed.
     */
    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label(fn (Booking $record) => $record->status === 'payment_review' ? 'Payment checked: confirm' : 'Mark fee paid')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Booking $record) => in_array($record->stage(), ['awaiting_payment', 'payment_review'], true))
            ->modalHeading(fn (Booking $record) => "Confirm {$record->name}’s booking?")
            ->modalDescription(fn (Booking $record) => $record->status === 'payment_review'
                ? 'Check the screenshot against your GCash or Maya first. The customer gets an email that they’re booked.'
                : 'For a fee paid another way (at the counter, by bank). The customer gets an email that they’re booked.')
            ->fillForm(fn (Booking $record) => ['payment_note' => $record->payment_note])
            ->schema([
                TextInput::make('payment_note')
                    ->label('Payment note')
                    ->placeholder('GCash ref no.')
                    ->maxLength(255),
            ])
            ->modalSubmitActionLabel('Confirm')
            ->action(function (Booking $record, array $data) {
                $record->update([
                    'status' => 'confirmed',
                    'paid_at' => now(),
                    'payment_note' => $data['payment_note'] ?? null,
                    'hold_expires_at' => null,
                ]);
                Notifier::customer($record, 'paid');

                Notification::make()->success()->title('Booking confirmed')->send();
            });
    }

    /**
     * The screenshot doesn't match: the customer is asked for another and
     * gets a fresh hold.
     */
    public static function rejectProof(): Action
    {
        return Action::make('rejectProof')
            ->label('Ask for another screenshot')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Booking $record) => $record->status === 'payment_review')
            ->modalDescription(fn () => 'The customer sees your reason and has '.PaymentSetting::instance()->holdMinutes().' minutes to upload again before the slot opens up. Their screenshot is deleted.')
            ->schema([
                TextInput::make('reason')
                    ->label('Why (the customer sees this)')
                    ->placeholder('The amount doesn’t match, the screenshot is cut off…')
                    ->required()
                    ->maxLength(255),
            ])
            ->modalSubmitActionLabel('Ask again')
            ->action(function (Booking $record, array $data) {
                PaymentProof::delete($record->payment_proof);
                $record->update([
                    'status' => 'awaiting_payment',
                    'payment_proof' => null,
                    'payment_submitted_at' => null,
                    'payment_rejected_reason' => $data['reason'],
                    'hold_expires_at' => $record->isOnsite() ? now()->addMinutes(PaymentSetting::instance()->holdMinutes()) : null,
                ]);
                Notifier::customer($record, 'rejected');

                Notification::make()->success()->title('Asked for another screenshot')->send();
            });
    }

    /**
     * The unit is at the branch: recorded with the time it arrived.
     */
    public static function markArrived(): Action
    {
        return self::step('markArrived', 'Arrived', Heroicon::OutlinedArrowRightEndOnRectangle, 'info', 'arrived', 'The unit is at the branch. Its arrival time is recorded and the customer gets an email.');
    }

    public static function startWork(): Action
    {
        return self::step('startWork', 'In progress', Heroicon::OutlinedWrenchScrewdriver, 'info', 'in_progress', fn (Booking $record) => $record->isHome() ? 'The team is at the customer’s place and has started.' : 'The job has started.');
    }

    public static function qualityCheck(): Action
    {
        return self::step('qualityCheck', 'Quality check', Heroicon::OutlinedMagnifyingGlass, 'warning', 'quality_check', 'The work is done and is being checked.');
    }

    public static function markReady(): Action
    {
        return self::step('markReady', 'Ready', Heroicon::OutlinedCheckBadge, 'success', 'ready', 'The customer gets an email that their car is ready.');
    }

    public static function markExited(): Action
    {
        return self::step('markExited', 'Exited', Heroicon::OutlinedCheckCircle, 'success', 'exited', 'The unit has left the branch. Its exit time is recorded.')
            ->label(fn (Booking $record) => $record->isHome() ? 'Done' : 'Exited');
    }

    /**
     * One step along the queue, offered while the booking's next status is $next.
     */
    private static function step(string $name, string $label, Heroicon $icon, string $color, string $next, string|Closure $description): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Booking $record) => $record->nextStatus() === $next)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(fn (Booking $record) => Reservations::advance($record));
    }

    /**
     * The admin has seen the unit: assigns its size class, which prices the
     * booking (and the customer's saved unit) from the services' rates.
     */
    public static function setSize(): Action
    {
        return Action::make('setSize')
            ->label(fn (Booking $record) => $record->size_reviewed_at ? 'Change size' : 'Review unit')
            ->icon(Heroicon::OutlinedScale)
            ->color(fn (Booking $record) => $record->needsSizeReview() ? 'warning' : 'gray')
            ->visible(fn (Booking $record) => $record->isOpen())
            ->modalHeading(fn (Booking $record) => 'Review '.($record->vehicleLabel() ?: 'the unit'))
            ->modalDescription('Pick the size class once you have seen the unit. It sets the price from each service’s rates, and the customer gets an email with it. Their saved unit keeps the size, so next time it’s priced at once.')
            ->fillForm(fn (Booking $record) => ['vehicle_size_id' => $record->vehicle_size_id])
            ->schema([
                Select::make('vehicle_size_id')
                    ->label('Size class')
                    ->options(fn () => VehicleSize::query()->active()->ordered()->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->modalSubmitActionLabel('Set size')
            ->action(function (Booking $record, array $data) {
                $size = VehicleSize::query()->findOrFail($data['vehicle_size_id']);
                $missing = Reservations::applySize($record, $size);

                if ($missing !== []) {
                    Notification::make()
                        ->warning()
                        ->title("No price for {$size->name} yet")
                        ->body('Set '.implode(', ', $missing).' for '.$size->name.' in Shop → Services. The booking stays in Needs you until it has a price.')
                        ->persistent()
                        ->send();

                    return;
                }

                Notifier::customer($record, 'priced');
                Notification::make()->success()->title("Size set: {$size->name}")->body('Price: ₱'.number_format((int) $record->total_price))->send();
            });
    }

    public static function noShow(): Action
    {
        return Action::make('noShow')
            ->label('No-show')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('gray')
            ->visible(fn (Booking $record) => $record->status === 'confirmed' && $record->drop_off_on?->isBefore(today()))
            ->requiresConfirmation()
            ->modalDescription('The customer didn’t come. The day no longer counts toward the cap.')
            ->action(fn (Booking $record) => $record->update(['status' => 'no_show']));
    }

    public static function cancel(): Action
    {
        return Action::make('cancelBooking')
            ->label(fn (Booking $record) => $record->status === 'requested' ? 'Decline' : 'Cancel booking')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (Booking $record) => $record->isOpen())
            ->modalDescription(fn (Booking $record) => $record->paid_at
                ? 'The day opens up again. Sort out the reservation fee with the customer yourself.'
                : 'The day opens up again. The customer gets an email.')
            ->schema([
                TextInput::make('reason')
                    ->label('Reason (the customer sees this)')
                    ->maxLength(250),
            ])
            ->modalSubmitActionLabel(fn (Booking $record) => $record->status === 'requested' ? 'Decline' : 'Cancel booking')
            ->action(function (Booking $record, array $data) {
                if ($record->status === 'requested') {
                    $record->update(['status' => 'declined', 'cancelled_by' => 'staff', 'cancel_reason' => $data['reason'] ?? null]);
                    Notifier::customer($record, 'declined');

                    return;
                }

                Reservations::cancel($record, 'staff', $data['reason'] ?? null);
                Notifier::customer($record, 'cancelled');
            });
    }

    /**
     * Moves an onsite booking to another drop-off date, keeping its
     * services and payment.
     */
    public static function reschedule(): Action
    {
        return Action::make('reschedule')
            ->label('Move')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (Booking $record) => $record->isOnsite() && in_array($record->status, ['awaiting_payment', 'payment_review', 'confirmed'], true) && $record->stage() !== 'expired')
            ->fillForm(fn (Booking $record) => ['drop_off_on' => $record->drop_off_on?->toDateString()])
            ->schema([
                DatePicker::make('drop_off_on')
                    ->label('New drop-off date')
                    ->displayFormat('D, M j, Y')
                    ->required()
                    ->helperText(fn (Booking $record) => 'Takes '.$record->estimate()->label().'. The ready date moves with it.'),
                Toggle::make('force')
                    ->label('Book even if it’s full'),
            ])
            ->modalSubmitActionLabel('Move')
            ->action(function (Booking $record, array $data, Action $action) {
                try {
                    Reservations::reschedule($record, CarbonImmutable::parse($data['drop_off_on']), byCustomer: false, force: (bool) ($data['force'] ?? false));
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Not moved')->body(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                } catch (LockTimeoutException) {
                    Notification::make()->danger()->title('Busy, try again')->send();
                    $action->halt();
                }

                Notifier::customer($record->refresh(), 'rescheduled');
                Notification::make()->success()->title('Moved')->body($record->whenLabel())->send();
            });
    }

    /**
     * For a car that isn't ready on time: a later ready date.
     */
    public static function changeReadyDate(): Action
    {
        return Action::make('changeReadyDate')
            ->label('Change ready date')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->visible(fn (Booking $record) => $record->isOnsite() && in_array($record->status, ['confirmed', 'arrived', 'in_progress', 'quality_check', 'ready'], true))
            ->fillForm(fn (Booking $record) => ['ready_on' => $record->ready_on?->toDateString()])
            ->schema([
                DatePicker::make('ready_on')
                    ->label('Ready around')
                    ->displayFormat('D, M j, Y')
                    ->required()
                    ->afterOrEqual(fn (Booking $record) => $record->drop_off_on?->toDateString()),
            ])
            ->action(fn (Booking $record, array $data) => $record->update(['ready_on' => CarbonImmutable::parse($data['ready_on'])->startOfDay()]));
    }

    /**
     * A home-service request gets its visit date (and the fee, when on).
     */
    public static function scheduleHome(): Action
    {
        return Action::make('scheduleHome')
            ->label('Schedule')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('primary')
            ->visible(fn (Booking $record) => $record->isHome() && in_array($record->status, ['requested', 'awaiting_payment', 'confirmed'], true))
            ->fillForm(fn (Booking $record) => [
                'drop_off_on' => $record->drop_off_on?->toDateString() ?? $record->preferred_date?->toDateString(),
                'ready_on' => $record->ready_on?->toDateString(),
                'ask_fee' => PaymentSetting::instance()->feeEnabled() && $record->status === 'requested',
            ])
            ->modalDescription('Agree on the day with the customer first. They get an email with it.')
            ->schema([
                DatePicker::make('drop_off_on')->label('Team comes on')->displayFormat('D, M j, Y')->required(),
                DatePicker::make('ready_on')->label('Done by')->displayFormat('D, M j, Y')->afterOrEqual('drop_off_on')->helperText('Leave blank to work it out from the services.'),
                Toggle::make('ask_fee')
                    ->label(fn () => 'Ask for the reservation fee ('.(PaymentSetting::instance()->feeLabel() ?? 'off in Payments').')')
                    ->visible(fn (Booking $record) => PaymentSetting::instance()->feeEnabled() && $record->status === 'requested'),
            ])
            ->modalSubmitActionLabel('Schedule')
            ->action(function (Booking $record, array $data) {
                $payments = PaymentSetting::instance();
                $askFee = ($data['ask_fee'] ?? false) && $record->status === 'requested' && $payments->feeAmount() > 0;
                $visit = CarbonImmutable::parse($data['drop_off_on'])->startOfDay();
                $ready = filled($data['ready_on'] ?? null)
                    ? CarbonImmutable::parse($data['ready_on'])->startOfDay()
                    : OpenCalendar::for($record->branch, $visit, $visit)->readyOn($visit, (int) $record->total_days);

                $record->update([
                    'drop_off_on' => $visit,
                    'ready_on' => $ready ?? $visit,
                    'status' => $record->status === 'requested' ? ($askFee ? 'awaiting_payment' : 'confirmed') : $record->status,
                    'amount_due' => $askFee ? $payments->feeAmount() : $record->amount_due,
                    'hold_expires_at' => null,
                    'read_at' => $record->read_at ?? now(),
                ]);
                Notifier::customer($record, 'home_scheduled');

                Notification::make()->success()->title('Scheduled')->body($record->whenLabel())->send();
            });
    }

    public static function call(): Action
    {
        return Action::make('call')
            ->icon(Heroicon::OutlinedPhone)
            ->color('gray')
            ->url(fn (Booking $record) => BookingResource::phoneUrl($record->phone))
            ->visible(fn (Booking $record) => BookingResource::phoneUrl($record->phone) !== null);
    }

    public static function text(): Action
    {
        return Action::make('text')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('gray')
            ->url(function (Booking $record) {
                $number = BookingResource::phoneUrl($record->phone);

                return $number === null ? null : 'sms:'.substr($number, 4).'?body='.rawurlencode("Hi {$record->name}, this is 826 {$record->branch?->name} about your booking {$record->reference}.");
            })
            ->visible(fn (Booking $record) => BookingResource::phoneUrl($record->phone) !== null);
    }

    public static function email(): Action
    {
        return Action::make('email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->url(fn (Booking $record) => $record->email ? 'mailto:'.$record->email.'?subject='.rawurlencode("Your 826 booking {$record->reference}") : null)
            ->visible(fn (Booking $record) => filled($record->email));
    }

    /**
     * The private booking link, to text the customer (it opens without signing in).
     */
    public static function link(): Action
    {
        return Action::make('customerLink')
            ->label('Copy booking link')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->modalHeading('Booking link')
            ->modalDescription('Text or message this to the customer. It opens their booking page, where they see the status and pay, without signing in. Keep it private.')
            ->schema([
                TextInput::make('url')
                    ->label('Link')
                    ->default(fn (Booking $record) => $record->customerUrl())
                    ->readOnly()
                    ->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }

    /**
     * Every status action, in the order they're offered.
     *
     * @return list<Action>
     */
    public static function workflow(): array
    {
        return [
            self::markPaid(),
            self::rejectProof(),
            self::setSize(),
            self::scheduleHome(),
            self::markArrived(),
            self::startWork(),
            self::qualityCheck(),
            self::markReady(),
            self::markExited(),
        ];
    }
}
