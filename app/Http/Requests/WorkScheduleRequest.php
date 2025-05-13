<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WorkScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $scheduleId = $this->route('workSchedule') ? $this->route('workSchedule')->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('work_schedules', 'name')->ignore($scheduleId),
            ],
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s',
            'crosses_midnight' => 'required|boolean',
            'work_duration_hours' => 'required|numeric|min:0|max:24', // Jam kerja efektif netto
            'break_duration_minutes' => 'required|integer|min:0|max:1440', // Maks 24 jam
            'monday' => 'sometimes|boolean',
            'tuesday' => 'sometimes|boolean',
            'wednesday' => 'sometimes|boolean',
            'thursday' => 'sometimes|boolean',
            'friday' => 'sometimes|boolean',
            'saturday' => 'sometimes|boolean',
            'sunday' => 'sometimes|boolean',
            'grace_period_late_minutes' => 'sometimes|integer|min:0',
            'grace_period_early_leave_minutes' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }

    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message'   => 'Data jadwal kerja tidak valid.',
            'errors'    => $validator->errors(),
        ], 422));
    }
}
