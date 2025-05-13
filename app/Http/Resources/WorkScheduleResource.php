<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_time' => $this->start_time, // String HH:MM:SS
            'end_time' => $this->end_time,     // String HH:MM:SS
            'crosses_midnight' => $this->crosses_midnight,
            'work_duration_hours' => (float) $this->work_duration_hours, // Pastikan float
            'break_duration_minutes' => $this->break_duration_minutes,
            'days_active' => [ // Contoh representasi hari aktif
                'monday' => $this->monday,
                'tuesday' => $this->tuesday,
                'wednesday' => $this->wednesday,
                'thursday' => $this->thursday,
                'friday' => $this->friday,
                'saturday' => $this->saturday,
                'sunday' => $this->sunday,
            ],
            'grace_period_late_minutes' => $this->grace_period_late_minutes,
            'grace_period_early_leave_minutes' => $this->grace_period_early_leave_minutes,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
