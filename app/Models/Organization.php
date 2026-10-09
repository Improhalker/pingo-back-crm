<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    // suspended_at is deliberately not fillable: only the platform suspension action changes it.
    protected $fillable = ['name', 'timezone'];

    protected function casts(): array
    {
        return ['suspended_at' => 'immutable_datetime'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
