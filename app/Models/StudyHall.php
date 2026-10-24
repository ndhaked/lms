<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyHall extends Model
{
    use BelongsToLibrary;

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'description', 'capacity', 'opening_time', 'closing_time', 'status', 'supervisor_id'];

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
