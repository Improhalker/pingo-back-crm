<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\IsSettlement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One settlement: amount, method and the UTC instant the money was received. Never overwritten; a correction supersedes it. */
class Receipt extends Model
{
    use BelongsToOrganization;
    use IsSettlement;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
