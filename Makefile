# Contact Sync: сервер в Docker, Android-клиент — локальным Android SDK (или в Docker при make release).
# Список команд: make help

COMPOSE   := docker compose
APP       := $(COMPOSE) exec app
APP_T     := $(COMPOSE) exec -T app
HTTP_PORT ?= $(shell grep -s '^HTTP_PORT=' .env | cut -d= -f2)
HTTP_PORT := $(or $(HTTP_PORT),8001)
APK       := android/app/build/outputs/apk/debug/app-debug.apk

.DEFAULT_GOAL := help
.PHONY: help init build up down restart logs ps sh psql migrate admin user apk-cleanup news articles translate analyze market advice digest digest-preview agy-login test clean backup-env backup backup-db backup-status restore-db restore-files decrypt-secrets apk install-apk reverse release deploy

help: ## Список команд
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# --- Сервер ---

init: ## Создать .env со случайными секретами (один раз)
	@if [ -f .env ]; then echo ".env уже есть — не трогаю"; else \
		printf 'APP_SECRET=%s\nPOSTGRES_PASSWORD=%s\nHTTP_PORT=8001\n' \
			"$$(openssl rand -hex 32)" "$$(openssl rand -hex 24)" > .env && \
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

translate: ## Перевести новости изданий на других языках сейчас (LIMIT=40 заголовков, ARTICLES=3 статьи за проход)
	$(COMPOSE) exec news php bin/console app:news:translate --limit=$(or $(LIMIT),40) --articles=$(or $(ARTICLES),3)

analyze: ## Векторы новостей и сюжетные линии сейчас (нужен NEWS_EMBEDDINGS_URL)
	$(COMPOSE) exec news php bin/console app:news:analyze

market: ## Последствия новостей для Мосбиржи сейчас: связь с инструментами (agy) и замер цены (ISS)
	$(COMPOSE) exec news php bin/console app:news:market

advice: ## Анализ инструментов виджета Мосбиржи сейчас (agy): новости, индикаторы, покупки → совет (LIMIT=3 за проход)
	$(COMPOSE) exec news php bin/console app:market:analyze --limit=$(or $(LIMIT),3)

digest: ## Написать сводки новостей за прошедший час и сутки, если их ещё нет (REBUILD=1 — переписать)
	$(COMPOSE) exec news php bin/console app:news:digest $(if $(REBUILD),--rebuild)

digest-preview: ## Сводка за последние часы без сохранения: make digest-preview HOURS=3 MODEL="Gemini 3.1 Pro (High)"
	$(COMPOSE) exec news php bin/console app:news:digest --preview --hours=$(or $(HOURS),1) $(if $(MODEL),--model="$(MODEL)")

agy-login: ## Войти в аккаунт Google для сводок (Antigravity CLI): откройте ссылку, вставьте код, выйдите /quit
	$(COMPOSE) exec -it -e HOME=/app/var/agy news sh -c "mkdir -p /app/var/agy && agy"

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

apk: android/local.properties ## Собрать debug APK (сервер по умолчанию — VPS); другой: make apk SERVER_URL=http://host:port
	cd android && ./gradlew assembleDebug $(if $(SERVER_URL),-PserverUrl=$(SERVER_URL))

install-apk: apk ## Поставить APK на подключённое устройство
	adb install -r $(APK)

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
		$(BUILDER) ./gradlew --no-daemon --no-watch-fs -q assembleRelease -PversionCode=$$code -PsigningStoreFile=/keystore/debug.keystore && \
	$(COMPOSE) cp android/app/build/outputs/apk/release/app-release.apk app:/tmp/contactsync.apk && \
	$(APP_T) php bin/console app:release:publish /tmp/contactsync.apk --version-code=$$code --version-name=1.$$code --revision=$$rev && \
	$(APP_T) rm -f /tmp/contactsync.apk

deploy: ## Раскатка на сервере: git pull, make up и, если клиент изменился, make release
	git pull --ff-only
	$(MAKE) up
	$(MAKE) release
