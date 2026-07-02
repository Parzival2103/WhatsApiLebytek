<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Credentials
 *
 * BYO Green API credentials — not implemented in v1.
 */
class CredentialsController extends Controller
{
    /**
     * Update Green API credentials for an instance (platform only).
     */
    public function updateGreenApi(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Not implemented',
            'detail' => 'Demo instances use partner-provisioned credentials. BYO credentials are planned for a future release.',
        ], 501);
    }
}
