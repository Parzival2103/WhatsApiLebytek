# Live saturation tests

Opt-in Pest suites that hit a **real** demo tenant on `api.lebytek.com` (or another base URL).

| File | What it proves |
|------|----------------|
| `MessageMonthlyQuotaSaturationTest.php` | Fill `min(100, remaining)`, then 5× monthly `429` when that drains the month (e.g. 59+5); if remaining > 100 only burns 100+5 under quota |
| `RedisRateLimitBufferTest.php` | Jobs leave Redis/Horizon without exceeding **30 sent / 60s** per tenant; prints cadence for inbox review |

## CI / default `php artisan test`

Without `RUN_SATURATION_TESTS=1`, both tests **soft-pass** immediately (no Live HTTP, no demo quota spend). Safe for GitHub Actions.

## Manual run (PowerShell)

Spends real demo messages into **your** WhatsApp number.

```powershell
$env:RUN_SATURATION_TESTS = "1"
$env:SATURATION_DEMO_TOKEN = "<sanctum token from demo email / panel>"
$env:SATURATION_INSTANCE_PUBLIC_ID = "<authorized instance publicId>"
$env:SATURATION_RECIPIENT = "521XXXXXXXXXX"   # digits only, your inbox
$env:SATURATION_API_BASE_URL = "https://api.lebytek.com"
# If Windows cURL error 60 (SSL CA): 
# $env:SATURATION_SSL_VERIFY = "0"

# Optional for buffer test (31–40, default 35):
# $env:SATURATION_BUFFER_COUNT = "35"

php artisan test --filter=MessageMonthlyQuotaSaturation
php artisan test --filter=RedisRateLimitBuffer
```

If env vars are omitted in a TTY, the gate prompts for token / instance / recipient.

## Timing expectations

- HTTP route limiter `messages-send` = **10/min** → pauses ~1 min every batch of 10 (expected; not the Redis job buffer).
- Job middleware `RateLimitedWithRedis` = **30/min per tenant** → inbox spacing after enqueue.
- Same number or 100 different numbers: same job throttle (keyed by tenant, not chat).

Prefer **one recipient** (your phone) so you can watch spam spacing in a single chat.

## Prechecks

- Quota test: reads `account/status` first. Fill = `min(100, remaining)`. Monthly `429` asserted only when fill drains all remaining.
- Buffer test needs remaining ≥ burst count (default 35).
