<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone from the public website who asked for the free months or a demo. Platform-wide, not tied to a library. */
class Lead extends Model
{
    public const TRIAL = 'trial';
    public const DEMO = 'demo';
    public const TYPES = [self::TRIAL => 'Free months', self::DEMO => 'Demo'];

    public const SIZES = ['1-50' => 'Up to 50 students', '51-150' => '51 – 150 students', '151-500' => '151 – 500 students', '500+' => 'More than 500'];
    public const TIMES = ['morning' => 'Morning (9 – 12)', 'afternoon' => 'Afternoon (12 – 4)', 'evening' => 'Evening (4 – 8)'];
    public const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'converted' => 'Library created', 'closed' => 'Closed'];

    protected $guarded = ['id', 'status', 'library_id', 'handled_by'];

    protected function casts(): array
    {
        return ['preferred_date' => 'date'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
