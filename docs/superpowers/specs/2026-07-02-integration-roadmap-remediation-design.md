# Spec — Remediación alineación roadmap integración (Fases 0–3)

**Fecha:** 2026-07-02  
**Repo fuente de verdad:** `WhatsApiLebytek`  
**Repos afectados:** `WhatsApiLebytek`, `Lebytek_Framework`, `docs.lebytek.com`, VPS  
**Estado:** Propuesto — pendiente de aprobación  
**Propósito:** Corregir la **desalineación** entre lo declarado como “Fases 2/3 completadas” y el estado real de código/docs/ops.

**Specs relacionados (no reemplazan este):**
- [2026-07-01-integration-phase2-3-design.md](2026-07-01-integration-phase2-3-design.md) — spec original 2/3
- [2026-07-02-integration-phase4-5-design.md](2026-07-02-integration-phase4-5-design.md) — continúa **después** de esta remediación

---

## 1. Resumen ejecutivo

Se ejecutaron con éxito **Fase 0/1** (integración back-office ↔ api, provisioning demo, correo, tests) y un **bloque extra no planificado** en Framework (baja demo, expiración, estados CRUD). Ese bloque extra es **valioso** pero **no sustituye** lo que el spec 2/3 original definía como Fase 2 (vertical `/messages` en api) ni Fase 3 (go-live DNS/docs/main).

**Síntoma:** docs/checklists y conversación del equipo dicen “fases 2 y 3 listas”, pero el contrato HTTP y `routes/api.php` siguen sin envío de mensajes; el cliente demo no puede usar el producto más allá de vincular WhatsApp.

**Acción de este spec:** renombrar lo hecho, completar lo faltante, y **no avanzar a Fase 4/5** hasta pasar los criterios de remediación §6.

---

## 2. Revisión detallada — mapa de verdad

### 2.1 Roadmap acordado (4 bloques)

| Bloque | Spec | Objetivo declarado |
|--------|------|-------------------|
| **0/1** | `2026-07-01-integration-e2e-phase0-1-design.md` | E2E ops + back-office operable |
| **2/3** | `2026-07-01-integration-phase2-3-design.md` | Vertical WhatsApp api + go-live producción |
| **4/5** | `2026-07-02-integration-phase4-5-design.md` | Panel waapi + madurez plataforma |

### 2.2 Estado real verificado (código + docs, 2026-07-02)

#### ✅ Completado — Fase 0/1

| Ítem | Evidencia |
|------|-----------|
| Cliente HTTP + tests | `Lebytek_Framework/.../LebytekApiClient.php`, `tests/Integration/` |
| Provisioning demo | `LeadApiProvisioningService` |
| Correo credenciales | `emails/lead_api_credentials.php` |
| CRUD columnas api | `config/cruds/mkt_leads.json` |
| Legacy Green off prod | `IntegrationsController`, `GREEN_API_ENABLED=false` |
| Instancias + tokens api | `WhatsApiLebytek/routes/api.php`, tests Feature |
| Smoke VPS provisioning | `VPS_CHECKLIST.md` § E2E Fase 0 marcado |

#### ✅ Completado — **Extra 2b** (lifecycle demo, no estaba en spec 2/3)

> **Renombrar oficialmente** este trabajo como **“Fase 2b — lifecycle demo”** para no confundirlo con Fase 2 api.

| Ítem | Evidencia |
|------|-----------|
| Deprovision manual | `LeadApiDeprovisioningService`, ruta deprovision-api |
| Estado `demo_baja` | `mkt_leads.json`, `PdoLeadRepository` |
| Expiración 30 días | `scripts/expire-api-demos.php` |
| Acciones CRUD condicionales | `visible_when` validada / demo_enviada |
| `mail_failed` en provision | retorno `status` en `provisionLead()` |
| Cliente list/delete instance | `LebytekApiClient::listInstances/deleteInstance` |

#### ❌ Pendiente — **Fase 2a** (vertical api — spec 2/3 original)

| Ítem | Evidencia de ausencia |
|------|----------------------|
| `POST /messages`, `GET /messages/{id}` | No en `routes/api.php` |
| `GET/POST /campaigns`, dispatch | No en rutas; `CampaignBatchJob` stub |
| Migraciones mensajes/campañas/webhooks | No en `database/migrations/` |
| Permisos `mensajes.*`, `campanias.*` | No en `config/permissions.php` |
| `TransactionalMessageJob` real | `handle()` vacío |
| Webhooks inbound messages | `IncomingWebhookController` solo `stateInstanceChanged` |
| Token demo `mensajes.enviar` | `LeadApiProvisioningService` → `['instancias.ver']` |
| Contrato “planned” actualizado | `waapi-api-contract.md` § Fase 2 sigue planned |

#### ❌ Pendiente — **Fase 3** (go-live — spec 2/3 original)

