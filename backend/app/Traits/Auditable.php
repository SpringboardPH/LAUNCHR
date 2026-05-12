<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable()
    {
        static::created(function (Model $model) {
            static::logAudit($model, 'created');
        });

        static::updated(function (Model $model) {
            static::logAudit($model, 'updated');
        });

        static::deleted(function (Model $model) {
            static::logAudit($model, 'deleted');
        });
    }

    protected static function logAudit(Model $model, $event)
    {
        $oldValues = null;
        $newValues = null;

        if ($event === 'updated') {
            $oldValues = array_intersect_key($model->getOriginal(), $model->getDirty());
            $newValues = $model->getDirty();
        } elseif ($event === 'created') {
            $newValues = $model->getAttributes();
        } elseif ($event === 'deleted') {
            $oldValues = $model->getAttributes();
        }

        // Filter sensitive fields
        $hidden = array_merge($model->getHidden(), ['password', 'remember_token']);
        if ($oldValues) $oldValues = array_diff_key($oldValues, array_flip($hidden));
        if ($newValues) $newValues = array_diff_key($newValues, array_flip($hidden));
        
        // Skip if nothing changed for updates
        if ($event === 'updated' && empty($newValues)) {
            return;
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event . '_' . strtolower(class_basename($model)),
            'auditable_type' => get_class($model),
            'auditable_id' => $model->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => ucfirst($event) . " " . class_basename($model) . " (ID: {$model->id})",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
