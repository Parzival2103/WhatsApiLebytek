# Instalación en WhatsApiLebytek

Guía para la cadena portable en `Parzival2103/WhatsApiLebytek` (api.lebytek.com).

El kit **vive en este repo** bajo `docs/automation/`. No hace falta copiar desde
Framework (migración completada desde Framework PR #65).

## 1. Layout

```
docs/automation/
├── AUTOMATION-00-daily-audit.md … AUTOMATION-08-plan-closure.md
├── REPO-PROFILE.md              # perfil activo
├── REPO-PROFILE.example.md
├── profiles/WhatsApiLebytek.md
├── KIT-README.md
├── README.md                    # roadmap 00–08
├── CONTEXT.md                   # stack del API (conservar)
└── INSTALL-WhatsApiLebytek.md   # este archivo
```

Artefactos de salida:

```
docs/audits/
docs/superpowers/specs/
docs/superpowers/plans/
docs/automation-reports/
docs/archive/superpowers/plans/
```

El prompt legacy «Daily SaaS Technical Audit» está en
`docs/automation/archive/AUTOMATION-01-daily-audit.LEGACY.md`.

## 2. Cursor Automations (9)

Crea **nueve** automations en el repo `WhatsApiLebytek`, branch `main`:

| Orden | Archivo a pegar | Cron sugerido |
|-------|-----------------|---------------|
| 00 | `AUTOMATION-00-daily-audit.md` → bloque Prompt | inicio cadena |
| 01 | `AUTOMATION-01-daily-spec.md` | +30 min |
| 02 | `AUTOMATION-02-audit-tech-debt.md` | +30 min |
| 03 | `AUTOMATION-03-audit-ux.md` | +30 min |
| 04 | `AUTOMATION-04-plan-writer.md` | +30 min |
| 05 | `AUTOMATION-05-wha-notify.md` | +30 min |
| 06 | `AUTOMATION-06-plan-readiness-gate.md` | tras 05 |
| 07 | `AUTOMATION-07-plan-executor.md` | solo si 06 READY |
| 08 | `AUTOMATION-08-plan-closure.md` | tras 07 |

Secrets (05 y 08): `LEBYTEK_API_URL`, `LEBYTEK_API_TOKEN`,
`LEBYTEK_INSTANCE_PUBLIC_ID`, `AUDIT_PLAN_WHATSAPP_TO`.

Permisos: Git write en 07–08; `gh pr merge` en 03 (audit) y 08 (cierre).

## 3. Diferencias vs Framework (recordatorio)

| Tema | WhatsApi |
|------|----------|
| Tests | `composer test` / Pest |
| UX 03 | Contrato HTTP + OpenAPI + admin Inertia |
| Legacy refs | vacías → comprobación vacua |
| Deploy | prohibido en automation (SSH VPS solo sesión humana) |
| Idempotency WhatsApp | prefijos `waapi-audit-plan` / `waapi-audit-closure` |

## 4. Primera corrida

1. Deshabilita la automation antigua «Daily SaaS Technical Audit» si aún existe.
2. Corre 00 en manual; verifica PR `docs(audit):` y reporte bajo `docs/audits/`.
3. Deja correr 01–05; valida WhatsApp.
4. Activa 06–08 en modo verificación hasta el primer ciclo completo con plan real.
