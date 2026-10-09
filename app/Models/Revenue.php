<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A sale. Creating it never means money entered the cash: that is only a Receipt. */
class Revenue extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    // Every column is assigned explicitly by CreateRevenue; nothing is mass assignable from requests.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'sale_date' => 'immutable_date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class)->where('sequence', 1);
    }
}
