# Модульность, структура `Makefile` и управление ИИ-провайдерами

Документ описывает модульную организацию проекта (`Makefile`, `compose.yaml`), включение и выключение блоков и точечных функций ИИ в веб-админке (`/admin/modules`), а также управление подключёнными провайдерами ИИ и пулом CLI-агентов (`/admin/ai-providers` и `app:ai:*`).

---

## 1. Блоки `Makefile` и профили Docker Compose

Команды в [Makefile](../Makefile) разделены на пять независимых функциональных блоков (полный перечень — `make help`). По умолчанию `make up` запускает только базовый сервер (`app` + `database`). Дополнительные контуры включаются либо профильными командами (`make news-up`, `make vpn-up`), либо через `COMPOSE_PROFILES` в `.env` (например, `COMPOSE_PROFILES=news,vpn,backup`).

1. **Блок 1: Система, сервер и пользователи (Core)**
   - `init` — создать `.env` со случайными `APP_SECRET`, паролем БД и `APP_ENCRYPTION_KEY`; `env-ai` — добавить `APP_ENCRYPTION_KEY` в существующий `.env`.
   - `build`, `up`, `down`, `restart`, `logs`, `ps`, `sh`, `psql`, `migrate`, `admin`, `user`, `test`, `clean`.
2. **Блок 2: Данные устройства (контакты, пароли, приложения, календарь, бэкапы)**
   - Сборка и доставка Android-клиента: `device-apk`, `device-install-apk`, `device-reverse`, `device-apk-cleanup`, `device-release`, `device-deploy`.
   - Резервное копирование (`profile=backup`): `backup-env`, `backup`, `backup-db`, `backup-status`, `restore-db`, `restore-files`, `decrypt-secrets`.
3. **Блок 3: Новости и рынок акций (Мосбиржа)**
   - Фоновые сервисы (`profile=news`): `news-up`, `news-down`.
   - Новости и сводки: `news-collect`, `news-articles`, `news-translate`, `news-translate-req`, `news-analyze`, `news-emb-compare`, `news-tune`, `news-digest`, `news-digest-prev`.
   - Мосбиржа: `market-impact`, `market-advice`.
4. **Блок 4: Подключённые провайдеры ИИ и CLI-агенты**
   - Единое управление пулом из консоли и админки: `ai-status`, `ai-add`, `ai-login`, `ai-check`, `ai-toggle`, `ai-remove`, `agy-login`.
5. **Блок 5: VPN и шифрование (CEP / Argon4 Tunnel / ocserv)**
   - Стек в Docker (`profile=vpn`): `vpn-up`, `vpn-down`.
   - Сервер Argon4 Tunnel: `vpn-argon4-up`, `vpn-argon4-test`, `vpn-argon4-e2e`, `vpn-argon4-ubuntu`.
   - Сервер OpenConnect (`ocserv`): `vpn-ocserv-up`, `vpn-ocserv-down`, `vpn-ocserv-logs`, `vpn-ocserv-status`, `vpn-ocserv-ip`, `vpn-ocserv-add-user`, `vpn-ocserv-rm-user`.
   - Клиенты CEP:
     - Linux: `vpn-cep-linux` (проверяет/ставит зависимости и собирает `cep-argon4` + `cep-cli`), `vpn-cep-linux-test`, `vpn-cep-linux-inst`, `vpn-cep-linux-cfg`, `vpn-cep-linux-start`, `vpn-cep-linux-stop`, `vpn-cep-linux-restart`, `vpn-cep-linux-stat`, `vpn-cep-linux-logs`;
     - macOS: `vpn-cep-mac-deps`, `vpn-cep-mac-build`, `vpn-cep-mac-dmg`, `vpn-cep-mac-test`;
     - Android: `vpn-android-engine`.
   - Устаревший отдельный APK (`:tunnel`, заменён движком CEP в основном приложении): `vpn-legacy-argon-apk`, `vpn-legacy-argon-inst`.

Прежние короткие имена (`apk`, `install-apk`, `release`, `deploy`, `news`, `translate`, `ocserv-up`, `cep-linux` и др.) сохранены как скрытые алиасы совместимости. Избыточные цели `build-tunnel` и отдельный `cep-linux-deps` убраны (зависимости Linux CEP проверяются прямо в `vpn-cep-linux`).

---

## 2. Включение и выключение модулей и функций ИИ (`/admin/modules`)

Настройки хранятся в БД в таблице `feature_settings` (единая запись `id=1`, миграция `Version20261114200000`) и управляются через [`FeatureManager`](../server/src/Service/FeatureManager.php) и экран админки «Система и ИИ → Модули и функции ИИ» (`/admin/modules`).

