# Spec — Gate pre-brainstorm (cierre ops + verificación E2E)

**Fecha:** 2026-07-02  
**Repo fuente de verdad:** `WhatsApiLebytek`  
**Estado:** Propuesto — **bloqueante** antes del siguiente brainstorm (nuevas features: campañas, facturación, etc.)  
**Tipo:** Cierre operativo + docs menores — **no** nuevo vertical de código salvo fixes de smoke

**Specs previos:**
- [2026-07-02-integration-roadmap-remediation-design.md](2026-07-02-integration-roadmap-remediation-design.md)
- [2026-07-02-integration-phase4-5-design.md](2026-07-02-integration-phase4-5-design.md)

---

## 1. Resumen de auditoría (2026-07-02)

Revisión de repos locales + tests + checklists. **Conclusión:** el trabajo de **código** de remediación (2a) y Fase 4 (portal waapi) está **implementado**. Lo que falta es casi todo **ops/VPS/smoke documentado** y **go-live** (Fase 3). No hace falta un spec de features nuevo antes del siguiente brainstorm; hace falta **este gate** para no construir encima de humo.

### 1.1 ✅ Implementado en código (no rehacer)

| Área | Entregable | Evidencia |
|------|------------|-----------|
| **0/1** | E2E back-office | `LeadApiProvisioningService`, CRUD, tests, correo |
| **2b** | Lifecycle demo | `LeadApiDeprovisioningService`, `expire-api-demos.php`, `demo_baja` |
| **2a** | `/messages` MVP | `routes/api.php`, `MessageController`, `TransactionalMessageJob`, migraciones `int_mensajes` + `int_webhooks` |
| **2a** | Permisos mensajes | `mensajes.enviar`, `mensajes.ver` — tests `MessageSendTest` (9 passed) |
| **2a** | Token demo ampliado | `['instancias.ver','mensajes.enviar','mensajes.ver']` en Framework |
| **2a** | Smoke script | `Lebytek_Framework/scripts/smoke-send-test-message.php` |
| **4** | Portal waapi | `WaapiPortalController`, `routes/waapi_portal.php`, `WaapiPortalSession`, tests |
| **4** | Correo dashboard | `MKT_EMAIL_DASHBOARD_URL`, CTA en `lead_api_credentials.php` |
| **5 parcial** | Credentials 501 | `CredentialsController`, `CredentialsStubTest` |
| **5 parcial** | Register prod off | `routes/auth.php` redirect en `production` |
| **Docs** | Roadmap honesto | `docs/integration/README.md` tabla fases |
| **Docs** | Guía portal | `docs/guides/portal-cliente-waapi.md` |
| **Docs** | Contrato `/messages` | `waapi-api-contract.md` § Fase 2a implementados |

**Commits recientes relevantes:**

- api: `dcc46d0` messages MVP, `a454121` credentials stub + portal docs
- Framework: `0868af2` waapi portal, `e6302e2` mensajes abilities

### 1.2 ⏳ Pendiente — bloquea gate (ops, no código nuevo)

| Ítem | Tipo | Por qué bloquea |
|------|------|-----------------|
| Smoke paso 2: QR → instancia `authorized` | VPS manual | Sin esto no se valida producto WhatsApp |
| Smoke paso 3: mensaje recibido en móvil | VPS manual | Criterio §6 remediación |
| Smoke paso 4: baja demo E2E | VPS manual | Cierra ciclo 2b en prod |
| Crons health + expire | VPS crontab | Checklist R2 sin confirmar |
| Deploy api VPS con migraciones `int_mensajes` | Ops | Código local ≠ prod hasta pull+migrate |
| Deploy waapi `WAAPI_PORTAL_ENABLED=true` | Ops | Portal solo en código |
| Smoke panel waapi (login token → dashboard → QR) | VPS manual | Fase 4 aceptación |
| `docs.lebytek.com` deploy live | Ops | Correo CTA docs; sync puede estar desactualizado |
| DNS cutover `lebytek.com` | Ops | **Solo tras** smoke mensaje verde |
| Marcar checklists con fechas reales | Docs | Evitar `[x]` sin evidencia |

### 1.3 📋 Diferido — **después** del gate (siguiente brainstorm)

Estos ítems **no** bloquean el gate; van en el próximo spec de producto/madurez:

