# MigrateMODX

Компонент для управляемого переезда **MODX Revolution 2.8 → MODX 3** с полной картиной до любых мутаций, подготовкой PHP 7.4→8+, проверкой синтаксиса MODX/pHx и Fenom, снимками и **Recovery Console** вне менеджера.

Версия 1.3.31-pl.

## Что делает

1. **Аудит** окружения, установленных пакетов (база знаний + вердикты/аналоги), кастомного кода под MODX 3, PHP 8, шаблонов/чанков.
2. **PHP 8+ prep** — скан и безопасный autofix (`$arr{0}` → `$arr[0]`, `${var}` → `$var`) с журналом и откатом правок.
3. **Синтаксис** — баланс `[[…]]`, Fenom `{if}/{foreach}/{$…}`, dry-run compile через Fenom из pdoTools если доступен.
4. **Снимок** файлов (`core`, `manager`, `connectors`, `setup`) + дамп БД.
5. **Активация Recovery** — отдельный URL + пароль, работает при белом экране manager.
6. **Оркестратор** — скачивание distro 2.8.8 / 3.0.0, применение файлов после двойного подтверждения staging, затем `/setup/`.
7. **Health check** и откат к снимку из CMP или Recovery.

## Установка

1. Соберите пакет на MODX 2.8:

```bash
MODX_CORE_PATH=/path/to/site/core/ php _build/build.transport.php
```

2. Установите zip из `core/packages/` через «Пакеты» → «Установщик».
3. Откройте **Пакеты → MigrateMODX**.

Либо скопируйте `core/components/migratemodx` и `assets/components/migratemodx` в сайт и создайте меню вручную:

- namespace: `migratemodx`
- parent: `workspaces`
- action: `index`
- permissions: `workspaces`

## Рекомендуемый порядок

1. Staging-копия сайта (не прод).
2. **Run audit** — закрыть blockers (пакеты, PHP, Fenom, core path).
3. PHP autofix на ещё работающем 7.4.
4. **Create snapshot + activate Recovery** — сохранить URL и пароль.
5. Download / apply **3.0.0** (сначала последний 2.8.x при необходимости) → пройти `/setup/`.
6. Health check → переключить PHP хостинга на 8.1+ → снова health.
7. **Deactivate Recovery**.

## Recovery Console

- URL: `/assets/components/migratemodx/recovery/index.php?token=…`
- Ярлык в корне (после активации): `/migratemodx-recovery.php`
- Возможности: статус инцидента (`state.json`), диагностика, список снимков, rollback, скачать отчёт.

Не зависит от живого manager: читает `config.inc.php` и store на диске.

## База знаний пакетов

Файл: `core/components/migratemodx/data/kb/packages.json`.

Статусы: `compatible`, `compatible_from`, `replaced_by`, `incompatible`, `abandoned`, `unknown`.

Примеры замен: SeoFilter / mSearch2 → **msCatalyst**, miniShop2 → **MiniShop3**, localizator → localizator3.

## Ручной чеклист QA

1. Установка на чистый MODX 2.8 → пункт в **Пакеты** → полная картина после audit.
2. Стенд ms2 + mSearch2/SeoFilter + старый PHP-кастом → preflight + PHP scan.
3. Битый Fenom `{if` → blocker во вкладке Templates.
4. Snapshot → download/apply → setup → health.
5. Белый экран manager → откат только через Recovery.
6. Обновление пакета на уже мигрированном MODX 3.

## Ограничения

- Не обещает «100%» на любом брошенном CMP.
- Не переключает версию PHP на хостинге.
- Autofix PHP только для high-safety правил.
- Откат возвращает сайт к снимку (контент после апгрейда не сохраняется).
