<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditPurchaseProof extends Model
{
    protected $guarded = ['id'];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreditPurchase::class, 'credit_purchase_id');
    }
}
