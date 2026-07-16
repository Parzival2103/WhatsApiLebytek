<?php

namespace App\Services\Messaging;

use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use App\Services\AccountStatusService;
use App\Services\GreenApi\InstanceStateSyncService;
use App\Services\GreenApi\WhatsappModuleGuard;
use Illuminate\Support\Facades\DB;

class MessageSendService
{
    public function __construct(
        private readonly WhatsappModuleGuard $moduleGuard,
        private readonly InstanceStateSyncService $stateSync,
        private readonly AccountStatusService $accountStatusService,
        private readonly RecipientNormalizer $recipientNormalizer,
    ) {}

    /**
     * @return array{mensaje: Mensaje, created: bool}
     */
    public function queueOutbound(
        int $tenantId,
        Instancia $instancia,
        string $recipient,
        string $body,
        string $idempotencyKey,
    ): array {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->moduleGuard->ensureEnabled($tenant);

        if ($instancia->tenant_id !== $tenantId) {
            abort(404);
        }

        // Siempre revalidar contra Green antes de encolar: evita enviar (y contar cuota)
        // cuando la BD aún dice `authorized` tras un logout / desautorización.
        $instancia = $this->stateSync->refreshFromGreen($instancia);

        if ($instancia->status !== 'authorized') {
            abort(409, 'Instance not authorized for sending.');
        }

        $limit = $tenant->messages_monthly_limit;
        if ($limit !== null) {
            $sent = $this->accountStatusService->countMessagesSentThisMonth($tenantId);
            if ($sent >= $limit) {
                abort(429, 'Monthly message quota exceeded.');
            }
        }

        $normalizedRecipient = $this->recipientNormalizer->normalize($recipient);
        $payloadHash = hash('sha256', $tenantId.':'.$idempotencyKey);

        $existing = Mensaje::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('payload_hash', $payloadHash)
            ->first();

        if ($existing !== null) {
            return ['mensaje' => $existing, 'created' => false];
        }

        $mensaje = DB::transaction(function () use ($tenantId, $instancia, $normalizedRecipient, $body, $payloadHash): Mensaje {
            return Mensaje::query()->create([
                'tenant_id' => $tenantId,
                'instancia_id' => $instancia->id,
                'direction' => 'outbound',
                'recipient' => $normalizedRecipient,
                'body' => $body,
                'status' => 'queued',
                'payload_hash' => $payloadHash,
            ]);
        });

        TransactionalMessageJob::dispatch($mensaje->id);

        return ['mensaje' => $mensaje->fresh(), 'created' => true];
    }
}
