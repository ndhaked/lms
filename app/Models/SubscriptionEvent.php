<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history of everything that happened to a subscription. */
class SubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Subscription history cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Subscription history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public static function log(Subscription $s, string $type, ?string $from = null, ?string $to = null, ?string $reason = null, array $data = []): self
    {
        return static::create([
            'subscription_id' => $s->id, 'library_id' => $s->library_id, 'user_id' => auth()->id(),
            'type' => $type, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'data' => $data ?: null,
        ]);
    }
}
