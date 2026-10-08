# Contact Sync: сервер в Docker, Android-клиент — локальным Android SDK.
# Список команд: make help

COMPOSE   := docker compose
APP       := $(COMPOSE) exec app
HTTP_PORT ?= $(shell grep -s '^HTTP_PORT=' .env | cut -d= -f2)
HTTP_PORT := $(or $(HTTP_PORT),8001)
APK       := android/app/build/outputs/apk/debug/app-debug.apk

.DEFAULT_GOAL := help
.PHONY: help init build up down restart logs ps sh psql migrate admin user apk-cleanup test clean apk install-apk reverse

help: ## Список команд
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# --- Сервер ---

init: ## Создать .env со случайными секретами (один раз)
	@if [ -f .env ]; then echo ".env уже есть — не трогаю"; else \
		printf 'APP_SECRET=%s\nPOSTGRES_PASSWORD=%s\nHTTP_PORT=8001\n' \
			"$$(openssl rand -hex 32)" "$$(openssl rand -hex 24)" > .env && \
		echo "Создан .env (секреты сгенерированы)"; fi

build: init ## Собрать образ сервера
	$(COMPOSE) build

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

test: ## Тесты сервера в контейнере
	docker build --target dev -t contactsync-server-test ./server
	docker run --rm contactsync-server-test

clean: ## Удалить контейнеры и ВСЕ данные (база, APK): make clean CONFIRM=yes
	@test "$(CONFIRM)" = "yes" || { echo "Удалит базу и сохранённые APK. Запустите: make clean CONFIRM=yes"; exit 1; }
	$(COMPOSE) down -v

# --- Android-клиент (нужны Android SDK и подключённое устройство) ---

ANDROID_SDK ?= $(or $(ANDROID_HOME),$(ANDROID_SDK_ROOT),$(HOME)/Library/Android/sdk)

android/local.properties:
	@test -d "$(ANDROID_SDK)" || { echo "Android SDK не найден в $(ANDROID_SDK) — укажите ANDROID_SDK=/путь/к/sdk"; exit 1; }
	echo "sdk.dir=$(ANDROID_SDK)" > $@

apk: android/local.properties ## Собрать debug APK; адрес сервера по умолчанию: make apk SERVER_URL=http://host:port
	cd android && ./gradlew assembleDebug $(if $(SERVER_URL),-PserverUrl=$(SERVER_URL))

install-apk: apk ## Поставить APK на подключённое устройство (без SERVER_URL — ещё и проброс порта)
	$(if $(SERVER_URL),,$(MAKE) reverse)
	adb install -r $(APK)

reverse: ## Пробросить порт сервера на устройство: в приложении адрес http://127.0.0.1:<порт>
	adb reverse tcp:$(HTTP_PORT) tcp:$(HTTP_PORT)
