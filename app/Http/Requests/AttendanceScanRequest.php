<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

//use Illuminate\Validation\Validator;

class AttendanceScanRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'qr_payload' => 'required|array',
            'qr_payload.type' => 'required|string|in:attendance_scan', // Pastikan tipe payload sesuai
            'qr_payload.token' => 'required|string', // Asumsi token ada di payload QR
            'qr_payload.date' => 'required|date_format:Y-m-d', // Asumsi tanggal ada di payload QR
            'qr_payload.location_name' => 'sometimes|string|max:100', // Opsional, tergantung desain QR
            'client_timestamp' => 'required|date_format:Y-m-d\TH:i:sP', // ISO 8601 Timestamp
            'location' => 'sometimes|nullable|array',
            'location.latitude' => 'required_with:location|nullable|numeric|between:-90,90',
            'location.longitude' => 'required_with:location|nullable|numeric|between:-180,180',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'qr_payload.required' => 'Payload QR code wajib diisi.',
            'qr_payload.array' => 'Payload QR code harus berupa array.',
            'qr_payload.type.required' => 'Tipe dalam payload QR wajib diisi.',
            'qr_payload.type.in' => 'Tipe payload QR tidak valid untuk absensi.',
            'qr_payload.token.required' => 'Token dalam payload QR wajib diisi.',
            'qr_payload.date.required' => 'Tanggal dalam payload QR wajib diisi.',
            'qr_payload.date.date_format' => 'Format tanggal dalam payload QR harus YYYY-MM-DD.',
            'client_timestamp.required' => 'Timestamp klien wajib diisi.',
            'client_timestamp.date_format' => 'Format timestamp klien tidak valid (harus ISO 8601).',
            'location.array' => 'Data lokasi harus berupa array.',
            'location.latitude.required_with' => 'Latitude wajib diisi jika data lokasi diberikan.',
            'location.latitude.numeric' => 'Latitude harus berupa angka.',
            'location.latitude.between' => 'Latitude harus antara -90 dan 90.',
            'location.longitude.required_with' => 'Longitude wajib diisi jika data lokasi diberikan.',
            'location.longitude.numeric' => 'Longitude harus berupa angka.',
            'location.longitude.between' => 'Longitude harus antara -180 dan 180.',
        ];
    }

    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message'   => 'Data yang diberikan tidak valid.',
            'errors'    => $validator->errors(),
        ], 422));
    }
}
