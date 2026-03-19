<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function created(Model $model): void
    {
        AuditLog::record(
            AuditAction::ModelCreated,
            $model,
            newValues: $this->filterAttributes($model->getAttributes(), $model),
        );
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();

        // Skip if only timestamps changed
        $meaningful = array_diff_key($changes, array_flip(['created_at', 'updated_at']));
        if (empty($meaningful)) {
            return;
        }

        $old = [];
        $new = [];
        foreach ($meaningful as $key => $value) {
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $value;
        }

        $old = $this->filterAttributes($old, $model);
        $new = $this->filterAttributes($new, $model);

        AuditLog::record(AuditAction::ModelUpdated, $model, oldValues: $old, newValues: $new);
    }

    public function deleted(Model $model): void
    {
        AuditLog::record(
            AuditAction::ModelDeleted,
            $model,
            oldValues: $this->filterAttributes($model->getAttributes(), $model),
        );
    }

    /**
     * Remove hidden attributes (password, remember_token) and timestamps from audit data.
     * Mask password if present rather than exposing the hash.
     */
    private function filterAttributes(array $attributes, Model $model): array
    {
        // Remove timestamps
        unset($attributes['created_at'], $attributes['updated_at']);

        // Mask hidden attributes
        foreach ($model->getHidden() as $hidden) {
            if (array_key_exists($hidden, $attributes)) {
                $attributes[$hidden] = '[hidden]';
            }
        }

        return $attributes;
    }

    /**
     * Register this observer on multiple models.
     *
     * @param  array<class-string<Model>>  $models
     */
    public static function observe(array $models): void
    {
        foreach ($models as $model) {
            $model::observe(static::class);
        }
    }
}
