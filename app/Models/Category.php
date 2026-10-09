<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToOrganization;

    public const TYPES = ['REVENUE', 'EXPENSE'];

    // The type is fixed at creation: entries keep the meaning of the category they were recorded with.
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
