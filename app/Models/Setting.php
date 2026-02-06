<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'type',
        'encrypted',
        'group',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    /**
     * Get the value attribute with automatic type casting and decryption.
     */
    public function getValueAttribute(?string $rawValue): mixed
    {
        if ($rawValue === null) {
            return null;
        }

        // Get encrypted flag from attributes (handles pre-cast state)
        $encrypted = $this->attributes['encrypted'] ?? false;
        if (is_string($encrypted)) {
            $encrypted = filter_var($encrypted, FILTER_VALIDATE_BOOLEAN);
        }

        // Decrypt if encrypted
        $value = $encrypted ? $this->decryptValue($rawValue) : $rawValue;

        // Cast based on type
        $type = $this->attributes['type'] ?? 'string';

        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Set the value attribute with automatic type conversion and encryption.
     */
    public function setValueAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['value'] = null;

            return;
        }

        // Get type from attributes (may not be cast yet during mass assignment)
        $type = $this->attributes['type'] ?? $this->type ?? 'string';
        $encrypted = $this->attributes['encrypted'] ?? $this->encrypted ?? false;

        // Convert to string based on type
        $stringValue = match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };

        // Encrypt if needed
        $this->attributes['value'] = $encrypted
            ? Crypt::encryptString($stringValue)
            : $stringValue;
    }

    /**
     * Decrypt an encrypted value.
     */
    private function decryptValue(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            // Return raw value if decryption fails (e.g., not actually encrypted)
            return $value;
        }
    }
}
