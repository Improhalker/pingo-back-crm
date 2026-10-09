<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Financial audit (ARCHITECTURE §17). Called by the Actions inside their transaction, so a failure here
 * rolls the financial change back. Only relevant fields; never secrets.
 */
class AuditTrail
{
    public function __construct(private OrganizationContext $context) {}

    public function record(User $user, string $action, Model $entity, ?array $before, ?array $after): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill([
            'organization_id' => $this->context->organization()->id,
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'previous_data' => $before,
            'new_data' => $after,
            'request_id' => request()?->attributes->get('request_id'),
            'created_at' => now('UTC'),
        ])->save();

        return $log;
    }
}
