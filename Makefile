# Contact Sync: сервер в Docker, Android-клиент — локальным Android SDK (или в Docker при make release).
# Список команд: make help

COMPOSE   := docker compose
APP       := $(COMPOSE) exec app
APP_T     := $(COMPOSE) exec -T app
HTTP_PORT ?= $(shell grep -s '^HTTP_PORT=' .env | cut -d= -f2)
HTTP_PORT := $(or $(HTTP_PORT),8001)
APK        := android/app/build/outputs/apk/debug/app-debug.apk
TUNNEL_APK := android/tunnel/build/outputs/apk/debug/tunnel-debug.apk
GO_VERSION ?= 1.27
GO_IMAGE   := golang:$(GO_VERSION)-alpine
GO         ?= go
GOOS       ?= $(shell $(GO) env GOOS 2>/dev/null || uname -s | tr '[:upper:]' '[:lower:]')
GOARCH     ?= $(shell $(GO) env GOARCH 2>/dev/null || uname -m | sed -e 's/^x86_64$$/amd64/' -e 's/^aarch64$$/arm64/' -e 's/^armv7l$$/arm/')
CEP_BUILD_DIR := $(CURDIR)/cep/build/$(GOOS)-$(GOARCH)
CEP_GO_DEPS_STAMP := $(CURDIR)/cep/build/.go-modules.stamp
CEP_GO_MOD_FILES := $(wildcard cep/argon4/go.mod cep/argon4/go.sum)
CEP_SOURCE_FILES := $(wildcard cep/argon4/*.go cep/argon4/cmd/cep-cli/*.go cep/argon4/cmd/cep-argon4/*.go)
SING_BOX_VERSION ?= 1.14.3
XRAY_VERSION ?= 26.3.27
CEP_INSTALL_DEPS ?= 1

.DEFAULT_GOAL := help
# Домашняя папка agy: основной аккаунт — /app/var/agy, запасные (при лимите основного) — /app/var/agy/account2…
AGY_HOME = /app/var/agy$(if $(filter-out 1,$(or $(ACCOUNT),1)),/account$(ACCOUNT))
.PHONY: help init build up down restart logs ps sh psql migrate admin user apk-cleanup news articles translate translate-requested analyze storyline-tune embedding-compare market advice digest digest-preview agy-login agy-status test test-tunnel e2e-tunnel build-tunnel install-ubuntu-tunnel clean backup-env backup backup-db backup-status restore-db restore-files decrypt-secrets apk tunnel-apk cep-engine cep-linux cep-linux-deps test-cep-linux install-apk install-tunnel-apk reverse release deploy

help: ## Список команд
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# --- Сервер ---

init: ## Создать .env со случайными секретами (один раз)
	@if [ -f .env ]; then echo ".env уже есть — не трогаю"; else \
		printf 'APP_SECRET=%s\nPOSTGRES_PASSWORD=%s\nARGON_MASTER_PASSWORD=%s\nHTTP_PORT=8001\nARGON_UDP_PORT=51820\n' \
			"$$(openssl rand -hex 32)" "$$(openssl rand -hex 24)" "$$(openssl rand -hex 16)" > .env && \
		echo "Создан .env (секреты сгенерированы)"; fi

build: init ## Собрать образ сервера
	$(COMPOSE) --profile backup build

up: init ## Запустить сервер и базу (миграции применяются при старте)
	$(COMPOSE) up -d --build
	@printf "Жду сервер"; for i in $$(seq 1 90); do \
		if curl -fs -o /dev/null http://localhost:$(HTTP_PORT)/admin/login; then \
			printf "\nГотово: API http://localhost:$(HTTP_PORT), админка http://localhost:$(HTTP_PORT)/admin\n"; exit 0; fi; \
		printf "."; sleep 2; done; printf "\nСервер не ответил — смотрите make logs\n"; exit 1

down: ## Остановить (данные сохраняются)
	$(COMPOSE) down

restart: down up ## Перезапустить

logs: ## Логи сервера и базы
	$(COMPOSE) logs -f --tail=100

ps: ## Состояние контейнеров
	$(COMPOSE) ps

sh: ## Shell в контейнере сервера
	$(APP) sh

psql: ## Консоль PostgreSQL
	$(COMPOSE) exec database psql -U app app

migrate: ## Применить миграции вручную
	$(APP) php bin/console doctrine:migrations:migrate --no-interaction

admin: ## Создать администратора: make admin EMAIL=admin@example.com (пароль спросит)
	@test -n "$(EMAIL)" || { echo "Укажите EMAIL=..."; exit 1; }
	$(APP) php bin/console app:user:create "$(EMAIL)" --admin

user: ## Создать пользователя или сменить ему пароль: make user EMAIL=...
	@test -n "$(EMAIL)" || { echo "Укажите EMAIL=..."; exit 1; }
	$(APP) php bin/console app:user:create "$(EMAIL)"

apk-cleanup: ## Удалить APK, которых нет ни на одном устройстве (запускается и после каждой сверки)
	$(APP) php bin/console app:apk:cleanup

news: ## Собрать новости сейчас, не дожидаясь контейнера news (он собирает раз в 5 минут)
	$(APP) php bin/console app:news:collect

articles: ## Скачать полные тексты новостей со страниц изданий сейчас (LIMIT=30 за проход)
	$(COMPOSE) exec news php bin/console app:news:articles --limit=$(or $(LIMIT),30)

translate: ## Перевести заголовки и анонсы новостей на других языках сейчас (LIMIT=40; статьи — только по кнопке, ARTICLES=N — принудительно)
	$(COMPOSE) exec news php bin/console app:news:translate --limit=$(or $(LIMIT),40) --articles=$(or $(ARTICLES),0)

translate-requested: ## Перевести статьи, которые попросили перевести на планшете или в виджете (обычно делает сервис translate)
	$(COMPOSE) exec translate php bin/console app:news:translate-requested

analyze: ## Векторы новостей и сюжетные линии сейчас (REBUILD=1 — заново векторы и сюжеты, REASSIGN=1 — заново только сюжеты)
	$(COMPOSE) exec news php bin/console app:news:analyze $(if $(REBUILD),--rebuild) $(if $(REASSIGN),--reassign)

embedding-compare: ## Сравнить векторы Gemini и Qwen на сюжетах часовых сводок (HOURS=48)
	$(COMPOSE) exec news php bin/console app:news:embedding-compare --hours=$(or $(HOURS),48)

storyline-tune: ## Подобрать порог сюжетов по готовым векторам (DAYS=2 — за сколько дней новости)
	$(COMPOSE) exec news php bin/console app:news:storyline-tune --days=$(or $(DAYS),2)

market: ## Последствия новостей для Мосбиржи сейчас: связь с инструментами (agy) и замер цены (ISS)
	$(COMPOSE) exec news php bin/console app:news:market

advice: ## Анализ инструментов виджета Мосбиржи сейчас (agy): новости, индикаторы, покупки → совет (LIMIT=3 за проход)
	$(COMPOSE) exec news php bin/console app:market:analyze --limit=$(or $(LIMIT),3)

agy-status: ## Состояние agy: аккаунты и их лимиты, модели, очередь новостей, последняя сводка и журнал за час
	@$(COMPOSE) exec news php bin/console app:agy:status
	@echo
	@echo "Журнал за час (шаги с agy):"
	@$(COMPOSE) logs --since 1h news translate 2>&1 | grep -E "agy:|перевод|сюжеты:|мосбиржа|анализ инструментов|hour:|day:" | tail -15 || true

digest: ## Написать сводки новостей за прошедший час и сутки, если их ещё нет (REBUILD=1 — переписать)
	$(COMPOSE) exec news php bin/console app:news:digest $(if $(REBUILD),--rebuild)

digest-preview: ## Сводка за последние часы без сохранения: make digest-preview HOURS=3 MODEL="Gemini 3.1 Pro (High)"
	$(COMPOSE) exec news php bin/console app:news:digest --preview --hours=$(or $(HOURS),1) $(if $(MODEL),--model="$(MODEL)")

agy-login: ## Войти в аккаунт Google для agy: откройте ссылку, вставьте код, выйдите /quit; запасной аккаунт — ACCOUNT=2
	$(COMPOSE) exec -it -e HOME=$(AGY_HOME) news sh -c "mkdir -p $(AGY_HOME) && agy"

test: ## Тесты сервера в контейнере
	docker build --target dev -t contactsync-server-test ./server
	docker run --rm contactsync-server-test

clean: ## Удалить контейнеры и ВСЕ данные (база, APK): make clean CONFIRM=yes
	@test "$(CONFIRM)" = "yes" || { echo "Удалит базу и сохранённые APK. Запустите: make clean CONFIRM=yes"; exit 1; }
	$(COMPOSE) down -v

# --- Резервные копии на Яндекс.Диск и шару D: компьютера 192.168.1.133 (схема — как у KB). Расписание — cron хоста, пример — README, «Резервные копии».
# Пока BACKUP_ENABLED не 1, бекап ничего не делает; восстановление работает всегда.

# Восстановление — из первой копии (Яндекс) или из FROM=/media/share/backup/sync
BACKUP = $(COMPOSE) --profile backup run --rm -T $(if $(FROM),-e BACKUP_FROM=$(FROM)) backup

backup-env: ## Пароль архива секретов в .env, если его ещё нет: сохраните его и в менеджере паролей
	@grep -q '^BACKUP_PASSPHRASE=.' .env || { pass=$$(openssl rand -base64 33 | tr -d '/+='); \
	  grep -q '^BACKUP_PASSPHRASE=' .env && sed -i.bak "s|^BACKUP_PASSPHRASE=.*|BACKUP_PASSPHRASE=$$pass|" .env || echo "BACKUP_PASSPHRASE=$$pass" >> .env; \
	  rm -f .env.bak; echo "BACKUP_PASSPHRASE задан в .env — сохраните его в менеджере паролей"; }

backup: ## Дневной бекап: секреты, БД, зеркала фото и APK; по воскресеньям и 1-го — срезы
	$(BACKUP) daily

backup-db: ## Дамп БД между дневными запусками (слот hours/<00|06|12|18>)
	$(BACKUP) db

backup-status: ## Последние запуски бекапа и слоты на Диске
	@$(BACKUP) status

restore-db: ## БД из слота: make restore-db SLOT=daily/Mon (weekly/w1..w4, monthly/01..12, hours/00..18). Заменяет текущие данные
	$(COMPOSE) stop app news
	$(BACKUP) restore-db $(SLOT)
	$(COMPOSE) start app news

restore-files: ## WHAT=photos (по умолчанию) или apk; без SLOT — зеркало current. Файлы, появившиеся после бекапа, остаются
	BACKUP_STORAGE_MODE=rw $(BACKUP) restore-files $(or $(WHAT),photos) $(or $(SLOT),current)

decrypt-secrets: ## Секреты слота SLOT — в BACKUP_DIR/restore/; файлы раскладываются по местам вручную
	$(BACKUP) decrypt-secrets $(SLOT)

# --- Android-клиент (нужны Android SDK и подключённое устройство) ---

ANDROID_SDK ?= $(or $(ANDROID_HOME),$(ANDROID_SDK_ROOT),$(HOME)/Library/Android/sdk)

android/local.properties:
	@test -d "$(ANDROID_SDK)" || { echo "Android SDK не найден в $(ANDROID_SDK) — укажите ANDROID_SDK=/путь/к/sdk"; exit 1; }
	echo "sdk.dir=$(ANDROID_SDK)" > $@

CEP_AAR := android/app/libs/cepcore.aar

apk: android/local.properties $(CEP_AAR) ## Собрать debug APK (сервер по умолчанию — VPS); другой: make apk SERVER_URL=http://host:port
	cd android && ./gradlew assembleDebug $(if $(SERVER_URL),-PserverUrl=$(SERVER_URL))

tunnel-apk: android/local.properties ## Собрать debug APK клиента Argon Tunnel
	cd android && ./gradlew :tunnel:assembleDebug

# Движок CEP пересобирается, только если менялись его исходники: сборка gomobile идёт минуты.
$(CEP_AAR): $(wildcard android/cep-engine/*.go android/cep-engine/go.mod android/cep-engine/go.sum)
	android/cep-engine/build.sh

cep-engine: ## Пересобрать движок CEP (Go + sing-box → android/app/libs/cepcore.aar; нужны Go, NDK, gomobile)
	android/cep-engine/build.sh

install-apk: apk ## Поставить APK на подключённое устройство
	adb install -r $(APK)

install-tunnel-apk: tunnel-apk ## Поставить APK Argon Tunnel на подключённое устройство
	adb install -r $(TUNNEL_APK)

reverse: ## Пробросить порт сервера на устройство: в приложении адрес http://127.0.0.1:<порт>
	adb reverse tcp:$(HTTP_PORT) tcp:$(HTTP_PORT)

# --- Обновление приложения: сборка на сервере (Android SDK — в Docker) ---

# Ключ подписи: тот же debug-ключ, что на машине разработчика, — иначе обновление не встанет поверх установленного.
KEYSTORE     ?= $(HOME)/.android/debug.keystore
GRADLE_CACHE ?= $(HOME)/.cache/contactsync-gradle
BUILDER      := contactsync-android-builder

release: ## Собрать и опубликовать APK, если клиент (android/) изменился с активной версии; FORCE=1 — собрать всё равно
	@test -f "$(KEYSTORE)" || { echo "Нет ключа подписи $(KEYSTORE): скопируйте ~/.android/debug.keystore с машины разработчика (или KEYSTORE=путь)"; exit 1; }
	@rev=$$(git rev-parse HEAD:android); \
	active=$$($(APP_T) php bin/console app:release:revision | tr -d '\r'); \
	if [ "$$rev" = "$$active" ] && [ -z "$(FORCE)" ]; then echo "Клиент не менялся с активной версии — сборка не нужна"; exit 0; fi; \
	code=$$(git rev-list --count HEAD -- android); \
	echo "Собираю версию 1.$$code (несколько минут, первый раз — дольше: скачивается Android SDK и зависимости)"; \
	docker build --platform linux/amd64 -q -t $(BUILDER) docker/android-builder > /dev/null && \
	mkdir -p "$(GRADLE_CACHE)" && \
	docker run --rm --platform linux/amd64 --user "$$(id -u):$$(id -g)" \
		-v "$(CURDIR):/src" -v "$(GRADLE_CACHE):/gradle" -v "$(KEYSTORE):/keystore/debug.keystore:ro" \
		$(if $(wildcard android/local.properties),-v /dev/null:/src/android/local.properties:ro) \
		$(BUILDER) sh -c "cep-engine/build.sh && ./gradlew --no-daemon --no-watch-fs -q assembleRelease -PversionCode=$$code -PsigningStoreFile=/keystore/debug.keystore" && \
	$(COMPOSE) cp android/app/build/outputs/apk/release/app-release.apk app:/tmp/contactsync.apk && \
	$(APP_T) php bin/console app:release:publish /tmp/contactsync.apk --version-code=$$code --version-name=1.$$code --revision=$$rev && \
	$(APP_T) rm -f /tmp/contactsync.apk

deploy: ## Раскатка на сервере: git pull, make up и, если клиент изменился, make release
	git pull --ff-only
	$(MAKE) up
	$(MAKE) release

# --- Терминальный CEP для Linux: сборка из исходников под выбранную платформу ---

cep-linux: cep-linux-deps $(CEP_BUILD_DIR)/cep-argon4 $(CEP_BUILD_DIR)/cep-cli ## Собрать терминальный CEP и подтянуть недостающие зависимости
	@test "$(GOOS)" = "linux" || { echo "Для Linux укажите GOOS=linux или запускайте на Linux"; exit 1; }
	@echo "Готово: $(CEP_BUILD_DIR)"

cep-linux-deps: ## Установить только отсутствующие системные зависимости CEP
	@if [ "$(CEP_INSTALL_DEPS)" = "1" ]; then \
		SING_BOX_VERSION="$(SING_BOX_VERSION)" XRAY_VERSION="$(XRAY_VERSION)" \
			cep/scripts/ensure-linux-deps.sh; \
	fi

$(CEP_BUILD_DIR):
	@mkdir -p "$@"

# Go-модули скачиваются повторно только после изменения go.mod/go.sum.
$(CEP_GO_DEPS_STAMP): $(CEP_GO_MOD_FILES) | cep-linux-deps
	@mkdir -p "$(dir $@)"
	cd cep/argon4 && $(GO) mod download
	@touch "$@"

$(CEP_BUILD_DIR)/cep-argon4: $(CEP_SOURCE_FILES) $(CEP_GO_DEPS_STAMP) | $(CEP_BUILD_DIR)
	cd cep/argon4 && CGO_ENABLED=0 GOOS="$(GOOS)" GOARCH="$(GOARCH)" \
		$(GO) build -trimpath -ldflags="-s -w" -o "$@" ./cmd/cep-argon4

$(CEP_BUILD_DIR)/cep-cli: $(CEP_SOURCE_FILES) $(CEP_GO_DEPS_STAMP) | $(CEP_BUILD_DIR)
	cd cep/argon4 && CGO_ENABLED=0 GOOS="$(GOOS)" GOARCH="$(GOARCH)" \
		$(GO) build -trimpath -ldflags="-s -w" -o "$@" ./cmd/cep-cli

test-cep-linux: $(CEP_GO_DEPS_STAMP) ## Проверить терминальный CEP и Argon4
	cd cep/argon4 && $(GO) test -race ./...
	cd cep/argon4 && $(GO) vet ./...
	bash cep/scripts/test-linux-service.sh

.PHONY: install-cep-linux configure-cep-linux cep-service-start cep-service-stop cep-service-restart cep-service-status cep-service-logs

install-cep-linux: cep-linux ## Собрать, настроить цепочку и установить CEP как systemd-сервис
	CEP_BUILD_DIR="$(CEP_BUILD_DIR)" cep/scripts/linux-service.sh install

configure-cep-linux: ## Интерактивно создать цепочку или добавить узлы в установленный CEP
	cep/scripts/linux-service.sh configure

cep-service-start: ## Запустить CEP и проверить состояние сервиса
	cep/scripts/linux-service.sh start

cep-service-stop: ## Остановить CEP и очистить его маршруты
	cep/scripts/linux-service.sh stop

cep-service-restart: ## Перезапустить CEP и проверить состояние сервиса
	cep/scripts/linux-service.sh restart

cep-service-status: ## Показать состояние CEP systemd-сервиса
	cep/scripts/linux-service.sh status

cep-service-logs: ## Показать журнал CEP (выход — Ctrl-C)
	cep/scripts/linux-service.sh logs

# --- Шлюз-надстройка Argon4 Tunnel (Ubuntu сервер) ---

build-tunnel: ## Собрать бинарник серверного демона Argon4 для Linux/Ubuntu
	docker run --rm -v "$(PWD)/tunnel-server:/src" -w /src -e CGO_ENABLED=0 $(GO_IMAGE) \
		go build -trimpath -ldflags="-s -w" -o argon-tunnel-server ./cmd/argon-tunnel-server

test-tunnel: ## Запустить unit-тесты протокола шифрования Argon4 в Docker
	docker run --rm -v "$(PWD)/tunnel-server:/src" -w /src $(GO_IMAGE) go test -v ./...

e2e-tunnel: ## Сквозной тест Argon4 туннеля в Docker (Ubuntu TUN + NAT + HTTP в Contact Sync)
	@PASS=$$(grep -s '^ARGON_MASTER_PASSWORD=' .env | cut -d= -f2); \
	PASS=$${PASS:-change-me-master-password}; \
	echo "==> 1. Проверка рукопожатия, отправки материала и маршрутизации через TUN в Contact Sync (10.44.0.1:80 -> app:80)"; \
	docker run --rm --network sync_default --cap-add NET_ADMIN --device /dev/net/tun:/dev/net/tun \
		--entrypoint /usr/local/bin/argon-tunnel-client sync-tunnel \
		-server tunnel:51820 -interface eth0 -tun argon-c0 \
		-password "$$PASS" \
		-send-material "argon4-encrypted-material" \
		-probe-url http://10.44.0.1:80/admin/login

install-ubuntu-tunnel: ## Установить демон Argon4 Tunnel как systemd-сервис на Ubuntu (требует sudo)
	sudo ./tunnel-server/ubuntu/install-ubuntu.sh
