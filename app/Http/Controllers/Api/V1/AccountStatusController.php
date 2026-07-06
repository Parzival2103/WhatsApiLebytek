<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccountStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Account
 *
 * @authenticated
 */
class AccountStatusController extends Controller
{
    public function __construct(
        private readonly AccountStatusService $accountStatusService,
    ) {}

    /**
     * Account status (demo quota, plan, expiration)
     *
     * Returns days remaining, messages remaining, contracted plan and request timestamp.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id === null) {
            abort(403, 'Tenant token required.');
        }

        $tenant = $user->tenant;

        if ($tenant === null) {
            abort(404, 'Tenant not found.');
        }

        return response()->json($this->accountStatusService->buildStatus($tenant));
    }
}
