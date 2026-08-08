<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveEligibility extends Model
{
    protected $table = 'employee_leave_eligibilities';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'is_eligible',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_eligible' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
