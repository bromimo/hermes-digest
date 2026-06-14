# Spec — Детерминированный дедуп: механика в код, состояние в памяти Hermes

- **Статус:** Согласован
- **Дата:** 2026-06-14
- **Решение:** [ADR 0001](../decisions/0001-deterministic-dedup.md)
- **Контекст ТЗ:** L3 (дедупликация между запросами), §12 (принятые риски free-уровня),
  приёмочное требование «валидные JSON-схемы инструментов и понятные описания» (агент по
  описанию понимает, когда какой инструмент звать и как заполнить `period`; фильтрация по дате —
  на стороне MCP), приёмочное требование «таймауты и аккуратная обработка пустого ответа /
  404 / падения источника»

## 1. Цель и не-цели

**Цель.** Сделать бухгалтерию дедупа детерминированной: извлечение ID, фильтрацию по
показанным ранее, объединение и обрезку окна выполняет код MCP, а не модель. Состояние
(показанные ID) остаётся в памяти Hermes — этим соблюдаются L3 «через память Hermes» и
принцип stateless-MCP.

**В scope:**
- Извлечение `id` статьи из URL.
- Фильтрация кандидатов по показанным ранее ID.
- Снятие внутри-запросного дубля по `id`.
- Объединение `old ∪ shown` и обрезка скользящего окна.

**Не-цели (остаются известными ограничениями):**
- **Гонки** при параллельных запросах одной темы/чата (состояние в памяти Hermes — read-modify-write).
- **Контентные дубли** — тот же сюжет под другим `id` (перепечатка, перевод).
- **Рассинхрон ключа** по формулировке темы («AI/ML» vs «машинное обучение»).
- **URL без `/articles/N/`** (RSS: `/news/N/`, старый `/post/N/`) — остаются недедуплицируемыми.

## 2. Обзор архитектуры

Два новых **stateless**-инструмента MCP, без сетевых вызовов и без состояния между вызовами:

```
search_habr / get_news ──► (объединённый список кандидатов)
                                     │
                       dedup_filter(candidates, seen_blob)   ◄── строка памяти (шаг 2)
                                     │
                                  fresh ──► отбор 5–7, fetch_article, сборка статьи
                                     │
                       dedup_commit(key, shown_ids, seen_blob) ──► line ──► запись в память
```

Модель теряет всю арифметику: не парсит URL, не считает множества, не режет окно, не считает
символы. За ней остаётся отбор 5–7 статей (суждение) и перекладывание непрозрачного blob
память↔инструмент.

## 3. Контракт инструментов

### 3.1 `dedup_filter`

```php
#[McpTool(name: 'dedup_filter')]
public function dedupFilter(array $candidates, string $seen_blob = ''): array
```

**Вход:**
- `candidates` — объединённый массив item'ов из `search_habr`/`get_news` как есть; у каждого
  обязательно поле `url`. Остальные поля пробрасываются без изменений.
- `seen_blob` — сырая строка памяти из шага 2 (или `""`). Непрозрачна для модели.

**Логика (в коде):**
1. Разобрать показанные ID: если в строке есть маркер `ids:`, взять подстроку после него; извлечь
   все последовательности `\d+` → множество `seen`. (Снисходительный парсинг: при отсутствии
   `ids:` берутся все числа строки.)
2. Для каждого кандидата извлечь `id` через `UrlNormalizer::id($url)` (приведение к `int`).
3. Внутри-запросный дубль:
   - item'ы с `id` — оставить первое вхождение каждого `id`;
   - item'ы без `id` (не-article URL) — снять дубль по `canonical(url)`.
4. Удалить кандидатов, чей `id ∈ seen`.

**Возврат:**
```json
{
  "fresh": [ { "...исходные поля...", "id": 1047108 }, { "...", "id": null } ],
  "candidate_count": 20,
  "removed_count": 7
}
```
- `fresh` — в исходном порядке (новейшие сверху), с добавленным полем `id` (`int` или `null`).
- item с `id: null` считается свежим (его нельзя дедуплицировать), проходит дальше, но в память
  не попадёт.

### 3.2 `dedup_commit`

```php
#[McpTool(name: 'dedup_commit')]
public function dedupCommit(string $key, array $shown_ids, string $seen_blob = '', int $keep = 50): array
```

**Вход:**
- `key` — `digest:<chat_id>:<тема-slug>` (модель строит как в шаге 2).
- `shown_ids` — `id` статей, реально вошедших в дайджест; модель берёт их из поля `id` элементов
  `fresh` (без регэкспов). `null`/нечисловые отбрасываются.
- `seen_blob` — старая строка памяти из шага 2 (или `""`).
- `keep` — размер окна по количеству ID, по умолчанию `50`.

**Логика (в коде):**
1. Разобрать старые ID из `seen_blob` (тот же снисходительный парсинг).
2. Объединение с сохранением свежести: взять старые ID в их порядке; для каждого `shown_id` —
   если уже присутствует, переместить в конец (свежее), иначе добавить в конец. Порядок
   результата: старейшие слева → новейшие справа.
