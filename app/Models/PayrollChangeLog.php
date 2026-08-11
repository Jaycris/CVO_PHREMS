<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class PayrollChangeLog extends Model
{
    protected $fillable = ['area', 'field', 'old_value', 'new_value', 'user_id', 'user_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Records whatever actually changed between two snapshots.
     *
     * Snapshots are shaped as area => [label => displayed value]. Comparing
     * displayed values rather than raw fields means a save that changes nothing
     * writes nothing, and the log reads the way the screen did.
     *
     * @param  array<string, array<string, string>>  $before
     * @param  array<string, array<string, string>>  $after
     */
    public static function record(array $before, array $after): int
    {
        $user = Auth::user();
        $rows = [];

        foreach ($after as $area => $fields) {
            foreach ($fields as $label => $newValue) {
                $oldValue = $before[$area][$label] ?? null;

                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }

                $rows[] = [
                    'area' => $area,
                    'field' => $label,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'user_id' => $user?->id,
                    'user_name' => $user?->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows) {
            static::insert($rows);
        }

        return count($rows);
    }
}
