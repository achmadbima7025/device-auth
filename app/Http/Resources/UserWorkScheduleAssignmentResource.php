<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserWorkScheduleAssignmentResource extends JsonResource
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
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn() => $this->user?->name),
            'work_schedule_id' => $this->work_schedule_id,
            'work_schedule_name' => $this->whenLoaded('workSchedule', fn() => $this->workSchedule?->name),
            'effective_start_date' => $this->effective_start_date->format('Y-m-d'),
            'effective_end_date' => $this->effective_end_date ? $this->effective_end_date->format('Y-m-d') : null,
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'assigned_by_name' => $this->whenLoaded('assignedBy', fn() => $this->assignedBy?->name),
            'assignment_notes' => $this->assignment_notes,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'is_currently_active' => $this->is_currently_active, // Dari accessor jika ada
        ];
    }
}
