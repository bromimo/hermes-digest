# hermes-digest

Telegram-агент на **Hermes Agent**, который по запросу собирает дайджест новостей
**Хабра** за период по теме и присылает связную статью на русском — с обложкой
(из `og:image` ведущей статьи) и дедупликацией уже показанных материалов между
запросами. Агент разбирает тему и период из естественного языка, вызывает MCP-
инструменты `mcpnews`, аннотирует каждую статью и собирает из аннотаций цельный
обзорный текст: вводка → 5–7 пунктов со ссылками → вывод.

## Архитектура

```
Telegram (запрос) ──► Hermes Agent ──► skill: news-digest
                           │                    │
                           │           mcp_mcpnews_get_news       (RSS)
                           │           mcp_mcpnews_search_habr    (JSON API)
                           │           mcp_mcpnews_fetch_article  (парсинг)
                           │                    │
                           │              mcp-news (PHP)
                           │                    │
                           │                  Habr
                           │
                           └──► Telegram (ответ: обложка + статья)

docker compose: bridge-сеть appnet
  mcp-news      — PHP MCP-сервер, StreamableHTTP http://mcp-news:8000/mcp (внутри appnet)
  seeder        — busybox, копирует config.yaml + skills/ в volume hermes-home, затем завершается
  hermes-agent  — nousresearch/hermes-agent:latest, gateway run, Telegram long-polling
  hermes-home   — named volume: конфиг, skill, состояние агента (переживает рестарт)
  mcpnews-data  — named volume: SQLite-стор дедупа MCP (переживает рестарт)
```

## Компоненты

### mcp-news

PHP MCP-сервер (`php-mcp/server`, StreamableHTTP на `:8000/mcp`, только внутри `appnet`).
Порт **не** публикуется на хост. Собирается из `./mcp-news` (Dockerfile на базе `php:8.3-cli`).
Состояние дедупа хранит в SQLite (`/data/dedup.sqlite`, volume `mcpnews-data`).

Инструменты:

| Инструмент | Описание |
|---|---|
| `get_news(topic, period, limit)` | Статьи по теме из RSS Хабра, фильтрация по периоду |
| `search_habr(query, period, limit)` | Поиск через внутренний JSON-API `/kek/v2/articles/` |
| `fetch_article(url)` | Полный текст + метаданные + `og:image` одной статьи |

### hermes-agent

Официальный образ `nousresearch/hermes-agent:latest` (MCP `1.26.0` уже встроен, отдельный
Dockerfile не нужен). Запускается командой `gateway run` в режиме Telegram long-polling.
Конфиг (`hermes/config.yaml`) и skill (`hermes/skills/news/news-digest/`) поступают через
volume `hermes-home`, наполняемый сервисом `seeder`.

### seeder

Одноразовый сервис на `busybox:1.36`. Запускается до `hermes-agent`, копирует
`hermes/config.yaml` и `hermes/skills/` в volume `hermes-home` (точка монтирования
`/opt/data`) и выставляет владельца `10000:10000`. После завершения Hermes стартует
и читает уже готовый конфиг. Репозиторий — источник истины: повторный
`docker compose up` перезапишет файлы в volume.

## Промптинг skill

Надёжность дайджеста держится на промпте skill (`hermes/skills/news/news-digest/SKILL.md`),
а не на коде агента. Ключевые приёмы — с привязкой к коду:

**Структура статьи**
- Жанр задан явно — «связная статья, **не список**» → `SKILL.md:20-21`
- Задача разложена на 7 воспроизводимых шагов → `SKILL.md:41,46,54,68,85,97,103`
- Жёсткий каркас текста: вводка → 5–7 пунктов `### Заголовок → TL;DR → ссылка` → вывод → `SKILL.md:86-89` + `templates/digest.md`
- Формат нарезан под Telegram: подпись ≤1024, сообщения ≤4096, разбивка по `###` → `SKILL.md:99`

**Тон**
- Тон зафиксирован: нейтрально-технический, русский → `SKILL.md:95`
- Вводку/вывод писать самостоятельно, не копируя фрагменты статей → `SKILL.md:93`

