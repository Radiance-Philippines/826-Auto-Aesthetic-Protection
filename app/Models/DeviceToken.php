<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone's push token for the customer app. Stored now; nothing is sent
 * until push credentials are set up (see App\Support\PushNotifier).
 */
class DeviceToken extends Model
{
    protected $fillable = ['guest_id', 'token', 'platform'];

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
