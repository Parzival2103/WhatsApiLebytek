<?php

namespace App\Jobs;

use App\Exceptions\GreenApiException;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteGreenInstanceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $instanciaId,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(PartnerClient $partnerClient): void
    {
        $instancia = Instancia::query()->withoutGlobalScope('tenant')->withTrashed()->find($this->instanciaId);

        if ($instancia === null || $instancia->id_instance === null) {
            return;
        }

        try {
            $partnerClient->deleteInstanceAccount($instancia->id_instance);
        } catch (GreenApiException $e) {
            Log::warning('DeleteGreenInstanceJob partner call failed', [
                'instancia_id' => $instancia->id,
                'error' => $e->getMessage(),
            ]);
        }

        $instancia->update(['status' => 'deleted']);
        $instancia->delete();
    }
}
