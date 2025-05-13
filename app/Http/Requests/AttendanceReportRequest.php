<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttendanceReportRequest extends FormRequest
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
        return [
            'per_page' => 'sometimes|integer|min:1|max:100',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d|after_or_equal:start_date',
            'user_id' => 'sometimes|integer|exists:users,id',
            'work_schedule_id' => 'sometimes|integer|exists:work_schedules,id',
            'clock_in_status' => 'sometimes|string|max:50', // Bisa diperketat dengan Rule::in jika status terbatas
            'clock_out_status' => 'sometimes|string|max:50',
            'sort_by' => ['sometimes', 'string', Rule::in(['work_date', 'user_id', 'clock_in_at', 'clock_out_at', 'work_duration_minutes', 'lateness_minutes', 'overtime_minutes'])],
            'sort_direction' => 'sometimes|string|in:asc,desc',
        ];
    }

    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message'   => 'Parameter filter laporan tidak valid.',
            'errors'    => $validator->errors(),
        ], 422));
    }
}
