# Contact Sync: сервер в Docker, Android-клиент — локальным Android SDK.
# Список команд: make help

COMPOSE   := docker compose
APP       := $(COMPOSE) exec app
HTTP_PORT ?= $(shell grep -s '^HTTP_PORT=' .env | cut -d= -f2)
HTTP_PORT := $(or $(HTTP_PORT),8001)
APK       := android/app/build/outputs/apk/debug/app-debug.apk

.DEFAULT_GOAL := help
# Домашняя папка agy: основной аккаунт — /app/var/agy, запасные (при лимите основного) — /app/var/agy/account2…
AGY_HOME = /app/var/agy$(if $(filter-out 1,$(or $(ACCOUNT),1)),/account$(ACCOUNT))
.PHONY: help init build up down restart logs ps sh psql migrate admin user apk-cleanup news articles translate translate-requested analyze market advice digest digest-preview agy-login test clean backup-env backup backup-db backup-status restore-db restore-files decrypt-secrets apk install-apk reverse

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

translate: ## Перевести заголовки и анонсы новостей на других языках сейчас (LIMIT=40; статьи — только по кнопке, ARTICLES=N — принудительно)
	$(COMPOSE) exec news php bin/console app:news:translate --limit=$(or $(LIMIT),40) --articles=$(or $(ARTICLES),0)

translate-requested: ## Перевести статьи, которые попросили перевести на планшете или в виджете (обычно делает сервис translate)
	$(COMPOSE) exec translate php bin/console app:news:translate-requested

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

apk: android/local.properties ## Собрать debug APK (сервер по умолчанию — VPS); другой: make apk SERVER_URL=http://host:port
	cd android && ./gradlew assembleDebug $(if $(SERVER_URL),-PserverUrl=$(SERVER_URL))

install-apk: apk ## Поставить APK на подключённое устройство
	adb install -r $(APK)

reverse: ## Пробросить порт сервера на устройство: в приложении адрес http://127.0.0.1:<порт>
	adb reverse tcp:$(HTTP_PORT) tcp:$(HTTP_PORT)
