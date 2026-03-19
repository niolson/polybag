<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Setting;

class SettingObserver
{
    public function created(Setting $setting): void
    {
        AuditLog::record(
            AuditAction::SettingChanged,
            $setting,
            newValues: ['value' => $this->displayValue($setting)],
            metadata: ['key' => $setting->key, 'group' => $setting->group],
        );
    }

    public function updated(Setting $setting): void
    {
        if (! $setting->wasChanged('value')) {
            return;
        }

        $isEncrypted = $setting->encrypted;

        AuditLog::record(
            AuditAction::SettingChanged,
            $setting,
            oldValues: ['value' => $isEncrypted ? '[encrypted]' : $setting->getOriginal('value')],
            newValues: ['value' => $this->displayValue($setting)],
            metadata: ['key' => $setting->key, 'group' => $setting->group],
        );
    }

    private function displayValue(Setting $setting): mixed
    {
        return $setting->encrypted ? '[encrypted]' : $setting->getRawOriginal('value');
    }
}
