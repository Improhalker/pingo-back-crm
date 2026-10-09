<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Receipt/payment correction chain (ADR-031). A row is effective while `superseded_at` is null; a corrected
 * row stays as evidence and its replacement points to it through `corrects_id`. Totals use effective rows only.
 */
trait IsSettlement
{
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('superseded_at'));
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /** The row this one corrects (null for an original settlement). */
    public function corrected(): BelongsTo
    {
        return $this->belongsTo(static::class, 'corrects_id');
    }

    /** The row that replaced this one (null while effective). */
    public function replacement(): HasOne
    {
        return $this->hasOne(static::class, 'corrects_id');
    }
}
