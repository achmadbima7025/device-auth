<?php

namespace App\Http\Requests;

use App\Models\AttendanceSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class UpdateAttendanceSettingsRequest extends FormRequest
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
        $rules = [
            'settings' => 'required|array', // Semua pengaturan dikirim dalam nested key 'settings'
        ];

        // Aturan dinamis berdasarkan key dan tipe data dari DB
        $dbSettings = AttendanceSetting::all()->keyBy('key');
        $settingsInput = $this->input('settings', []);

        foreach ($settingsInput as $key => $value) {
            if ($dbSettings->has($key)) {
                $settingModel = $dbSettings->get($key);
                $ruleForKey = 'required'; // Semua setting yang dikirim harus punya nilai (bisa diubah jadi 'sometimes')
                switch ($settingModel->data_type) {
                    case 'integer':
                        $ruleForKey .= '|integer';
                        break;
                    case 'boolean':
                        $ruleForKey .= '|boolean';
                        break;
                    case 'time':
                        $ruleForKey .= '|date_format:H:i:s';
                        break;
                    case 'json':
                        // Memvalidasi apakah input adalah array atau string JSON yang valid
                        $ruleForKey = ['required', function ($attribute, $value, $fail) {
                            if (!is_array($value) && (!is_string($value) || json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE)) {
                                $fail(str_replace('settings.', '', $attribute) . ' must be a valid JSON string or an array.');
                            }
                        }];
                        break;
                    case 'string':
                    case 'text': // Anggap text juga sebagai string untuk validasi dasar
                        $ruleForKey .= '|string|max:2000'; // Max length bisa disesuaikan
                        break;
                    case 'decimal':
                        $ruleForKey .= '|numeric';
                        break;
                }
                $rules['settings.' . $key] = $ruleForKey;
            } else {
                // Jika ada key yang dikirim tapi tidak ada di DB, bisa ditambahkan aturan untuk mengabaikan atau error
                // $rules['settings.' . $key] = 'prohibited'; // Contoh: melarang key yang tidak dikenal
            }
        }
        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.required' => 'Data pengaturan wajib diisi.',
            'settings.array' => 'Data pengaturan harus berupa array.',
            // Pesan kustom untuk setiap key bisa ditambahkan di sini jika perlu
            // 'settings.late_tolerance_minutes.integer' => 'Toleransi keterlambatan harus berupa angka.',
        ];
    }

    protected function failedValidation(Validator|\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message'   => 'Data pengaturan absensi tidak valid.',
            'errors'    => $validator->errors(),
        ], 422));
    }
}