| Ítem | Evidencia |
|------|-----------|
| Merge `feature/backoffice-api-integration` → `main` | Branch activo |
| DNS `lebytek.com` → VPS | Checklist: “Do not point DNS” |
| `docs.lebytek.com` live | Repo local; deploy no verificado |
| Smoke mensaje WhatsApp post-demo | Imposible sin `/messages` |
| Cron health confirmado | `[ ]` en checklist |
| Specs/plans en git api | Varios archivos untracked localmente |

### 2.3 Matriz de desalineación (qué corregir)

| Área | Declarado | Real | Corrección |
|------|-----------|------|------------|
| Nomenclatura fases | “Fase 2/3 done” | 2b done; 2a y 3 open | Renombrar + completar 2a/3 |
| Contrato HTTP | Cliente puede enviar (Fase 2) | Solo instancias | Implementar `/messages` mínimo |
| Token demo | Debería incluir envío | Solo lectura instancia | Ampliar abilities al provisionar |
| Checklists | Algunos `[x]` sin prueba mensaje | Smoke incompleto | Honestidad + criterios §6 |
| Roadmap siguiente | Saltar a waapi panel | Producto API incompleto | Remediación antes Fase 4 |

---

## 3. Objetivo de remediación

1. **Verdad documental:** roadmap refleja 0/1, **2b**, **2a**, **3**, **4**, **5**.
2. **Producto mínimo vendible:** demo → QR → **enviar 1 mensaje WhatsApp vía api**.
3. **Go-live controlado:** DNS/docs/main solo tras smoke mensaje verde.
4. **Ops cerradas:** crons confirmados en VPS.

**No objetivo:** panel waapi (Fase 4), Sentry (Fase 5), campañas masivas completas (puede ser MVP post-messages).

---

## 4. Enfoque de corrección

### A — Re-etiquetar solo docs (insuficiente)

Actualizar README/specs sin código.

- **Pro:** rápido
- **Contra:** no corrige producto roto

### B — Completar 2a + 3 antes de renombrar (recomendado)

Implementar `/messages` MVP, alinear token/contrato, go-live, luego actualizar docs.

- **Pro:** alinea realidad con promesa comercial
- **Contra:** 1–2 semanas api + ops

### C — Revertir 2b y volver al spec 2/3 literal

Eliminar deprovision/expire.

- **Pro:** spec único
- **Contra:** pierde trabajo útil ya hecho

**Decisión:** **Enfoque B** + **conservar 2b** documentado como sub-fase completada.

---

## 5. Plan de remediación (orden obligatorio)

### R0 — Verdad inmediata (docs, 1 PR)

**Sin tocar código de producto.** Corregir narrativa falsa.

| Tarea | Archivo |
|-------|---------|
| Añadir § “Roadmap real” con 0/1, 2b✅, 2a❌, 3❌ | `docs/integration/README.md` |
| Nota en spec 2/3: “2b implementado; 2a/3 pendiente” | `2026-07-01-integration-phase2-3-design.md` (banner) |
| Desmarcar o acotar checks que implican mensajes/DNS | `VPS_CHECKLIST.md` |
| Commit specs/plans untracked | `docs/superpowers/**` |

Texto canónico para README integration:

```markdown
| Fase | Nombre | Estado |
|------|--------|--------|
| 0/1 | E2E + back-office | ✅ |
| 2b | Lifecycle demo (baja/expiración) | ✅ |
| 2a | Vertical api `/messages` | ⏳ remediación |
| 3 | Go-live DNS/docs/main | ⏳ tras 2a |
| 4/5 | waapi + madurez | 📋 spec listo |
```

### R1 — Vertical api mínimo (Fase 2a corregida)

Implementar **solo** lo necesario para smoke mensaje (reusar diseño §4 spec 2/3):

| # | Entregable | Repo |
|---|------------|------|
| R1.1 | Migración `int_mensajes` (+ `int_webhooks` audit) | api |
| R1.2 | Permisos `mensajes.enviar`, `mensajes.ver` + seeder | api |
| R1.3 | `MessageController` store/show | api |
| R1.4 | `TransactionalMessageJob` + `InstanceClient::sendMessage` | api |
| R1.5 | Tests Pest `MessageSendTest` | api |
| R1.6 | Scribe regen; contrato mueve `/messages` a implementado | api |
| R1.7 | Token demo: `['instancias.ver','mensajes.enviar','mensajes.ver']` | Framework |
| R1.8 | Test integración abilities en provision | Framework |

**YAGNI en R1:** campañas, `PUT /credentials` — quedan planned explícito.

**Smoke R1:**

```bash
# Tras provision demo + QR authorized
curl -X POST "$API/messages" \
  -H "Authorization: Bearer $TENANT_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"recipient":"521XXXXXXXXXX","body":"Test Lebytek API","instancePublicId":"..."}'
```

