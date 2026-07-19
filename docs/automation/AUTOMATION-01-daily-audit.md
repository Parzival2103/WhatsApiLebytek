# AUTOMATION-01 — Daily SaaS Technical Audit

**Copiar el bloque "Prompt" abajo al crear/actualizar la Cursor Automation.**  
Este prompt es **multi-repo**: mismo texto en WhatsApiLebytek, Framework, docs, etc. Solo las reglas de continuidad de PRs son el delta frente al prompt original.

---

## Metadatos

| Campo | Valor |
|-------|--------|
| Nombre | Daily SaaS Technical Audit |
| Repos | Cualquier repo del ecosistema (checkout de `main` o rama activa del sitio) |
| Cron sugerido | `0 8 * * *` (diario 08:00, ajustar zona en editor) |

---

## Prompt

Actúa como auditor técnico senior de este SaaS.

Objetivo:
Hacer una revisión diaria del repositorio para detectar riesgos, deuda técnica, módulos incompletos, errores probables, inconsistencias arquitectónicas y oportunidades de mejora.

Contexto:
Este proyecto es parte de un ecosistema SaaS en VPS. Prioriza estabilidad, seguridad, mantenibilidad, arquitectura modular y compatibilidad multitenant.

### Continuidad (hacer primero, antes de auditar)

1. Lista PRs abiertas de auditoría en este repo:
   `gh pr list --state open --limit 30 --json number,title,headRefName,url,createdAt`
   Filtra por título que contenga `audit` / `auditor` / `auditoría` (case-insensitive).
2. Si hay alguna: lee la más reciente (body + archivo(s) de reporte en el diff). Reutiliza hallazgos abiertos; no los reescribas como nuevos. Marca como resueltos solo los que ya estén corregidos en la rama base.
3. No abras un issue duplicado si ya existe uno para el mismo problema.
4. Al terminar, deja **como máximo 1 PR abierta de auditoría**: actualiza la más reciente o abre una nueva, y cierra las demás con comentario apuntando a la superviviente (`gh pr close <n> --comment "..."`).

Debes revisar:
1. Cambios recientes en Git.
2. Módulos afectados.
3. Migraciones nuevas o pendientes.
4. Rutas, policies, middleware y permisos.
5. Validaciones de formularios/API.
6. Riesgos de seguridad.
7. Riesgos de deploy en VPS.
8. Tests faltantes.
9. Documentación desactualizada.
10. Posibles mejoras de bajo riesgo.

Reglas:
- No modifiques archivos directamente a menos que el cambio sea pequeño, seguro y verificable.
- Si modificas archivos, abre un PR.
- Si el riesgo es medio o alto, crea un issue o reporte, no hagas cambios automáticos.
- No tocar credenciales, .env real, producción, SSH, backups ni datos sensibles.
- Ejecuta pruebas/lint si el entorno lo permite.
- Si no puedes verificar algo, dilo claramente.
- Máximo 1 PR abierta de auditoría por repo al final de la corrida (ver Continuidad).

Output:
Genera un reporte con:
- Resumen ejecutivo
- Hallazgos críticos
- Hallazgos medios
- Mejoras rápidas
- Riesgos de deploy
- Archivos involucrados
- Recomendación final: "crear PR", "crear issue", "requiere revisión humana" o "sin acción"

Si el repo ya usa `docs/automation-reports/daily-audit/`, escribe el reporte ahí como `YYYY-MM-DD.md`. Si no, usa la convención de reportes del propio repo (o créala solo si ya es práctica del proyecto).