Базовое ядро (`Core`: авторизация `/api/auth/*`, `User`, `AppRelease`, `/generate_204`, страницы `/admin/modules` и `/admin/ai-providers`) остаётся включённым всегда.

### Переключатели функциональных модулей

- **Данные устройства** (`deviceDataEnabled` — главный рубильник блока + отдельные флаги подразделов):
  - `contactsEnabled` — контакты, семьи, фото, корзина и дубликаты (`/admin` + `/api/sync`, `/api/contacts`, `/api/family/contacts`, `/api/contact-photos/*`);
  - `vaultEnabled` — зашифрованное хранилище паролей (`/api/vault*`, админка хранилищ);
  - `appsEnabled` — бэкап установленных приложений и APK (`/api/apps*`, `/api/apk*`, админка приложений);
  - `calendarEnabled` — календари и события (`/api/calendars*`, `/api/events*`, админка календарей);
  - `browsingEnabled` — история браузеров (`/api/browsing`, админка посещений).
- **Новости** (`newsEnabled`) — сбор, ленты, сюжетные линии, `/api/news*` и разделы новостей в админке.
- **Рынок акций (Мосбиржа)** (`marketEnabled`) — инструменты виджета и анализ акций (`/api/market*`, `app:market:analyze`).
- **VPN и шифрование** (`vpnEnabled`) — все разделы админки `/admin/vpn-*`, `/admin/port-forward`, выгрузка `profiles.json`. При выключении `FeatureSettingsController` синхронно вызывает `NodeRuntimeExporter::sync(applyNow: true)`: очищает `profiles.json` и `port-forward.sh`, удаляет серверные `.env` / `xray-config.json` / `hysteria2.yaml`, записывает `"vpnModuleEnabled": false` и `"server.enabled": false` в `desired-state.json` и запускает `VPN_RUNTIME_APPLY_CMD` для остановки туннелей и правил на хосте.

### Точечные переключатели ИИ (по конкретным задачам)

Вместо общего рубильника «Весь ИИ» отключается каждая из пяти точек вызова моделей на сервере:

1. **`aiNewsTranslateEnabled`** (`ai_news_translate`) — автоперевод иностранных новостей и статей на русский (`NewsTranslator`).
2. **`aiNewsStorylinesEnabled`** (`ai_news_storylines`) — построение векторов (`NewsEmbedder`), называние и склейка сюжетных линий (`NewsStorylineService`).
3. **`aiNewsDigestEnabled`** (`ai_news_digest`) — генерация часовых и суточных сводок и сводок-статей (`NewsDigestService`).
4. **`aiMarketNewsImpactEnabled`** (`ai_market_news_impact`) — оценка влияния новостей на инструменты Мосбиржи (`NewsMarketService`).
5. **`aiMarketAnalysisEnabled`** (`ai_market_analysis`) — инвестиционный анализ выбранных бумаг и советы по покупкам (`MarketAnalysisService`).

> **Примечание для Android:** отправка аудиозаписей созвонов во внешнюю Базу Знаний (KB) в `SaveRecordingActivity.kt` относится к клиентской интеграции с KB и сервером `sync` не управляется.

### Как работает блокировка при отключении

- **Веб-админка**: пункты выключенных модулей исчезают из бокового меню EasyAdmin (`DashboardController`); прямой переход по URL контроллера с атрибутом `#[RequiresFeature(...)]` блокируется подписчиком `FeatureGuardSubscriber` с ответом HTTP **503 Service Unavailable**.
- **REST API**: эндпоинты `/api/...` с `#[RequiresFeature(...)]` возвращают JSON-ответ HTTP **503** (`feature` + понятное описание).
- **Консольные команды и cron**: команды `app:news:*` и `app:market:*` проверяют флаг модуля/ИИ-функции в начале выполнения, выводят уведомление о пропуске и завершаются с кодом `0` (`Command::SUCCESS`), не ломая цикл контейнера `news`. Точечные ИИ-флаги пропускают только соответствующий ИИ-шаг, не затрагивая уже собранные данные и API чтения (например, при выключенном `ai_market_news_impact` котировки ISS продолжают обновляться).

---

## 3. Подключённые провайдеры ИИ и CLI-агенты (`/admin/ai-providers`)

Модель объединяет HTTP/API-провайдеры и пул консольных CLI-агентов с ротацией аккаунтов по квотам и cooldown.

### HTTP/API провайдеры (`ai_provider`)

Поддерживаются:
- `ollama` — локальный сервер (эмбеддинги `qwen3-embedding` и/или чат-модели);
- `openai` — OpenAI Chat Completions + `/v1/embeddings`;
- `anthropic` — Claude Messages API;
- `gemini` — Google Gemini `generateContent` + `embedContent` / `batchEmbedContents`;
- `openai_compatible` — любой совместимый API со своим `base_url` (vLLM, LM Studio, OpenRouter, LiteLLM и т.д.).

