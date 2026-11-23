<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FinancialRecurrenceRate extends Model
{
    protected $fillable = ['amount', 'effective_month', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'effective_month' => 'immutable_date'];
    }
}