### R2 — Ops pendientes Fase 0/2b

| # | Entregable |
|---|------------|
| R2.1 | Confirmar crontab `lebytek-api-health.php` (5 min) |
| R2.2 | Documentar crontab `expire-api-demos.php` (diario 03:00) |
| R2.3 | Script smoke mensaje opcional `scripts/smoke-send-test-message.php` (Framework o api) |

### R3 — Go-live (Fase 3 corregida)

**Bloqueado por R1 smoke verde.**

| # | Entregable |
|---|------------|
| R3.1 | `node scripts/sync-docs.mjs` + deploy `docs.lebytek.com` |
| R3.2 | PR merge Framework → `main`, tag semver |
| R3.3 | Deploy VPS desde `main` |
| R3.4 | DNS cutover `lebytek.com` (TTL bajo 24h antes) |
| R3.5 | Smoke post-cutover §6.3 |
| R3.6 | Runbook rollback en checklist |

### R4 — Puerta hacia Fase 4/5

Solo cuando §6 cumplido → ejecutar [2026-07-02-integration-phase4-5-design.md](2026-07-02-integration-phase4-5-design.md).

---

## 6. Criterios de aceptación — remediación completa

### 6.1 Documentación

- [ ] README integration lista fases 0/1, 2b, 2a, 3, 4/5 con estados honestos
- [ ] `waapi-api-contract.md`: `/messages` en sección implementados; campañas siguen planned
- [ ] Ningún doc dice “Fase 2/3 completada” sin distinguir 2a vs 2b
- [ ] Specs/plans en git (no solo untracked local)

### 6.2 Producto api

- [ ] `POST /messages` responde 202 y job envía a Green (Http::fake en CI, real en smoke)
- [ ] `GET /messages/{publicId}` refleja sent/failed
- [ ] Token por-tenant demo incluye `mensajes.enviar`
- [ ] 0 tokens Green en JSON respuesta

### 6.3 Smoke E2E integrado (manual, documentar fecha)

| Paso | Esperado |
|------|----------|
| 1 | Lead `validada` → Provisionar demo → `demo_enviada` + correo |
| 2 | Cliente autoriza WhatsApp (QR vía api) |
| 3 | `POST /messages` con token del correo → WhatsApp recibido en móvil |
| 4 | Dar de baja demo → instancias eliminadas en api, `demo_baja` |
| 5 | `docs.lebytek.com` muestra contrato con `/messages` implementado |

### 6.4 Go-live (si R3 en scope de esta remediación)

- [ ] `lebytek.com` resuelve VPS
- [ ] Framework en `main` desplegado
- [ ] Crons health + expire activos (`crontab -l` capturado en checklist)

---

## 7. Qué sigue después (no mezclar con remediación)

| Orden | Trabajo | Spec |
|-------|---------|------|
| 1 | **Esta remediación R0→R3** | Este documento |
| 2 | Panel waapi cliente | Fase 4 |
| 3 | Campañas MVP, Sentry, usage, legacy cleanup | Fase 5 |
| 4 | Facturación, 2FA, BYO credentials | P2 backlog |

### Backlog priorizado post-remediación

| P | Ítem |
|---|------|
| P0 | R0 docs verdad |
| P0 | R1 `/messages` |
| P0 | R2 crons |
| P1 | R3 go-live |
| P1 | Fase 4 waapi panel |
| P2 | Campañas api |
| P2 | Sentry / TenantUsageService |
| P3 | Eliminar legacy Green UI |

---

## 8. Riesgos

| Riesgo | Mitigación |
|--------|------------|
| Seguir a Fase 4 con api sin mensajes | **Gate §6** — no waapi hasta R1 verde |
| Confusión 2a vs 2b en equipo | Tabla §2.2 en README + este spec |
| Checklist `[x]` incorrectos | R0 desmarcar items DNS/mensaje hasta R3/R1 |
| Re-trabajo campañas | R1 solo transaccional; campañas spec aparte |

---

## 9. Aprobación

Confirmar:

1. Renombrar trabajo lifecycle como **Fase 2b** (no borrar)
2. **R1 messages** es bloqueante antes de waapi/DNS
3. R0 docs puede mergearse **hoy** sin código api

**Siguiente paso:** skill **writing-plans** → `docs/superpowers/plans/2026-07-02-integration-roadmap-remediation.md`

---

## 10. Referencias

| Recurso | Ruta |
|---------|------|
| Spec 2/3 original | `docs/superpowers/specs/2026-07-01-integration-phase2-3-design.md` |
| Spec 4/5 | `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md` |
| Contrato | `docs/integration/waapi-api-contract.md` |
| Lifecycle 2b | `LeadApiDeprovisioningService.php`, `expire-api-demos.php` |
| Job stub | `app/Jobs/TransactionalMessageJob.php` |