| Ítem | Notas |
|------|-------|
| `/campaigns` MVP | Contrato sigue "planned" — correcto |
| Webhook `int_webhooks` persist + inbound messages | Tabla existe; controller solo `stateInstanceChanged` |
| `TenantUsageService` hook | `docs/P2_BACKLOG.md` |
| Sentry / Horizon alerts | P2 |
| 2FA admin | P2 |
| `PUT /credentials` BYO (no 501) | Backlog |
| Renombrar `WAAPI_SERVICE_*` → `PLATFORM_*` | P2 |
| Eliminar legacy Green UI Framework | Post-cutover 30 días |
| Facturación automática | Fuera roadmap |
| Merge Framework `feature/backoffice-api-integration` → `main` | **Solo con orden explícita del usuario** — regla Cursor `no-merge-framework-main` |

### 1.4 ⚠️ Deuda documental menor (incluir en gate, 1 PR)

| Archivo | Problema | Fix |
|---------|----------|-----|
| `waapi-api-contract.md` | `PUT /credentials` sin nota **501 implementado** | Añadir subsección |
| `2026-07-02-integration-phase4-5-design.md` §0 | Tabla audit desactualizada (dice messages ❌) | Banner "superseded by gate spec" |
| `2026-07-02-integration-roadmap-remediation-design.md` §6 | Checkboxes sin marcar aunque código listo | Actualizar tras smoke ops |
| `docs.lebytek.com` mirror | Puede ir detrás de api si no se corrió sync | Task gate G4 |
| Sección legacy waapi en `VPS_CHECKLIST.md` | Confunde con Fase 4 nueva | Deprecar o consolidar |

---

## 2. Problema

El equipo puede interpretar “todo implementado” como **listo para producción y para el siguiente brainstorm**. En realidad:

- Tests CI pasan **en local** (`MessageSendTest`, portal tests).
- **Ningún smoke** documenta mensaje WhatsApp real en móvil ni panel waapi en VPS.
- **DNS** no está cerrado; **merge a `main`** está fuera de alcance hasta orden explícita.
- Construir campañas, facturación o webhooks inbound **antes** del gate repite el error de Fase 2/3 (código adelantado, ops atrasadas).

**Objetivo de este spec:** un único sprint **solo ops + smoke + docs menores** (~1–3 días) que desbloquee el siguiente brainstorm.

---

## 3. Alcance del gate (G0–G5)

### Fuera de alcance

- Nuevo código de campañas, facturación, Sentry, webhooks inbound
- Hook automático provisioning al aprobar lead
- Cambios arquitectónicos

### G0 — Deploy código actual en VPS

| Sitio | Acción |
|-------|--------|
| api.lebytek.com | `git pull`, `composer install`, `php artisan migrate --force`, restart Horizon |
| lebytek.com | Pull `feature/backoffice-api-integration`, `composer install`, migrate scripts |
| waapi.lebytek.com | Mismo tree Framework; `.env`: `WAAPI_PORTAL_ENABLED=true` |

Verificar commits mínimos en VPS:

- api ≥ `dcc46d0` (messages)
- Framework ≥ `0868af2` (portal)

### G1 — Crons (R2)

Instalar y capturar en checklist:

```cron
*/5 * * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php >> storage/logs/api-health.log 2>&1
0 3 * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/expire-api-demos.php 30 >> storage/logs/expire-demos.log 2>&1
```

### G2 — Smoke E2E integrado (documentar fecha + operador)

Ejecutar secuencia **una sola vez** en prod/staging VPS; rellenar tabla en `VPS_CHECKLIST.md`:

| # | Paso | Comando / acción | Pass | Fecha |
|---|------|------------------|------|-------|
| 1 | Provision demo | CRUD lead `validada` → Provisionar | [ ] | |
| 2 | Correo | Token + base URL + CTA waapi (si URL set) | [ ] | |
| 3 | QR | api `GET /instances/{id}/qr` o panel waapi → escanear | [ ] | |
| 4 | Authorized | `GET /instances/{id}` → `status=authorized` | [ ] | |
| 5 | Mensaje | `php scripts/smoke-send-test-message.php ...` → WhatsApp en móvil | [ ] | |
| 6 | Panel waapi | Login token → dashboard → QR coherente | [ ] | |
| 7 | Baja demo | Dar de baja → `demo_baja`, instancias gone en api | [ ] | |

