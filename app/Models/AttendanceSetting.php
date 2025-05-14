<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'data_type', 'description', 'group', // 'scope_type', // Jika ada scope
        // 'scope_id',   // Jika ada scope
    ];

    protected $casts = [// Casting akan dilakukan di accessor/mutator berdasarkan 'data_type'
    ];

    public static function getByKey(string $key, $default = null, $scopeType = 'global', $scopeId = null)
    {
        // Implementasi scope jika ada
        // $query = self::where('key', $key)->where('scope_type', $scopeType);
        // if ($scopeId) {
        //     $query->where('scope_id', $scopeId);
        // } else {
        //     $query->whereNull('scope_id');
        // }
        // $setting = $query->first();

        $setting = self::where('key', $key)->first(); // Versi sederhana tanpa scope
        return $setting ? $setting->value : $default;
    }

    public function getValueAttribute($originalValue)
    {
        if (is_null($originalValue)) {
            return null;
        }

        switch ($this->data_type) {
            case 'integer':
                return (int)$originalValue;
            case 'boolean':
                // Memastikan 'false' string juga di-cast benar
                if (strtolower($originalValue) === 'false') return false;
                return filter_var($originalValue, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($originalValue, true);
            case 'time': // Misal disimpan sebagai HH:MM:SS
                return $originalValue; // Bisa di-parse ke Carbon di service jika perlu
            case 'decimal': // Jika ada tipe decimal
                return (float)$originalValue;
            default: // string
                return $originalValue;
        }
    }

    public function setValueAttribute($inputValue)
    {
        if ($this->data_type === 'boolean') {
            $this->attributes['value'] = filter_var($inputValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        } elseif ($this->data_type === 'json' && (is_array($inputValue) || is_object($inputValue))) {
            $this->attributes['value'] = json_encode($inputValue);
        } elseif ($this->data_type === 'json' && is_string($inputValue) && json_decode($inputValue) !== null) {
            // Jika input sudah string JSON, simpan apa adanya
            $this->attributes['value'] = $inputValue;
        } else {
            $this->attributes['value'] = $inputValue;
        }
    }
}
