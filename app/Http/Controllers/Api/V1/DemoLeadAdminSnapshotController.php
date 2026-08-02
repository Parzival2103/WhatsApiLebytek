<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DemoLeadSnapshotBatchRequest;
use App\Services\DemoLeadAdminSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Admin
 *
 * @authenticated
 */
class DemoLeadAdminSnapshotController extends Controller
{
    public function __construct(
        private readonly DemoLeadAdminSnapshotService $snapshotService,
    ) {}

    /**
     * Batch snapshot for admin demo leads (instance state + tenant activity).
     */
    public function batch(DemoLeadSnapshotBatchRequest $request): JsonResponse
    {
        $this->ensurePlatformService($request);

        /** @var list<array{tenantPublicId: string, instancePublicId?: string|null}> $items */
        $items = $request->validated('items');

        return response()->json([
            'items' => $this->snapshotService->buildSnapshotMap($items),
        ]);
    }

    private function ensurePlatformService(Request $request): void
    {
        if (! $request->user()?->isPlatformAdmin()) {
            abort(403, 'Platform service access required.');
        }
    }
}
