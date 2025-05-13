<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class AssignWorkScheduleRequest extends FormRequest
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
            'user_id' => 'required|integer|exists:users,id',
            'work_schedule_id' => 'required|integer|exists:work_schedules,id',
            'effective_start_date' => 'required|date_format:Y-m-d',
            'effective_end_date' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:effective_start_date',
            'assignment_notes' => 'sometimes|nullable|string|max:1000',
        ];
    }

    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message'   => 'Data penugasan jadwal kerja tidak valid.',
            'errors'    => $validator->errors(),
        ], 422));
    }
}
