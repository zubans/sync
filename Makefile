# Contact Sync: структурированное управление по функциональным блокам.
# Список команд по блокам: make help

COMPOSE    := docker compose
APP        := $(COMPOSE) exec app
APP_T      := $(COMPOSE) exec -T app
HTTP_PORT  ?= $(shell grep -s '^HTTP_PORT=' .env | cut -d= -f2)
HTTP_PORT  := $(or $(HTTP_PORT),8001)

APK        := android/app/build/outputs/apk/debug/app-debug.apk
TUNNEL_APK := android/tunnel/build/outputs/apk/debug/tunnel-debug.apk
CEP_AAR    := android/app/libs/cepcore.aar

GO_VERSION ?= 1.27
GO_IMAGE   := golang:$(GO_VERSION)-alpine
GO         ?= go
GOOS       ?= $(shell $(GO) env GOOS 2>/dev/null || uname -s | tr '[:upper:]' '[:lower:]')
GOARCH     ?= $(shell $(GO) env GOARCH 2>/dev/null || uname -m | sed -e 's/^x86_64$$/amd64/' -e 's/^aarch64$$/arm64/' -e 's/^armv7l$$/arm/')
CEP_BUILD_DIR     := $(CURDIR)/cep/build/$(GOOS)-$(GOARCH)
CEP_GO_DEPS_STAMP := $(CURDIR)/cep/build/.go-modules.stamp
CEP_GO_MOD_FILES  := $(wildcard cep/argon4/go.mod cep/argon4/go.sum)
CEP_SOURCE_FILES  := $(wildcard cep/argon4/*.go cep/argon4/cmd/cep-cli/*.go cep/argon4/cmd/cep-argon4/*.go)
SING_BOX_VERSION  ?= 1.14.3
XRAY_VERSION      ?= 26.3.27
CEP_INSTALL_DEPS  ?= 1

ANDROID_SDK  ?= $(or $(ANDROID_HOME),$(ANDROID_SDK_ROOT),$(HOME)/Library/Android/sdk)
KEYSTORE     ?= $(HOME)/.android/debug.keystore
GRADLE_CACHE ?= $(HOME)/.cache/contactsync-gradle
BUILDER      := contactsync-android-builder

BACKUP = $(COMPOSE) --profile backup run --rm -T $(if $(FROM),-e BACKUP_FROM=$(FROM)) backup

.DEFAULT_GOAL := help

.PHONY: help \
	init env-ai build up down restart logs ps sh psql migrate admin user test clean \
	device-apk device-install-apk device-reverse device-apk-cleanup device-release device-deploy \
	backup-env backup backup-db backup-status restore-db restore-files decrypt-secrets \
	news-up news-down news-collect news-articles news-translate news-translate-req news-analyze \
	news-emb-compare news-tune news-digest news-digest-prev market-impact market-advice \
	ai-status ai-add ai-login ai-check ai-toggle ai-remove agy-login \
	vpn-up vpn-down vpn-argon4-up vpn-argon4-test vpn-argon4-e2e vpn-argon4-ubuntu \
	vpn-ocserv-up vpn-ocserv-down vpn-ocserv-logs vpn-ocserv-status vpn-ocserv-ip vpn-ocserv-add-user vpn-ocserv-rm-user \
	vpn-cep-linux vpn-cep-linux-test vpn-cep-linux-inst vpn-cep-linux-cfg \
	vpn-cep-linux-start vpn-cep-linux-stop vpn-cep-linux-restart vpn-cep-linux-stat vpn-cep-linux-logs \
	vpn-cep-mac-deps vpn-cep-mac-build vpn-cep-mac-dmg vpn-cep-mac-test \
	vpn-android-engine vpn-legacy-argon-apk vpn-legacy-argon-inst \
	apk install-apk reverse apk-cleanup release deploy \
	news articles translate translate-requested analyze embedding-compare storyline-tune \
	market advice digest digest-preview agy-status \
	cep-engine tunnel-apk install-tunnel-apk cep-linux test-cep-linux \
	install-cep-linux configure-cep-linux cep-service-start cep-service-stop \
	cep-service-restart cep-service-status cep-service-logs \
	test-tunnel e2e-tunnel install-ubuntu-tunnel \
	ocserv-up ocserv-down ocserv-logs ocserv-status ocserv-ip ocserv-add-user ocserv-rm-user

help: ## Показать список команд по блокам
	@awk ' \
		/^# === .* ===$$/ { sub(/^# === /, ""); sub(/ ===$$/, ""); printf "\n\033[1;33m[%s]\033[0m\n", $$0; next } \
		/^[a-zA-Z0-9_.-]+:.*## / { split($$0, a, ":.*## "); printf "  \033[36m%-24s\033[0m %s\n", a[1], a[2] } \
	' $(MAKEFILE_LIST)

# === Блок 1: Система, сервер и пользователи (Core) ===

init: ## Создать .env со случайными секретами и ключом шифрования ИИ (один раз)
	@if [ -f .env ]; then echo ".env уже есть — не трогаю (добавить ключ ИИ: make env-ai)"; else \
		printf 'APP_SECRET=%s\nAPP_ENCRYPTION_KEY=base64:%s\nPOSTGRES_PASSWORD=%s\nARGON_MASTER_PASSWORD=%s\nHTTP_PORT=8001\nARGON_UDP_PORT=51820\n# Профили Docker Compose: news (новости и перевод), vpn (Argon4 Tunnel и ocserv), backup\nCOMPOSE_PROFILES=news\n' \
			"$$(openssl rand -hex 32)" "$$(openssl rand -base64 32)" "$$(openssl rand -hex 24)" "$$(openssl rand -hex 16)" > .env && \
		echo "Создан .env (секреты и APP_ENCRYPTION_KEY сгенерированы)"; fi

env-ai: ## Добавить APP_ENCRYPTION_KEY в существующий .env, если его ещё нет
	@test -f .env || $(MAKE) init
	@grep -q '^APP_ENCRYPTION_KEY=.' .env || { key="base64:$$(openssl rand -base64 32)"; \
		grep -q '^APP_ENCRYPTION_KEY=' .env && sed -i.bak "s|^APP_ENCRYPTION_KEY=.*|APP_ENCRYPTION_KEY=$$key|" .env || echo "APP_ENCRYPTION_KEY=$$key" >> .env; \
		rm -f .env.bak; echo "APP_ENCRYPTION_KEY добавлен в .env"; }

build: init ## Собрать Docker-образы сервера и бэкапа
	$(COMPOSE) --profile backup build

up: init ## Запустить сервер и базу (и активные профили COMPOSE_PROFILES из .env)
	$(COMPOSE) up -d --build
	@printf "Жду сервер"; for i in $$(seq 1 90); do \
		if curl -fs -o /dev/null http://localhost:$(HTTP_PORT)/admin/login; then \
			printf "\nГотово: API http://localhost:$(HTTP_PORT), админка http://localhost:$(HTTP_PORT)/admin\n"; exit 0; fi; \
		printf "."; sleep 2; done; printf "\nСервер не ответил — смотрите make logs\n"; exit 1

down: ## Остановить все контейнеры проекта (данные сохраняются)
	$(COMPOSE) --profile news --profile vpn --profile backup down

restart: down up ## Перезапустить контейнеры проекта

logs: ## Поток логов запущенных контейнеров
	$(COMPOSE) logs -f --tail=100

ps: ## Показать состояние контейнеров
	$(COMPOSE) ps

sh: ## Открыть shell в контейнере веб-сервера (app)
	$(APP) sh

psql: ## Открыть консоль PostgreSQL
	$(COMPOSE) exec database psql -U app app

migrate: ## Применить миграции БД вручную
	$(APP) php bin/console doctrine:migrations:migrate --no-interaction

admin: ## Создать администратора: make admin EMAIL=admin@example.com
	@test -n "$(EMAIL)" || { echo "Укажите EMAIL=..."; exit 1; }
	$(APP) php bin/console app:user:create "$(EMAIL)" --admin

user: ## Создать пользователя или сменить пароль: make user EMAIL=...
	@test -n "$(EMAIL)" || { echo "Укажите EMAIL=..."; exit 1; }
	$(APP) php bin/console app:user:create "$(EMAIL)"

test: ## Запустить PHPUnit-тесты сервера в Docker
	docker build --target dev -t contactsync-server-test ./server
	docker run --rm contactsync-server-test

clean: ## Удалить контейнеры и ВСЕ данные (БД, APK, сессии): make clean CONFIRM=yes
	@test "$(CONFIRM)" = "yes" || { echo "Удалит базу и файлы! Запустите: make clean CONFIRM=yes"; exit 1; }
	$(COMPOSE) --profile news --profile vpn --profile backup down -v

# === Блок 2: Данные устройства (контакты, пароли, приложения, календарь, бэкапы) ===

android/local.properties:
	@test -d "$(ANDROID_SDK)" || { echo "Android SDK не найден в $(ANDROID_SDK) — укажите ANDROID_SDK=/путь/к/sdk"; exit 1; }
	echo "sdk.dir=$(ANDROID_SDK)" > $@

$(CEP_AAR): $(wildcard android/cep-engine/*.go android/cep-engine/argon4/*.go android/cep-engine/go.mod android/cep-engine/go.sum)
	android/cep-engine/build.sh

device-apk: android/local.properties $(CEP_AAR) ## Собрать debug APK клиента Android (SERVER_URL=http://host:port)
	cd android && ./gradlew assembleDebug $(if $(SERVER_URL),-PserverUrl=$(SERVER_URL))

device-install-apk: device-apk ## Установить собранный APK на подключённое по ADB устройство
	adb install -r $(APK)

device-reverse: ## Пробросить порт сервера на устройство (127.0.0.1:$(HTTP_PORT))
	adb reverse tcp:$(HTTP_PORT) tcp:$(HTTP_PORT)

device-apk-cleanup: ## Удалить неиспользуемые APK с сервера (app:apk:cleanup)
	$(APP) php bin/console app:apk:cleanup

device-release: ## Собрать и опубликовать release APK на сервер в Docker (FORCE=1)
	@test -f "$(KEYSTORE)" || { echo "Нет ключа подписи $(KEYSTORE): скопируйте ~/.android/debug.keystore (или KEYSTORE=путь)"; exit 1; }
	@rev=$$(git rev-parse HEAD:android); \
	active=$$($(APP_T) php bin/console app:release:revision | tr -d '\r'); \
	if [ "$$rev" = "$$active" ] && [ -z "$(FORCE)" ]; then echo "Клиент не менялся с активной версии — сборка не нужна"; exit 0; fi; \
	code=$$(git rev-list --count HEAD -- android); \
	echo "Собираю версию 1.$$code"; \
	docker build --platform linux/amd64 -q -t $(BUILDER) docker/android-builder > /dev/null && \
	mkdir -p "$(GRADLE_CACHE)" && \
	docker run --rm --platform linux/amd64 --user "$$(id -u):$$(id -g)" \
		-v "$(CURDIR):/src" -v "$(GRADLE_CACHE):/gradle" -v "$(KEYSTORE):/keystore/debug.keystore:ro" \
		$(if $(wildcard android/local.properties),-v /dev/null:/src/android/local.properties:ro) \
		$(BUILDER) sh -c "cep-engine/build.sh && ./gradlew --no-daemon --no-watch-fs -q assembleRelease -PversionCode=$$code -PsigningStoreFile=/keystore/debug.keystore" && \
	$(COMPOSE) cp android/app/build/outputs/apk/release/app-release.apk app:/tmp/contactsync.apk && \
	$(APP_T) php bin/console app:release:publish /tmp/contactsync.apk --version-code=$$code --version-name=1.$$code --revision=$$rev && \
	$(APP_T) rm -f /tmp/contactsync.apk

device-deploy: ## Выложить сервер (git pull + make up) и при изменениях пересобрать APK
	git pull --ff-only
	$(MAKE) up
	$(MAKE) device-release

backup-env: ## Сгенерировать BACKUP_PASSPHRASE в .env для шифрования резервных копий
	@grep -q '^BACKUP_PASSPHRASE=.' .env || { pass=$$(openssl rand -base64 33 | tr -d '/+='); \
		grep -q '^BACKUP_PASSPHRASE=' .env && sed -i.bak "s|^BACKUP_PASSPHRASE=.*|BACKUP_PASSPHRASE=$$pass|" .env || echo "BACKUP_PASSPHRASE=$$pass" >> .env; \
		rm -f .env.bak; echo "BACKUP_PASSPHRASE задан в .env — сохраните его в менеджере паролей"; }

backup: ## Выполнить дневной бекап (секреты, БД, фото контактов, APK)
	$(BACKUP) daily

backup-db: ## Снять внеочередной дамп БД (слот hours/<00|06|12|18>)
	$(BACKUP) db

backup-status: ## Показать состояние бэкапов и доступные слоты на Диске
	@$(BACKUP) status

restore-db: ## Восстановить БД из слота (SLOT=daily/Mon, weekly/w1, hours/12)
	$(COMPOSE) stop app news
	$(BACKUP) restore-db $(SLOT)
	$(COMPOSE) start app
	-@$(COMPOSE) --profile news start news translate 2>/dev/null || true

restore-files: ## Восстановить файлы (WHAT=photos|apk, SLOT=current)
	BACKUP_STORAGE_MODE=rw $(BACKUP) restore-files $(or $(WHAT),photos) $(or $(SLOT),current)

decrypt-secrets: ## Расшифровать архив секретов слота SLOT в BACKUP_DIR/restore/
	$(BACKUP) decrypt-secrets $(SLOT)

# === Блок 3: Новости и рынок акций (Мосбиржа) ===

news-up: init ## Запустить фоновые воркеры новостей и перевода (profile=news)
	$(COMPOSE) --profile news up -d --build news translate

news-down: ## Остановить фоновые воркеры новостей и перевода
	$(COMPOSE) --profile news stop news translate

news-collect: ## Собрать RSS-новости сейчас (app:news:collect)
	$(APP) php bin/console app:news:collect

news-articles: ## Скачать полные тексты новостей (LIMIT=30)
	$(APP) php bin/console app:news:articles --limit=$(or $(LIMIT),30)

news-translate: ## Перевести заголовки/статьи через ИИ (LIMIT=40, ARTICLES=0)
	$(APP) php bin/console app:news:translate --limit=$(or $(LIMIT),40) --articles=$(or $(ARTICLES),0)

news-translate-req: ## Перевести статьи, запрошенные кнопкой на клиентах
	$(APP) php bin/console app:news:translate-requested

news-analyze: ## Построить векторы и сюжетные линии (REBUILD=1, REASSIGN=1)
	$(APP) php bin/console app:news:analyze $(if $(REBUILD),--rebuild) $(if $(REASSIGN),--reassign)

news-emb-compare: ## Сравнить векторы Gemini и Qwen (HOURS=48)
	$(APP) php bin/console app:news:embedding-compare --hours=$(or $(HOURS),48)

news-tune: ## Подобрать пороги сюжетных линий (DAYS=2)
	$(APP) php bin/console app:news:storyline-tune --days=$(or $(DAYS),2)

news-digest: ## Сформировать часовую и суточную сводки новостей (REBUILD=1)
	$(APP) php bin/console app:news:digest $(if $(REBUILD),--rebuild)

news-digest-prev: ## Предпросмотр сводки без сохранения (HOURS=1 MODEL="...")
	$(APP) php bin/console app:news:digest --preview --hours=$(or $(HOURS),1) $(if $(MODEL),--model="$(MODEL)")

market-impact: ## Оценить влияние новостей на инструменты Мосбиржи (app:news:market)
	$(APP) php bin/console app:news:market

market-advice: ## Анализ акций Мосбиржи и рекомендации по покупкам (LIMIT=3)
	$(APP) php bin/console app:market:analyze --limit=$(or $(LIMIT),3)

# === Блок 4: Подключённые провайдеры ИИ и CLI-агенты ===

ai-status: ## Состояние провайдеров ИИ, аккаунтов agy/CLI-агентов, лимитов и очереди
	@$(APP) php bin/console app:agy:status
	@echo
	@echo "Журнал за час (шаги с ИИ):"
	@$(COMPOSE) logs --since 1h news translate 2>&1 | grep -E "agy:|перевод|сюжеты:|мосбиржа|анализ инструментов|hour:|day:" | tail -15 || true

ai-add: ## Добавить аккаунт CLI-агента: make ai-add TYPE=agy|claude|codex|gemini-cli|kimi [SLOT=...] [LABEL=...] [TOKEN=...]
	@test -n "$(TYPE)" || { echo "Укажите TYPE=agy|claude|codex|gemini-cli|kimi"; exit 1; }
	$(APP) php bin/console app:ai:agent-add "$(TYPE)" \
		$(if $(SLOT),--slot="$(SLOT)") \
		$(if $(LABEL),--label="$(LABEL)") \
		$(if $(PRIORITY),--priority="$(PRIORITY)") \
		$(if $(TOKEN),--token="$(TOKEN)") \
		$(if $(DISABLED),--disabled)

ai-login: ## Вход OAuth/API для CLI-агента: make ai-login TYPE=agy ACCOUNT=main [TOKEN=...] [LOGOUT=1]
	$(COMPOSE) exec -it app php bin/console app:ai:agent-login "$(or $(TYPE),agy)" "$(or $(ACCOUNT),main)" \
		$(if $(LABEL),--label="$(LABEL)") \
		$(if $(TOKEN),--token="$(TOKEN)") \
		$(if $(LOGOUT),--logout)

ai-check: ## Проверить вход и работоспособность аккаунтов CLI-агентов ([ID=<id или type:slot>])
	$(APP) php bin/console app:ai:agent-check $(if $(ID),"$(ID)")

ai-toggle: ## Включить/выключить слот CLI-агента: make ai-toggle ID=<id> STATE=enable|disable
	@test -n "$(ID)" || { echo "Укажите ID=<id или type:slot>"; exit 1; }
	$(APP) php bin/console app:ai:agent-toggle "$(ID)" $(if $(filter enable,$(STATE)),--enable) $(if $(filter disable,$(STATE)),--disable)

ai-remove: ## Удалить слот CLI-агента: make ai-remove ID=<id> [KEEP_FILES=1]
	@test -n "$(ID)" || { echo "Укажите ID=<id или type:slot>"; exit 1; }
	$(APP) php bin/console app:ai:agent-remove "$(ID)" $(if $(KEEP_FILES),--keep-files)

agy-login: ## Быстрый вход в Antigravity CLI (ACCOUNT=1|2|... или имя слота)
	$(COMPOSE) exec -it app php bin/console app:ai:agent-login agy "$(or $(ACCOUNT),main)"

# === Блок 5: VPN и шифрование (CEP / Argon4 Tunnel / ocserv) ===

vpn-up: init ## Запустить серверы Argon4 Tunnel и OpenConnect (profile=vpn)
	$(COMPOSE) --profile vpn up -d --build tunnel ocserv

vpn-down: ## Остановить контейнерные VPN-серверы (tunnel и ocserv)
	$(COMPOSE) --profile vpn stop tunnel ocserv

vpn-argon4-up: init ## Запустить только сервер Argon4 UDP Tunnel в Docker
	$(COMPOSE) --profile vpn up -d --build tunnel

vpn-argon4-test: ## Unit-тесты криптопротокола Argon4 Tunnel (tunnel-server) в Docker
	docker run --rm -v "$(PWD)/tunnel-server:/src" -w /src $(GO_IMAGE) go test -v ./...

vpn-argon4-e2e: ## E2E-тест Argon4 Tunnel (TUN + NAT -> app:80) в Docker
	@PASS=$$(grep -s '^ARGON_MASTER_PASSWORD=' .env | cut -d= -f2); \
	PASS=$${PASS:-change-me-master-password}; \
	echo "==> Проверка рукопожатия, передачи материала и маршрута 10.44.0.1:80 -> app:80"; \
	docker run --rm --network sync_default --cap-add NET_ADMIN --device /dev/net/tun:/dev/net/tun \
		--entrypoint /usr/local/bin/argon-tunnel-client sync-tunnel \
		-server tunnel:51820 -interface eth0 -tun argon-c0 \
		-password "$$PASS" \
		-send-material "argon4-encrypted-material" \
		-probe-url http://10.44.0.1:80/admin/login

vpn-argon4-ubuntu: ## Установить Argon4 Tunnel как systemd-сервис на Ubuntu (sudo)
	sudo ./tunnel-server/ubuntu/install-ubuntu.sh

vpn-ocserv-up: init ## Собрать и запустить OpenConnect VPN-сервер (ocserv)
	$(COMPOSE) --profile vpn up -d --build ocserv

vpn-ocserv-down: ## Остановить OpenConnect VPN-сервер (данные и сертификаты сохраняются)
	$(COMPOSE) --profile vpn stop ocserv

vpn-ocserv-logs: ## Логи OpenConnect VPN-сервера (ocserv)
	$(COMPOSE) --profile vpn logs -f --tail=100 ocserv

vpn-ocserv-status: ## Показать подключённых клиентов ocserv
	$(COMPOSE) --profile vpn exec -T ocserv occtl show users

vpn-ocserv-ip: ## Узнать VPN-IP клиента ocserv (OCSERV_USER=имя)
	@test -n "$(OCSERV_USER)" || { echo "Укажите OCSERV_USER=..."; exit 1; }
	$(COMPOSE) --profile vpn exec -T ocserv occtl show user "$(OCSERV_USER)" | grep -i 'IP:' || echo "Пользователь $(OCSERV_USER) не подключён"

vpn-ocserv-add-user: ## Добавить пользователя ocserv в .env и перезапустить (OCSERV_USER=... OCSERV_PASS=...)
	@test -n "$(OCSERV_USER)" || { echo "Укажите OCSERV_USER=..."; exit 1; }
	@test -n "$(OCSERV_PASS)" || { echo "Укажите OCSERV_PASS=..."; exit 1; }
	@if grep -q '^OCSERV_USERS=' .env; then \
		sed -i.bak "s|^OCSERV_USERS=.*|OCSERV_USERS=$$(grep '^OCSERV_USERS=' .env | cut -d= -f2-),$(OCSERV_USER):$(OCSERV_PASS)|" .env && rm -f .env.bak; \
	else echo "OCSERV_USERS=$(OCSERV_USER):$(OCSERV_PASS)" >> .env; fi
	$(COMPOSE) --profile vpn up -d ocserv

vpn-ocserv-rm-user: ## Удалить пользователя ocserv из .env (OCSERV_USER=...)
	@test -n "$(OCSERV_USER)" || { echo "Укажите OCSERV_USER=..."; exit 1; }
	@if grep -q '^OCSERV_USERS=' .env; then \
		cur=$$(grep '^OCSERV_USERS=' .env | cut -d= -f2-); \
		rest=$$(printf '%s' "$$cur" | tr ',' '\n' | grep -v "^$(OCSERV_USER):" | paste -sd, -); \
		sed -i.bak "s|^OCSERV_USERS=.*|OCSERV_USERS=$$rest|" .env && rm -f .env.bak; \
	fi
	$(COMPOSE) --profile vpn up -d ocserv

$(CEP_BUILD_DIR):
	@mkdir -p "$@"

$(CEP_GO_DEPS_STAMP): $(CEP_GO_MOD_FILES)
	@if [ "$(CEP_INSTALL_DEPS)" = "1" ] && [ "$(GOOS)" = "linux" ]; then \
		SING_BOX_VERSION="$(SING_BOX_VERSION)" XRAY_VERSION="$(XRAY_VERSION)" cep/scripts/ensure-linux-deps.sh; \
	fi
	@mkdir -p "$(dir $@)"
	cd cep/argon4 && $(GO) mod download
	@touch "$@"

$(CEP_BUILD_DIR)/cep-argon4: $(CEP_SOURCE_FILES) $(CEP_GO_DEPS_STAMP) | $(CEP_BUILD_DIR)
	cd cep/argon4 && CGO_ENABLED=0 GOOS="$(GOOS)" GOARCH="$(GOARCH)" \
		$(GO) build -trimpath -ldflags="-s -w" -o "$@" ./cmd/cep-argon4

$(CEP_BUILD_DIR)/cep-cli: $(CEP_SOURCE_FILES) $(CEP_GO_DEPS_STAMP) | $(CEP_BUILD_DIR)
	cd cep/argon4 && CGO_ENABLED=0 GOOS="$(GOOS)" GOARCH="$(GOARCH)" \
		$(GO) build -trimpath -ldflags="-s -w" -o "$@" ./cmd/cep-cli

vpn-cep-linux: $(CEP_BUILD_DIR)/cep-argon4 $(CEP_BUILD_DIR)/cep-cli ## Собрать клиент CEP для Linux (cep-argon4 + cep-cli)
	@test "$(GOOS)" = "linux" || { echo "Для Linux укажите GOOS=linux или запускайте на Linux"; exit 1; }
	@echo "Готово: $(CEP_BUILD_DIR)"

vpn-cep-linux-test: $(CEP_GO_DEPS_STAMP) ## Go-тесты CEP/Argon4 (-race, vet) и проверка systemd-скрипта
	cd cep/argon4 && $(GO) test -race ./...
	cd cep/argon4 && $(GO) vet ./...
	bash cep/scripts/test-linux-service.sh

vpn-cep-linux-inst: vpn-cep-linux ## Установить CEP клиентом как systemd-сервис Linux
	CEP_BUILD_DIR="$(CEP_BUILD_DIR)" cep/scripts/linux-service.sh install

vpn-cep-linux-cfg: ## Интерактивно настроить цепочку узлов в Linux CEP
	cep/scripts/linux-service.sh configure

vpn-cep-linux-start: ## Запустить systemd-сервис CEP Linux
	cep/scripts/linux-service.sh start

vpn-cep-linux-stop: ## Остановить systemd-сервис CEP Linux
	cep/scripts/linux-service.sh stop

vpn-cep-linux-restart: ## Перезапустить systemd-сервис CEP Linux
	cep/scripts/linux-service.sh restart

vpn-cep-linux-stat: ## Показать статус systemd-сервиса CEP Linux
	cep/scripts/linux-service.sh status

vpn-cep-linux-logs: ## Показать журнал systemd-сервиса CEP Linux
	cep/scripts/linux-service.sh logs

vpn-cep-mac-deps: ## Установить инструменты сборки macOS-клиента CEP (Swift/Go)
	cep/scripts/install-deps.sh

vpn-cep-mac-build: ## Собрать macOS-приложение ContactSyncCEP.app
	cep/scripts/build-app.sh

vpn-cep-mac-dmg: ## Собрать DMG-образ ContactSyncCEP для macOS
	cep/scripts/make-dmg.sh

vpn-cep-mac-test: ## Запустить Swift-тесты macOS-клиента CEP и Go-тесты Argon4
	cd cep && swift test
	cd cep/argon4 && $(GO) test ./...

vpn-android-engine: ## Пересобрать Go/gomobile-движок CEP для Android (cepcore.aar)
	android/cep-engine/build.sh

vpn-legacy-argon-apk: android/local.properties ## [УСТАРЕЛО] Собрать отдельный debug APK Argon Tunnel (:tunnel)
	cd android && ./gradlew :tunnel:assembleDebug

vpn-legacy-argon-inst: vpn-legacy-argon-apk ## [УСТАРЕЛО] Установить отдельный APK :tunnel по ADB
	adb install -r $(TUNNEL_APK)

# --- Скрытые псевдонимы совместимости (не засоряют make help) ---
apk: device-apk
install-apk: device-install-apk
reverse: device-reverse
apk-cleanup: device-apk-cleanup
release: device-release
deploy: device-deploy
news: news-collect
articles: news-articles
translate: news-translate
translate-requested: news-translate-req
analyze: news-analyze
embedding-compare: news-emb-compare
storyline-tune: news-tune
market: market-impact
advice: market-advice
digest: news-digest
digest-preview: news-digest-prev
agy-status: ai-status
cep-engine: vpn-android-engine
tunnel-apk: vpn-legacy-argon-apk
install-tunnel-apk: vpn-legacy-argon-inst
cep-linux: vpn-cep-linux
test-cep-linux: vpn-cep-linux-test
install-cep-linux: vpn-cep-linux-inst
configure-cep-linux: vpn-cep-linux-cfg
cep-service-start: vpn-cep-linux-start
cep-service-stop: vpn-cep-linux-stop
cep-service-restart: vpn-cep-linux-restart
cep-service-status: vpn-cep-linux-stat
cep-service-logs: vpn-cep-linux-logs
test-tunnel: vpn-argon4-test
e2e-tunnel: vpn-argon4-e2e
install-ubuntu-tunnel: vpn-argon4-ubuntu
ocserv-up: vpn-ocserv-up
ocserv-down: vpn-ocserv-down
ocserv-logs: vpn-ocserv-logs
ocserv-status: vpn-ocserv-status
ocserv-ip: vpn-ocserv-ip
ocserv-add-user: vpn-ocserv-add-user
ocserv-rm-user: vpn-ocserv-rm-user