3. Обрезать до последних `keep` (отбросить слева, самые старые).
4. Отрендерить строку: `"<key> ids: a, b, c"` (через запятую-пробел). Пустой список → `"<key> ids: "`.

**Возврат:**
```json
{ "line": "digest:123:ai-ml ids: 200, 300, 1047108", "id_count": 3 }
```

Модель пишет `line` в память: если в шаге 2 строки **не было** → `memory add line`; если **была** →
`memory replace` с `old_text` = **дословно** `seen_blob` (та же строка, что прочитана в шаге 2),
`new_text` = `line`.

## 4. Формат состояния (blob)

Строка памяти неизменна относительно текущей:

```
digest:<chat_id>:<тема-slug> ids: <id1>, <id2>, ...
```

Формат **обратно совместим**: существующие строки памяти разбираются снисходительным
парсингом без миграции. `id` — число из `/articles/(\d+)/` URL (через `UrlNormalizer::id`); в blob
хранятся только числа.

## 5. Изменения в skill (`SKILL.md`)

- **Шаг 2 (память).** Построить ключ, найти строку памяти по ключу, держать её **дословно** как
  `SEEN_BLOB` (пусто, если строки нет). Запомнить факт наличия строки (для выбора `add`/`replace`).
  Убрать «выдиранию OLD_IDS руками».
- **Шаг 3 (фильтрация).** Собрать кандидатов (`search_habr` + при необходимости `get_news`),
  объединить и **одним вызовом** `dedup_filter(candidates, SEEN_BLOB)` получить `fresh`.
  Сообщение «новых нет» — только если `fresh` пуст.
- **Шаг 4 (отбор).** Из `fresh` взять 5–7, для каждой `fetch_article`; на `error` пропустить.
  `SHOWN_IDS` = поля `id` реально вошедших элементов (берутся из `fresh`, без регэкспов).
- **Шаг 7 (запись).** `dedup_commit(key, SHOWN_IDS, SEEN_BLOB)` → `line`; записать `line`
  (`add`/`replace`, см. §3.2). Убрать инструкции про regex ID, операции над множествами, подсчёт
  окна и «≤2200 символов» — это теперь делает код.
- Сохранить guard «используй только `id`, возвращённые инструментами» и блок фактологичности.

## 6. Обработка краёв

| Случай | Поведение |
|---|---|
| Пустой `seen_blob` | `filter` → все уникальные кандидаты; `commit` → новая строка |
| URL без `/articles/N/` | `id = null`; проходит как свежий, в память не пишется (недедуплицируем) |
| Дубль `id` в наборе кандидатов | Свернуть, оставить первое вхождение |
| Дубль не-article URL в наборе | Свернуть по `canonical(url)` |
| `shown_id` уже в старом окне | Перемещается в конец (свежее), не дублируется |
| Кандидатов ≤ `keep` | Вернуть всё, без обрезки |
| Мусор/несколько строк в `seen_blob` | Снисходительный парсинг чисел после `ids:` |
| Пустой `candidates` | `fresh: []`, `candidate_count: 0`, `removed_count: 0` |
| Кандидат без поля `url` | `id: null`, проходит как свежий (недедуплицируем) |
| Нечисловой/`null` в `shown_ids` | Отбрасывается в `commit` |

**Принцип надёжности (приёмочное требование).** Инструменты дедупа не бросают исключений на
вырожденном входе (пустой/мусорный `seen_blob`, кандидат без `url`, нечисловой `id`, пустой
`candidates`) — всегда возвращают валидный структурный результат. Это локальный аналог
требования ТЗ *«таймауты и аккуратная обработка пустого ответа / 404 / падения источника»*.
У **сетевых** инструментов оно уже выполнено и этим spec не меняется:
- `HabrClient`: `TIMEOUT=15s`, `CONNECT_TIMEOUT=5s`, retry ×2 на `429/503`/сеть с линейным backoff;
- 4xx/5xx и сетевой сбой → `HabrUnavailable` → структурный `error` в ответе инструмента
  (`get_news`/`search_habr`/`fetch_article`), а не исключение;
- пустой/битый ответ источника → парсеры (`RssParser`/`SearchApiParser`) возвращают `[]`.

## 7. Размещение кода

- **`mcp-news/src/Dedup/DedupService.php`** — чистые методы `filter(array $candidates, string $seenBlob): array`
  и `commit(string $key, array $shownIds, string $seenBlob, int $keep): array`. Зависит только от
  `UrlNormalizer`. PHPDoc на русском по стилю проекта.
- **`mcp-news/src/Tools/DigestTools.php`** — добавить методы `dedupFilter`, `dedupCommit` с
  `#[McpTool]`, делегирующие в `DedupService` (как остальные методы делегируют в `HabrService`).
  В конструкторе собрать `new DedupService(new UrlNormalizer())`.

## 8. Тесты

**`mcp-news/tests/Dedup/DedupServiceTest.php`:**
- `filter`: удаляет показанные ID; сворачивает дубль `id` внутри набора; пустой `seen` → всё;
  не-article URL → `id: null` и passthrough; сохраняет порядок; корректные `candidate_count`/`removed_count`.
