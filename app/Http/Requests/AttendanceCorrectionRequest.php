<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class AttendanceCorrectionRequest extends FormRequest
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
        // Ambil work_date dari route parameter {attendance} atau dari input jika diizinkan diubah
        // $attendanceWorkDate = $this->route('attendance') ? $this->route('attendance')->work_date->toDateString() : ($this->input('work_date') ?? null);

        return [
            'work_date' => 'sometimes|date_format:Y-m-d', // Jika admin bisa mengubah tanggal kerja
            'clock_in_at_time' => 'sometimes|nullable|date_format:H:i:s', // Hanya waktu
            'clock_in_status' => 'sometimes|nullable|string|max:50',
            'clock_in_notes' => 'sometimes|nullable|string|max:1000',
            'clock_out_at_time' => 'sometimes|nullable|date_format:H:i:s', // Hanya waktu
            'clock_out_status' => 'sometimes|nullable|string|max:50',
            'clock_out_notes' => 'sometimes|nullable|string|max:1000',
            'work_schedule_id' => 'sometimes|nullable|integer|exists:work_schedules,id',
            'admin_correction_notes' => 'required|string|min:5|max:1000', // Alasan koreksi wajib
        ];
    }

    public function messages(): array
    {
        return [
            'admin_correction_notes.required' => 'Alasan koreksi wajib diisi.',
            'admin_correction_notes.min' => 'Alasan koreksi minimal 5 karakter.',
        ];
    }

    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message'   => 'Data koreksi absensi tidak valid.',
            'errors'    => $validator->errors(),
        ], 422));
    }
}
