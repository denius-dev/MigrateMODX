# MigrateMODX

Guided MODX 2.6.5–2.8.x → 3 migration: package compatibility map, PHP 7.4→8+ prep, Fenom/MODX template syntax scan, snapshots, rollback, and a **Recovery Console** that works when the manager is down.

See [docs/MigrateMODX.ru.md](docs/MigrateMODX.ru.md) for full Russian documentation.

## Build

```bash
MODX_CORE_PATH=/path/to/site/core/ php _build/build.transport.php
```

Build on MODX 2.8. Menu appears under **Packages** (`workspaces` parent).

Transport output: `{MODX_CORE_PATH}/packages/migratemodx-1.3.31-pl.transport.zip`

## Tests

```bash
# All suites (unit + resilience + battle)
php tests/run_all.php

# Fast unit checks (no MODX)
php tests/unit.php

# Missing Fenom/Provider, exploding services, php -l, 2.6.5 stub
php tests/resilience.php

# Full battle harness against local sandbox MODX 2.8.8 + MariaDB
php tests/battle.php
```

## Requirements

- PHP >= 7.2 (8.1+ after targeting MODX 3.2)
- MODX >= 2.6.5 (2.8.x recommended before core upgrade to 3.0.0)
- ZipArchive recommended for snapshots
- PDO MySQL for DB dump/restore fallback
