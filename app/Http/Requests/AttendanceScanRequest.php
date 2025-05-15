<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

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
            'qr_payload.required' => 'QR code payload is required.',
            'qr_payload.array' => 'QR code payload must be an array.',
            'qr_payload.type.required' => 'Type in QR payload is required.',
            'qr_payload.type.in' => 'QR payload type is not valid for attendance.',
            'qr_payload.token.required' => 'Token in QR payload is required.',
            'qr_payload.date.required' => 'Date in QR payload is required.',
            'qr_payload.date.date_format' => 'Date format in QR payload must be YYYY-MM-DD.',
            'client_timestamp.required' => 'Client timestamp is required.',
            'client_timestamp.date_format' => 'Client timestamp format is invalid (must be ISO 8601).',
            'location.array' => 'Location data must be an array.',
            'location.latitude.required_with' => 'Latitude is required when location data is provided.',
            'location.latitude.numeric' => 'Latitude must be a number.',
            'location.latitude.between' => 'Latitude must be between -90 and 90.',
            'location.longitude.required_with' => 'Longitude is required when location data is provided.',
            'location.longitude.numeric' => 'Longitude must be a number.',
            'location.longitude.between' => 'Longitude must be between -180 and 180.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message'   => 'The provided data is invalid.',
            'errors'    => $validator->errors(),
        ], 422));
    }
}