**Gate hard:** pasos **4, 5 y 6** deben ser ✅ antes de G3.

### G3 — Go-live mínimo (Fase 3)

Solo si G2 verde:

1. `node scripts/sync-docs.mjs` (repo docs.lebytek.com) + deploy nginx
2. DNS cutover `lebytek.com` (TTL bajo 24h antes)
3. Deploy lebytek desde `feature/backoffice-api-integration` (no merge a `main` salvo orden explícita — regla Cursor)
4. Smoke post-cutover: landing + admin + paso 1 provisioning

### G4 — Docs cierre (1 PR api + mirror Framework)

- Marcar remediación §6.1–6.3 según G2
- Actualizar `README.md` roadmap: 2a ✅ smoke, 3 ✅/⏳, 4 ✅ código / ✅ VPS
- Contrato: nota 501 en `/credentials/green-api`
- Eliminar o archivar sección "waapi legacy diferido" duplicada en checklist

### G5 — Declaración go/no-go

Plantilla en `VPS_CHECKLIST.md`:

```markdown
## Gate pre-brainstorm — GO/NO-GO (fecha: ______)

- [ ] G0 deploy VPS
- [ ] G1 crons
- [ ] G2 smoke E2E (pasos 4–6)
- [ ] G3 go-live (si aplica este sprint)
- [ ] G4 docs

**Decisión:** GO / NO-GO para siguiente brainstorm
**Operador:** ______
```

---

## 4. Criterios de aceptación — gate completo

- [ ] api VPS migraciones `int_mensajes` aplicadas; Horizon RUNNING
- [ ] Mensaje WhatsApp **recibido en móvil** vía `POST /messages` (evidencia: fecha en checklist)
- [ ] waapi.lebytek.com portal accesible con token demo
- [ ] Crons health + expire confirmados (`crontab -l` pegado en checklist)
- [ ] `docs.lebytek.com` responde con contrato § Fase 2a
- [ ] Ningún doc dice "Fase 2/3 completa" sin distinguir código vs ops
- [ ] Framework en `main` **o** decisión explícita de posponer DNS con razón documentada
- [ ] Sección G5 marcada GO

**Si NO-GO:** no iniciar brainstorm de campañas/facturación; solo fixes del fallo documentado.

---

## 5. Qué sigue después del gate (preview brainstorm)

Tras **GO**, el siguiente brainstorm puede abordar (orden sugerido):

| Prioridad | Tema | Spec nuevo sugerido |
|-----------|------|---------------------|
| 1 | Campañas MVP api | `dom_campanias` + dispatch |
| 2 | Webhooks inbound + persist `int_webhooks` | Extensión `IncomingWebhookController` |
| 3 | Usage metering + cuotas demo | `TenantUsageService` |
| 4 | Observabilidad prod | Sentry, Horizon alerts |
| 5 | Facturación manual → automática | Producto aparte |

---

## 6. Orden de ejecución (1 sprint)

| Día | Tareas |
|-----|--------|
| D1 | G0 deploy api + lebytek + migrate; G1 crons |
| D1–D2 | G2 smoke E2E (pasos 1–7) |
| D2 | G4 docs PR; waapi deploy si no en D1 |
| D3 | G3 go-live (si G2 verde); G5 declaración |

**Estimación:** 1–3 días operador + 1 PR docs.

---

## 7. Aprobación

Confirmar:

1. Gate = **solo ops/smoke/docs** — sin campañas ni webhooks inbound
2. DNS cutover solo tras paso 5 smoke mensaje
3. NO-GO explícito si falta evidencia móvil

**Siguiente paso tras GO:** brainstorming campañas / madurez P2 (spec nuevo).  
**Siguiente paso inmediato:** plan opcional `docs/superpowers/plans/2026-07-02-integration-pre-brainstorm-gate.md` (checklist G0–G5).

---

## 8. Referencias

| Recurso | Ruta |
|---------|------|
| Checklist VPS | `docs/integration/VPS_CHECKLIST.md` |
| Smoke mensaje | `Lebytek_Framework/scripts/smoke-send-test-message.php` |
| Portal guía | `docs/guides/portal-cliente-waapi.md` |
| Remediation spec | `2026-07-02-integration-roadmap-remediation-design.md` |
