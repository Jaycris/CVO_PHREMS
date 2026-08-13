<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThirteenthMonthOpeningBalance extends Model
{
    protected $fillable = ['employee_id', 'for_year', 'basic_earned', 'note', 'recorded_by_user_id'];

    protected function casts(): array
    {
        return ['basic_earned' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