- `commit`: объединение; обрезка до `keep` (отбрасывает старейшие); пустой `seen` → новая строка
  с `shown`; `shown_id` уже в старом → переезжает в конец; пустые `shown`+`seen` → `"<key> ids: "`;
  граница `keep` (ровно `keep` — без обрезки).

Дополнительно — **вырожденный вход** (принцип надёжности §6): пустой `candidates`; кандидат без
`url`; мусорный `seen_blob`; нечисловой элемент в `shown_ids` — оба метода возвращают валидный
результат без исключений.

Извлечение `id` уже покрыто `UrlNormalizerTest` — не дублируем.

## 9. Документация

- **ADR 0001** — уже написан.
- **README:**
  - строка **L3** в «Уровни ТЗ» — дедуп детерминирован, механика в коде (состояние по-прежнему
    в памяти Hermes);
  - оговорка в «Известные оговорки» — L3 «через память Hermes» и stateless-MCP соблюдены,
    бухгалтерия больше не стохастична; остаются нерешёнными гонки, контентные дубли, не-article URL;
  - секция «Промптинг skill» — обновить буллеты дедупа (теперь делегируется в инструменты).

## 10. Замечания по эксплуатации

- +2 вызова инструментов на дайджест (`dedup_filter`, `dedup_commit`) — оба локальные, без сети.
- Skill доставляется в volume `hermes-home` сервисом `seeder` при `docker compose up` — отдельной
  миграции данных не требуется (формат blob совместим).

## 11. Описания инструментов (приёмочное требование к JSON-схемам)

Приёмочное требование ТЗ: **валидные JSON-схемы инструментов и понятные описания** — агент по
описанию должен понимать, когда какой инструмент звать и как заполнить параметры (в частности
`period`). Схемы генерируются `php-mcp/server` из `#[McpTool]` + тайп-хинтов + PHPDoc, поэтому
описания ниже — нормативная часть spec.

> Язык описаний — английский, как у трёх существующих инструментов в `DigestTools`. Если нужен
> русский — скажи, поправим единообразно по всему классу.

### 11.1 `dedup_filter`

```
Remove already-shown Habr candidates against the per-topic "seen" memory blob.
Call once after merging search_habr/get_news results and before selecting
articles to annotate. Attaches an explicit numeric `id` to each returned item
and drops within-batch duplicates.

@param array  $candidates Merged candidate items from search_habr/get_news, each with at least a `url`. Passed through as-is.
@param string $seen_blob  Raw memory line for this chat+topic (snapshot of shown IDs), passed verbatim; "" if none. Opaque — do not parse it.
@return array{fresh: array<int, array<string, mixed>>, candidate_count: int, removed_count: int}
```

### 11.2 `dedup_commit`

```
Merge the IDs actually shown in this digest into the per-topic "seen" memory blob
and return the updated memory line to store. Call once after the final article set
is assembled. Keeps a sliding window of the most recent IDs. Write the returned
`line` to memory: add it if there was no prior line, otherwise replace the prior
line (use seen_blob verbatim as the old text).

@param string $key        Memory key for this chat+topic, e.g. "digest:<chat_id>:<topic-slug>".
@param array  $shown_ids  Numeric IDs of articles that made it into the digest (take `id` from dedup_filter's `fresh`). Null/non-numeric ignored.
@param string $seen_blob  Prior raw memory line (same string passed to dedup_filter), or "". Opaque.
@param int    $keep       Max IDs to retain in the sliding window. Default 50.
@return array{line: string, id_count: int}
```

### 11.3 Сопутствующая правка: `@param period` существующих инструментов

`PeriodParser` принимает общую форму `N[h|d|w|m]` (часы/дни/недели/месяцы) и диапазон дат, но
описания `get_news`/`search_habr` перечисляют лишь `24h/7d/30d/диапазон` и расходятся между собой.
Привести `@param period` обоих инструментов к единому тексту:

```
@param string $period Time window. General form "N<unit>", unit = h|d|w|m
(hours, days, weeks, months): e.g. "24h", "7d", "3d", "2w", "30d", "6m".
Or an absolute date range "YYYY-MM-DD..YYYY-MM-DD". Default "7d".
Date filtering is performed on the MCP side.
```

Это закрывает формулировку ТЗ: *«period — за какой срок брать материалы (напр. "7d", "24h",
или диапазон дат); фильтрация по дате выполняется на стороне MCP»*.

## 12. План реализации (укрупнённо)

1. `DedupService` + юнит-тесты (TDD).
2. Регистрация `dedup_filter`/`dedup_commit` в `DigestTools` с описаниями из §11.
3. Привести `@param period` у `get_news`/`search_habr` к общей форме `N[h|d|w|m]` (§11.3).
4. Переписать шаги 2/3/7 в `SKILL.md`.
5. Обновить README (L3, оговорки, секция промптинга).
6. Прогон `vendor/bin/phpunit`.

Детальный пошаговый план — на этапе writing-plans.