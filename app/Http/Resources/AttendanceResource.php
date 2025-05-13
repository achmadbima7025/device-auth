<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
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
            'work_date' => $this->work_date->format('Y-m-d'),
            'work_schedule_id' => $this->work_schedule_id,
            'work_schedule_name' => $this->whenLoaded('workSchedule', fn() => $this->workSchedule?->name), // Memuat nama jadwal jika relasi dimuat

            'clock_in_at' => $this->clock_in_at ? $this->clock_in_at->format('Y-m-d H:i:s') : null,
            'clock_in_timezone' => $this->clock_in_at ? $this->clock_in_at->format('P') : null, // Contoh: +07:00
            'clock_in_status' => $this->clock_in_status,
            'clock_in_notes' => $this->clock_in_notes,
            'clock_in_latitude' => $this->clock_in_latitude,
            'clock_in_longitude' => $this->clock_in_longitude,
            'clock_in_device_id' => $this->clock_in_device_id,
            'clock_in_device_name' => $this->whenLoaded('clockInDevice', fn() => $this->clockInDevice?->name),
            'clock_in_qr_code_id' => $this->clock_in_qr_code_id,
            'clock_in_method' => $this->clock_in_method,

            'clock_out_at' => $this->clock_out_at ? $this->clock_out_at->format('Y-m-d H:i:s') : null,
            'clock_out_timezone' => $this->clock_out_at ? $this->clock_out_at->format('P') : null,
            'clock_out_status' => $this->clock_out_status,
            'clock_out_notes' => $this->clock_out_notes,
            'clock_out_latitude' => $this->clock_out_latitude,
            'clock_out_longitude' => $this->clock_out_longitude,
            'clock_out_device_id' => $this->clock_out_device_id,
            'clock_out_device_name' => $this->whenLoaded('clockOutDevice', fn() => $this->clockOutDevice?->name),
            'clock_out_qr_code_id' => $this->clock_out_qr_code_id,
            'clock_out_method' => $this->clock_out_method,

            'scheduled_start_time' => $this->scheduled_start_time, // String 'HH:MM:SS'
            'scheduled_end_time' => $this->scheduled_end_time,   // String 'HH:MM:SS'
            'scheduled_work_duration_minutes' => $this->scheduled_work_duration_minutes,

            'work_duration_minutes' => $this->work_duration_minutes,
            'work_duration_formatted' => $this->work_duration_formatted, // Dari accessor
            'effective_work_minutes' => $this->effective_work_minutes,
            'effective_work_formatted' => $this->effective_work_formatted, // Dari accessor (jika ada)
            'overtime_minutes' => $this->overtime_minutes,
            'lateness_minutes' => $this->lateness_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,

            'is_manually_corrected' => $this->is_manually_corrected,
            'last_corrected_by_id' => $this->last_corrected_by,
            'last_corrected_by_name' => $this->whenLoaded('lastCorrector', fn() => $this->lastCorrector?->name),
            'last_correction_at' => $this->last_correction_at ? $this->last_correction_at->format('Y-m-d H:i:s') : null,
            'correction_summary_notes' => $this->correction_summary_notes,

            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),

            // Memuat relasi jika diminta secara eksplisit oleh controller
            'user' => new UserResource($this->whenLoaded('user')),
            // 'correction_logs' => AttendanceCorrectionLogResource::collection($this->whenLoaded('correctionLogs')), // Jika ada resource untuk log
        ];
    }
}
