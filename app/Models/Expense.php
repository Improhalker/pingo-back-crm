<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A financial obligation. Only a Payment represents money effectively paid. */
class Expense extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function payable(): HasOne
    {
        return $this->hasOne(Payable::class);
    }
}