**Фактологичность (anti-hallucination)**
- Даты — только из `since`/`until` инструмента, запрет вычислять «сегодня» → `SKILL.md:91`, источник `HabrService.php:73,113`
- TL;DR только по тексту/сниппету, без выдуманных фактов → `SKILL.md:71-72`
- «Никогда не выдумывай ID» → `SKILL.md:61`
- «Фактологичность обязательна» как явное требование → `SKILL.md:95`

**Дедупликация (состояние на стороне MCP)**
- `dedup_filter(key, candidates)` / `dedup_commit(key, shown_ids)` сами читают/пишут SQLite-стор по
  `key` → `DedupService.php` + `DedupStore.php`, ADR `docs/decisions/0001-deterministic-dedup.md`
- Модель передаёт только `key`; память Hermes не используется
- `SHOWN_IDS` (поле `id` из `fresh`) — единственный источник правды для тела и стора
- Объединение, скользящее окно (`keep=50`) и атомарная запись — в коде, не у модели

**Продакшн-приёмы (надёжность)**
- Обработка ошибок прямо в инструкции: `error` → пропусти статью, не прерывай дайджест → `SKILL.md:77-78`, поле `error` от сервера `HabrService.php:128-132`
- «Новых материалов нет» — только при нуле кандидатов (защита от ложного «пусто») → `SKILL.md:63`

