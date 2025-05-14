<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class UserWorkScheduleAssignment extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'work_schedule_id', 'effective_start_date', 'effective_end_date', 'assigned_by_user_id', 'assignment_notes',];

    protected $casts = ['effective_start_date' => 'date', 'effective_end_date' => 'date',];

    /**
     * Mendapatkan pengguna yang ditugaskan jadwal ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mendapatkan jadwal kerja yang ditugaskan.
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * Mendapatkan admin atau manajer yang melakukan penugasan.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /**
     * Scope a query to only include assignments active on a given date.
     *
     * @param Builder $query
     * @param string|Carbon|null $date The date to check against. Defaults to today.
     * @return Builder
     */
    public function scopeActiveOn(Builder $query, Carbon|string|null $date = null): Builder
    {
        $targetDate = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();

        return $query->where('effective_start_date', '<=', $targetDate)->where(function ($subQuery) use ($targetDate) {
                $subQuery->whereNull('effective_end_date')->orWhere('effective_end_date', '>=', $targetDate);
            });
    }

    /**
     * Accessor to check if the assignment is currently active.
     *
     * @return bool
     */
    public function getIsCurrentlyActiveAttribute(): bool
    {
        $today = Carbon::today();
        $starts = $this->effective_start_date; // Already a Carbon instance due to casting
        $ends = $this->effective_end_date;     // Already a Carbon instance or null

        if ($starts->gt($today)) {
            return false; // Starts in the future
        }

        if ($ends && $ends->lt($today)) {
            return false; // Ended in the past
        }

        return true; // Active now (starts today or in past, and ends today, in future, or indefinitely)
    }
}