Секреты (API-ключи провайдеров и OAuth/API токены CLI-агентов) шифруются с помощью [`SecretBox`](../server/src/Service/SecretBox.php) (симметричное аутентифицированное шифрование libsodium `crypto_secretbox` с ключом `APP_ENCRYPTION_KEY`) и никогда не возвращаются в HTML в открытом виде (отображается только маска последних 4 символов `…1234`). На уже развёрнутых инсталляциях ключ можно сгенерировать командой `make env-ai`.

Для каждого провайдера в админке доступны:
- включение/выключение (`enabled`), приоритет (`priority`), признак локального (`is_local`);
- назначение для текстовой генерации (`use_for_summarization`) и/или эмбеддингов (`use_for_embeddings`);
- кнопки **«Проверить подключение»** и **«Загрузить модели»** (`AiProviderTester`), а также выбор модели по умолчанию.

### Индивидуальные CLI-агенты и пул аккаунтов (`ai_agent_account`)

Поддерживаются все пять вариантов CLI-агентов (описание возможностей — [`AiAgentCatalog`](../server/src/Service/AiAgentCatalog.php)):
- **`antigravity_cli` (`agy`)** — Google / Claude / GPT-OSS модели по подписке Antigravity CLI;
- **`claude_cli` (`claude`)** — Anthropic Claude Code CLI (`claude -p`);
- **`codex_cli` (`codex`)** — OpenAI Codex CLI (`codex exec`);
- **`gemini_cli` (`gemini-cli`)** — Google Gemini CLI;
- **`kimi_cli` (`kimi`)** — Moonshot Kimi CLI.

Каждый добавленный слот получает отдельный рабочий каталог `home_dir` вида `/app/var/agents/<slug>/<slot>` (для исторического основного слота `agy:main` поддерживается legacy-путь `/app/var/agy`), поэтому сессии разных аккаунтов не перезаписывают друг друга. При `429` / `RESOURCE_EXHAUSTED` / ошибке авторизации [`AiAgentManager`](../server/src/Service/AiAgentManager.php) переводит слот на отдых (`cooldown`) и автоматически переключает запрос на следующий активный слот.

### Единый консольный механизм (CLI + Админка)

Операции добавления, переключения, проверки, входа и удаления слотов реализованы в консольных командах `app:ai:*`, а веб-админка [`AiProvidersController`](../server/src/Controller/Admin/AiProvidersController.php) вызывает эти же команды через [`AiConsoleRunner`](../server/src/Service/AiConsoleRunner.php):

| Команда Symfony | Цель `Makefile` | Назначение |
|---|---|---|
| `app:agy:status` (алиас `app:ai:status`) | `make ai-status` | Сводка провайдеров, слотов CLI-агентов, статусов квоты, очередей новостей и Мосбиржи |
| `app:ai:agent-add <type>` | `make ai-add TYPE=codex SLOT=work` | Создать слот агента, подготовить каталог `HOME`, опционально сохранить токен |
| `app:ai:agent-login <id \| type:slot>` | `make ai-login TYPE=agy ACCOUNT=main` | Интерактивный OAuth-вход (PTY) или запись API-токена (`--token=...` / `--logout`) |
| `app:ai:agent-toggle <id \| type:slot>` | `make ai-toggle ID=1 STATE=disable` | Включить или выключить конкретный слот агента |
| `app:ai:agent-check [id \| type:slot]` | `make ai-check` | Проверить наличие сессии/бинарника у одного или всех слотов |
| `app:ai:agent-remove <id \| type:slot>` | `make ai-remove ID=1 [KEEP_FILES=1]` | Удалить слот из БД (и по умолчанию удалить его каталог `home_dir`) |

Роутинг текстовых ИИ-задач в [`AiGatewaySummarizer`](../server/src/Service/AiGatewaySummarizer.php) работает по приоритету:
1. Активные HTTP-провайдеры и/или слоты CLI-агентов в БД (согласно `priority`).
2. Если в БД ничего подходящего не подключено — автоматический fallback на классический механизм `AgyNewsSummarizer` (совместимость со старыми установками `var/agy`).
3. Для эмбеддингов [`NewsEmbedder`](../server/src/Service/NewsEmbedder.php) сначала ищет активный провайдер с `use_for_embeddings = true` в БД (поддерживаются `ollama`, `gemini`, `openai` и `openai_compatible`); если в БД эмбеддинги явно отключены, генерация векторов отключается, а при отсутствии записей в БД используется fallback на `NEWS_EMBEDDINGS_*` из `.env`.
