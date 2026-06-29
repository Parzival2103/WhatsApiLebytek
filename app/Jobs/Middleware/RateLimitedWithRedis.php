<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

class RateLimitedWithRedis
{
    public function __construct(
        private readonly string $key,
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        Redis::throttle($this->key)
            ->block(0)
            ->allow($this->maxAttempts)
            ->every($this->decaySeconds)
            ->then(function () use ($job, $next): void {
                $next($job);
            }, function () use ($job): void {
                $job->release($this->decaySeconds);
            });
    }
}
