<?php

namespace App\Services\GreenApi;

use App\Models\Core\Module;
use App\Models\Core\Tenant;

class WhatsappModuleGuard
{
    public function ensureEnabled(Tenant $tenant): void
    {
        $enabled = Module::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', 'whatsapp')
            ->where('is_enabled', true)
            ->exists();

        if (! $enabled) {
            abort(403, 'WhatsApp module is not enabled for this tenant.');
        }
    }
}
