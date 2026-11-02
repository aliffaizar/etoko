<?php

namespace App\Concerns;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Records create, update and delete events for the model in `audit_logs` (BR-9).
 *
 * A model opts in by using this trait. Override `auditExclude()` to keep noisy or
 * secret attributes out of the log.
 *
 * @phpstan-require-extends Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(static function (self $model): void {
            $model->recordAudit(AuditLog::EVENT_CREATED, null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(static function (self $model): void {
            $changes = $model->auditableAttributes($model->getChanges());

            if ($changes === []) {
                return;
            }

            $model->recordAudit(
                AuditLog::EVENT_UPDATED,
                array_intersect_key($model->auditableAttributes($model->getOriginal()), $changes),
                $changes,
            );
        });

        static::deleted(static function (self $model): void {
            $model->recordAudit(AuditLog::EVENT_DELETED, $model->auditableAttributes($model->getOriginal()), null);
        });
    }

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * Attributes that must never reach the audit log.
     *
     * @return array<int, string>
     */
    protected function auditExclude(): array
    {
        return ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'updated_at'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExclude()));
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function recordAudit(string $event, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::user();
        $request = request();

        AuditLog::create([
            'user_id' => $user?->getAuthIdentifier(),
            'branch_id' => $user instanceof User ? $user->branch_id : null,
            'event' => $event,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }
}
