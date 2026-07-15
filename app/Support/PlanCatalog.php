<?php

namespace App\Support;

use InvalidArgumentException;

final class PlanCatalog
{
    /**
     * @return array{name: string, messages_monthly_limit: int|null, http_send_per_minute: int, job_send_per_minute: int}|null
     */
    public static function definition(string $slug): ?array
    {
        $plan = config("plans.catalog.{$slug}");

        return is_array($plan) ? $plan : null;
    }

    public static function resolveMessagesMonthlyLimit(string $slug, ?int $override): ?int
    {
        $plan = self::definition($slug);

        if ($plan === null) {
            throw new InvalidArgumentException("Unknown plan slug [{$slug}].");
        }

        if ($slug === 'empresa') {
            if ($override === null) {
                throw new InvalidArgumentException('messagesMonthlyLimit is required for empresa.');
            }

            $min = (int) config('plans.empresa.messages_monthly_limit_min', 1000);
            $max = (int) config('plans.empresa.messages_monthly_limit_max', 10_000_000);

            if ($override < $min || $override > $max) {
                throw new InvalidArgumentException(
                    "messagesMonthlyLimit must be between {$min} and {$max} for empresa."
                );
            }

            return $override;
        }

        return $plan['messages_monthly_limit'];
    }
}
