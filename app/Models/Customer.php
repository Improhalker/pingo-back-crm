<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToOrganization;

    // organization_id and archived_at are set only by the server (context and archive actions).
    protected $fillable = ['name', 'phone', 'email', 'document', 'notes'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
