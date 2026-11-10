# Feeding an agent with MigrateMODX

1. Run Goal A (PHP) or Goal B Prepare on staging.
2. Copy Attention Report (MD) or open `core/cache/migratemodx/last-attention.md`.
3. Prompt the agent: this file is inventory/flags only; re-check custom code and packages; do not treat «done» as full analysis.
4. For Goal B after core: finish `/setup/`, then Phase 3 if miniShop2 was present.
5. Prefer DB refresh / dump→setup on complex sites (see schema advice in the report).
