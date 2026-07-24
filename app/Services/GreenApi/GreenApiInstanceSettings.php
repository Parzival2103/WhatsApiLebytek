<?php

namespace App\Services\GreenApi;

/**
 * Instance-level Green API setting defaults used by provisioning and ops commands.
 */
final class GreenApiInstanceSettings
{
    /** Green-recommended send delay (ms) to mitigate Yellow Card / spam pacing. */
    public const DELAY_SEND_MESSAGES_MILLISECONDS = 15000;
}
