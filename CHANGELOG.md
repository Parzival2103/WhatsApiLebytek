# Changelog

All notable changes to **WhatsApiLebytek** (`api.lebytek.com`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-14

First stable production release. Deploy branch: `main` → `api.lebytek.com`.

### Added

- Multi-tenant REST API under `/api/v1` with Laravel Sanctum tokens and RBAC (Spatie Permission)
- Green API vertical: instances, partner teardown, transactional and campaign message send
- WhatsApp recipients: phone numbers and group `chatId` formats via `RecipientNormalizer`
- Webhooks with signature verification and idempotency
- Redis queues and Laravel Horizon workers (transactional, campaigns, provisioning)
- Tenant commercial fields, account status, and `GET /usage` message metrics
- Demo sandbox hardening (permissions, CORS, state sync, rate limits)
- API docs via Scribe (`/docs`) and integration contract for waapi back-office
- Observability health endpoint and CI workflow (Pest, PHP 8.3, Redis)
- Secure uploads / archivo serving and public landing (Inertia + Vue)

### Fixed

- Green partner `deleteInstanceAccount` aligned with POST contract
- Tenant demo tokens default abilities include `cuenta.ver`
- Scribe available in production so `/docs` does not 404
- Horizon bootstrap and queue job stubs for reliable worker startup

### Docs

- Integration contract (`docs/integration/waapi-api-contract.md`) and role delegation guides
- Deploy runbook (`docs/DEPLOY.md`) and automation safety context
- Gate evidence for VPS deploy, crons, and portal cutover

[Unreleased]: https://github.com/Parzival2103/WhatsApiLebytek/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Parzival2103/WhatsApiLebytek/releases/tag/v1.0.0