> Всё перечисленное — **инструкции, а не гарантии**: на free-модели они исполняются
> вероятностно, гарантии живут только в коде MCP. Подробнее — в разделе
> [«Известные оговорки»](#известные-оговорки).

## Запуск

```bash
cp .env.example .env   # вписать OPENROUTER_API_KEY, TELEGRAM_BOT_TOKEN, TELEGRAM_ALLOWED_USERS
docker compose up --build
```

Затем напишите боту в Telegram, например:

> собери дайджест по теме AI/ML за последнюю неделю

Бот разберёт тему и период, вызовет инструменты `mcpnews`, аннотирует статьи и пришлёт
обзорную статью с обложкой.

## Управление контейнерами

```bash
docker compose up --build            # поднять (foreground)
docker compose up -d --build         # поднять в фоне
docker compose ps                    # статус сервисов
docker compose logs -f hermes-agent  # логи агента
docker compose down                  # опустить (volume с памятью сохраняется)
```

**Сброс состояния** (стор дедупа + состояние агента живут в volume):

```bash
docker compose down -v               # опустить и стереть volume (стор дедупа + состояние Hermes)
```

> `down -v` удаляет volume `mcpnews-data` (SQLite-стор дедупа) и `hermes-home` (состояние агента).
> Конфиг и skill не теряются — их заново засевает сервис `seeder` при следующем `up` из репозитория.

## Прогон без Telegram (один запрос)

Дать задание Hermes напрямую, минуя Telegram, — one-shot режим `hermes -z` (тот же способ,
что в примере `docs/examples/`; реальный прогон: модель → skill → MCP-инструменты → стор дедупа,
вывод в stdout):

```bash
docker compose run --rm hermes-agent -z "собери дайджест по теме AI/ML за последнюю неделю"
```

- `-z "…"` — один запрос в неинтерактивном режиме, ответ печатается в stdout (без Telegram).
- Команда поднимет зависимости (`mcp-news`, `seeder`); навык `news-digest` подхватывается
  по описанию автоматически (явно — `--skills news-digest`). Стор дедупа обновляется как при
  обычном прогоне.

## LLM

Используется **OpenRouter, бесплатный уровень**. Модель по умолчанию:
`openai/gpt-oss-120b:free` (free, поддерживает tool-calling, контекст 131K).
Задаётся переменной `HERMES_MODEL` в `.env`.

> **Важно:** набор бесплатных моделей на OpenRouter меняется, а у части free-моделей
> tool-calling нестабилен или временно недоступен. Перед запуском проверьте актуальный
> список:
> https://openrouter.ai/models?max_price=0&supported_parameters=tools&order=context-high-to-low
>
> Резервные варианты (на июнь 2026):
> - `nvidia/nemotron-3-ultra-550b-a55b:free` (1M ctx)
> - `poolside/laguna-m.1:free` (262K ctx)
> - `nex-agi/nex-n2-pro:free` (262K ctx)
>
> Лимит free-уровня ~20 запросов/мин — skill экономит вызовы инструментов.

Сменить модель без пересборки:

```bash
HERMES_MODEL=nvidia/nemotron-3-ultra-550b-a55b:free docker compose up
```

## Переменные окружения

| Переменная | Назначение |
|---|---|
| `OPENROUTER_API_KEY` | Ключ OpenRouter (формат `sk-or-...`) |
| `HERMES_MODEL` | ID модели OpenRouter; по умолчанию `openai/gpt-oss-120b:free` |
| `TELEGRAM_BOT_TOKEN` | Токен бота от @BotFather |
| `TELEGRAM_ALLOWED_USERS` | Разрешённые Telegram user ID через запятую |
| `HABR_BASE_URL` | Базовый URL источника (по умолчанию `https://habr.com`) |
| `DEDUP_DB_PATH` | Путь к SQLite-стору дедупа в MCP (по умолчанию `/data/dedup.sqlite`, volume `mcpnews-data`) |

Секреты передаются только через `.env` / environment — ничего не зашито в образы.
Состояние переживает рестарт: стор дедупа — в volume `mcpnews-data` (SQLite на стороне MCP),
конфиг и состояние агента — в volume `hermes-home`.

## Уровни ТЗ

| Уровень | Что реализовано |
|---|---|
| **L1** | `get_news(topic, period, limit)` — статьи из RSS Хабра по хабу/теме |
| **L2** | `search_habr` + `fetch_article`, аннотации (TL;DR по тексту) и цельная обзорная статья (вводка → пункты → вывод), а не просто список ссылок |
| **L3** | Дедупликация между запросами: детерминированный **серверный** дедуп — состояние в SQLite на стороне MCP (`dedup_filter`/`dedup_commit`), проверено вживую (прогон 2 не повторяет прогон 1, см. `docs/examples/`); обложка дайджеста — `og:image` ведущей статьи (без image-gen) |

## Разработка MCP-сервера

```bash
cd mcp-news
composer install
vendor/bin/phpunit
```

Юнит-тесты покрывают: `PeriodParser`, `UrlNormalizer`, `RssParser`, `SearchApiParser`,
`ArticleParser`, `HabrClient`, `HabrService`, `DedupService`, а также делегирование dedup-инструментов в `DigestTools`.

## Примеры

См. `docs/examples/` — два реальных прогона; дедуп работает (прогон 2 не повторяет статьи прогона 1).

## Известные оговорки

- **Дедупликация детерминирована (серверная) и id-основана.** Состояние показанных статей хранит
  **MCP-сервер** в SQLite (`dedup_filter`/`dedup_commit`, ключ `digest:<chat_id>:<тема-slug>`); память
  Hermes не используется. Почему так: её инструмент `memory` не умеет читать, поэтому модель не может
  получить прежний список (см. ADR `docs/decisions/0001-deterministic-dedup.md`, раздел «Дополнение»;
  ТЗ L3 трактуется по намерению «не повторять материалы», а не буквально «через память Hermes»). Гонки
  решены атомарной транзакцией на стороне сервера. Остаются **известными ограничениями**: контентные
  дубли (один сюжет под разными `id`), рассинхрон ключа по формулировке темы и URL без `/articles/N/`
  (недедуплицируемы).

- **`search_habr` использует недокументированный API.** Инструмент обращается к
  внутреннему JSON-API Хабра (`/kek/v2/articles/`), который может измениться без
  предупреждения. RSS (`get_news`) — более стабильный путь; skill автоматически
  использует его как запасной вариант.

- **Повторный `docker compose up` перезаписывает конфиг и skill в volume.** Сервис
  `seeder` копирует `hermes/config.yaml` и `hermes/skills/` при каждом старте.
  Репозиторий — источник истины; правки, сделанные непосредственно внутри volume,
  будут перезаписаны. Вносите изменения в файлы репозитория.

- **Если Telegram-канал не стартует** из-за ошибки схемы `gateway.platforms.telegram`,
  выполните один раз интерактивную настройку:
  ```bash
  docker compose run --rm hermes-agent gateway setup
  ```
  Мастер запишет корректный блок конфига в volume; после этого запустите
  `docker compose up -d`.