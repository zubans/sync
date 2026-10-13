#!/usr/bin/env bash
# Резервные копии Contact Sync на Яндекс.Диск. Схема — как у бекапа KB (docker/backup в kb, ADR-017):
# rclone, слоты по кругу, зеркало файлов со старыми версиями в дневном слоте.
#
# Приоритеты определяют, как часто и как глубоко хранится уровень:
#   P0 secrets — .env (APP_SECRET, пароль БД) и вход agy для сводок новостей; шифруется BACKUP_PASSPHRASE.
#                                                                                 daily + weekly + monthly
#   P1 db      — PostgreSQL целиком: аккаунты, контакты, календари, хранилища паролей (шифротекст), списки
#                приложений, новости.                                             hours + daily + weekly + monthly
#   P1 photos  — фото контактов (var/storage/photos).                             current + daily + weekly + monthly
#   P2 apk     — APK приложений (var/storage/apk): десятки гигабайт; пока приложение стоит на телефоне,
#                тот же APK загрузится снова.                                      current + daily
#
# Куда — BACKUP_REMOTES, пути rclone через пробел: Яндекс.Диск и шара D: компьютера 192.168.1.133 (на хосте
# смонтирована в /media/share, в контейнер — по тому же пути). Дамп и секреты снимаются один раз и заливаются
# в каждую копию по очереди; недоступная копия пропускается, остальные пишутся, запуск в итоге — с ошибкой.
#
# Раскладка в каждой копии ($REMOTE):
#   photos/current/, apk/current/    зеркала хранилища на момент последнего запуска
#   hours/<00|06|12|18>/db.dump      дамп БД каждые 6 ч — точки восстановления внутри суток
#   daily/<Mon..Sun>/                secrets, db.dump; photos/ и apk/ — старые версии файлов,
#                                    которые sync в этот день удалил или перезаписал в зеркалах
#   weekly/w<1..4>/                  по воскресеньям: secrets, db.dump и полная копия photos/current
#   monthly/<01..12>/                1-го числа: то же
# Полные копии делаются на стороне Яндекса, без трафика сервера.
#
# Не копируются: недокачанные APK (apk/uploads), сертификаты Caddy (сервер работает по HTTP), сам agy и его кэши,
# переписки и логи.
set -euo pipefail
# set -e и внутри $(…): иначе ошибка в slot_target или fetch прошла бы незаметно
shopt -s inherit_errexit

STAGE=/backup
SRC=/src
STORAGE=/storage
AGY=/agy
read -ra REMOTES <<< "${BACKUP_REMOTES:-yandex:backup/sync /media/share/backup/sync}"
# копия, в которую пишет процесс заливки (each_remote передаёт её в BACKUP_FROM); восстановление — из BACKUP_FROM или первой копии
REMOTE=${BACKUP_FROM:-${REMOTES[0]}}
TODAY=$(date +%F)
DOW=$(LC_ALL=C date +%a)
# неделя считается от начала эпохи, а не по ISO: у ISO в годы с 53 неделями слот w2 перезаписывается дважды подряд
WEEK="w$(( $(date +%s) / 604800 % 4 + 1 ))"
MONTH=$(date +%m)
RC=(--transfers 8 --stats-one-line --stats 60s)

# rclone.conf пользователя хоста (remote yandex — тот же, что у бекапов KB и trash) смонтирован только на чтение.
# Работаем с копией: обновлённый токен rclone пишет в неё и не меняет владельца файла хоста
if [[ -f /rclone/rclone.conf ]]; then
    cp /rclone/rclone.conf /tmp/rclone.conf
else
    touch /tmp/rclone.conf
fi
export RCLONE_CONFIG=/tmp/rclone.conf

export PGHOST=database PGUSER=app PGDATABASE=app PGPASSWORD=${POSTGRES_PASSWORD:-}

umask 077

log() { echo "[$(date '+%F %T')] $*"; }
die() { echo "ОШИБКА: $*" >&2; exit 1; }
size() { du -sh "$1" | cut -f1; }

# Итог запуска — для make backup-status: время, результат, подробности. $1 — запуск и копия: daily@yandex:backup/sync
status() {
    mkdir -p "$STAGE/status"
    printf '%s %s %s\n' "$(date -Iseconds)" "$2" "${3:-}" > "$STAGE/status/${1//\//%}"
}

# Один запуск за раз: дневной бекап с APK может наложиться на дамп БД по расписанию
lock() {
    mkdir -p "$STAGE/.locks"
    mkdir "$STAGE/.locks/$1" 2>/dev/null || die "$1: предыдущий запуск ещё идёт (если он упал — удалите $STAGE/.locks/$1)."
    trap "rmdir '$STAGE/.locks/$1'" EXIT
}

# БД могла быть перезапущена как раз в момент бекапа — ждём до 5 минут
wait_db() {
    for _ in $(seq 60); do
        pg_isready -q && return 0
        sleep 5
    done
    die "db: PostgreSQL не отвечает 5 минут."
}

# $1 — файл дампа в стейдже: у дневного запуска и дампа по расписанию свои, они могут идти одновременно
dump_db() {
    local file=$STAGE/$1
    wait_db
    pg_dump -Fc -Z 6 -f "$file.tmp"
    pg_restore --list "$file.tmp" > /dev/null || die "db: дамп не читается pg_restore."
    mv "$file.tmp" "$file"
    log "db: $(size "$file")"
}

dump_secrets() {
    [[ -n ${BACKUP_PASSPHRASE:-} ]] || die "secrets: не задан BACKUP_PASSPHRASE в .env (make backup-env)."
    local list=$STAGE/secrets.list
    : > "$list"
    cd /
    [[ -f $SRC/.env ]] && printf 'src/.env\0' >> "$list"
    # HOME agy — это сам agy на сотни мегабайт, переписки и логи; для входа нужны только маленькие файлы настроек и токенов
    if [[ -d $AGY ]]; then
        find agy \( -type d \( -name '*cache*' -o -name '*Cache*' -o -name logs -o -name log -o -name bin -o -name builtin \
            -o -name conversations -o -name annotations -o -name brain -o -name tmp -o -name updater \
            -o -name presence -o -name implicit -o -name crashes \) -prune \) \
            -o -type f -size -256k ! -name '*.jsonl' -print0 >> "$list"
    fi
    # пути в архиве: src/… — папка проекта, agy/… — том agy_data
    tar -C / --null -T "$list" -czf - \
        | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE -out "$STAGE/secrets.tar.gz.enc.tmp"
    mv "$STAGE/secrets.tar.gz.enc.tmp" "$STAGE/secrets.tar.gz.enc"
    log "secrets: $(tr -cd '\0' < "$list" | wc -c) файлов, $(size "$STAGE/secrets.tar.gz.enc")"
    rm -f "$list"
    cd "$STAGE"
}

stamp_of() { rclone cat "$REMOTE/$1/stamp" 2> /dev/null || true; }

# Слот пишется в <слот>.new и заменяет старый только после успешной заливки: сбой посередине не стирает
# прошлонедельный слот. Повторный запуск в тот же день дописывает в уже готовый слот, а не пересоздаёт его —
# иначе пропали бы старые версии файлов, ушедшие в слот при первом запуске. Печатает, куда писать
slot_target() {
    local slot=$1 new=$1.new
    if [[ $(stamp_of "$slot") == "$TODAY" ]]; then
        echo "$slot"
        return
    fi
    if [[ $(stamp_of "$new") != "$TODAY" ]]; then
        # остаток упавшего запуска прошлых дней
        rclone purge "$REMOTE/$new" 2> /dev/null || true
        echo "$TODAY" | rclone rcat "$REMOTE/$new/stamp"
    fi
    echo "$new"
}

slot_commit() {
    local slot=$1 target=$2
    [[ $target == "$slot" ]] && return 0
    rclone purge "$REMOTE/$slot" 2> /dev/null || true
    rclone moveto "$REMOTE/$target" "$REMOTE/$slot"
}

# Зеркало каталога хранилища; удалённое и перезаписанное уходит в дневной слот
mirror() {
    local name=$1 target=$2
    [[ -d $STORAGE/$name ]] || { log "$name: каталога ещё нет — пропускаю"; return 0; }
    log "$name: $STORAGE/$name ($(size "$STORAGE/$name")) → $REMOTE/$name/current"
    rclone sync "$STORAGE/$name" "$REMOTE/$name/current" --exclude '/uploads/**' --backup-dir "$REMOTE/$target/$name" "${RC[@]}"
}

# Полный срез: секреты, дамп и копия зеркала фото на стороне Яндекса
snapshot() {
    local slot=$1 target
    log "== $slot"
    target=$(slot_target "$slot")
    rclone copyto "$STAGE/secrets.tar.gz.enc" "$REMOTE/$target/secrets.tar.gz.enc"
    rclone copyto "$STAGE/db.dump" "$REMOTE/$target/db.dump"
    # пустое зеркало (фото ещё нет) rclone не создаёт
    if rclone lsf --max-depth 1 "$REMOTE/photos/current" > /dev/null 2>&1; then
        rclone sync "$REMOTE/photos/current" "$REMOTE/$target/photos" "${RC[@]}"
    fi
    slot_commit "$slot" "$target"
}

# Доступна ли копия: remote rclone отвечает, локальный путь (шара) смонтирован и пишется
reachable() {
    if [[ $REMOTE == *:* ]]; then
        rclone lsf --max-depth 1 "${REMOTE%%:*}:" > /dev/null 2>&1 \
            || { log "$REMOTE: нет доступа к ${REMOTE%%:*}: — проверьте remote в rclone.conf пользователя хоста (BACKUP_RCLONE_DIR)"; return 1; }
    else
        # каталог не создаём: на несмонтированной шаре он оказался бы на диске сервера
        [[ -d $REMOTE ]] && touch "$REMOTE/.probe" 2> /dev/null && rm -f "$REMOTE/.probe" \
            || { log "$REMOTE: каталога нет или он не пишется — шара не смонтирована?"; return 1; }
    fi
}

# Снять дамп один раз и залить в каждую копию отдельным процессом (см. run); $1 — daily или db
each_remote() {
    local kind=$1 failed=0
    for REMOTE in "${REMOTES[@]}"; do
        log "-> $REMOTE"
        if reachable && BACKUP_FROM=$REMOTE "$0" "_push_$kind"; then
            continue
        fi
        status "$kind@$REMOTE" failed
        failed=1
    done
    return $failed
}

backup_daily() {
    dump_secrets
    dump_db db.dump
    each_remote daily
}

push_daily() {
    local slot=daily/$DOW target
    log "== $slot"
    target=$(slot_target "$slot")
    rclone copyto "$STAGE/secrets.tar.gz.enc" "$REMOTE/$target/secrets.tar.gz.enc"
    rclone copyto "$STAGE/db.dump" "$REMOTE/$target/db.dump"
    mirror photos "$target"
    mirror apk "$target"
    slot_commit "$slot" "$target"
    [[ $(date +%u) == 7 ]] && snapshot "weekly/$WEEK"
    [[ $(date +%d) == 01 ]] && snapshot "monthly/$MONTH"
    status "daily@$REMOTE" ok "$slot"
}

# Дамп БД по расписанию между дневными: слот по 6-часовому интервалу, перезаписывается через сутки
backup_db() {
    dump_db hours.dump
    each_remote db
}

push_db() {
    local slot hour
    hour=$((10#$(date +%H) / 6 * 6))
    slot=$(printf 'hours/%02d' "$hour")
    rclone copyto "$STAGE/hours.dump" "$REMOTE/$slot/db.dump.part"
    rclone moveto "$REMOTE/$slot/db.dump.part" "$REMOTE/$slot/db.dump"
    log "db: $REMOTE/$slot"
    status "db@$REMOTE" ok "$slot"
}

# Запуск отдельным процессом: в функции под || bash отключает set -e, и ошибка посередине прошла бы незаметно
run() {
    log "==> $1 → ${REMOTES[*]}"
    "$0" "_$1" || { log "==> $1: не все копии записаны"; return 1; }
    log "==> готово"
}

# Слот из аргумента: daily/Mon, weekly/w2, monthly/10, hours/06
fetch() {
    local slot=${1:-} file=$2 out
    [[ -n $slot ]] || die "укажите слот: SLOT=daily/Mon (weekly/w1..w4, monthly/01..12, hours/00..18 — только БД)."
    out=$STAGE/restore/${slot//\//-}
    mkdir -p "$out"
    rclone copyto -q "$REMOTE/$slot/$file" "$out/$file" 2> /dev/null || die "в $REMOTE/$slot нет $file."
    echo "$out/$file"
}

restore_db() {
    local file
    file=$(fetch "${1:-}" db.dump)
    wait_db
    log "Восстановление БД из $1 (текущие данные будут заменены)"
    pg_restore --clean --if-exists --no-owner --single-transaction -d "$PGDATABASE" "$file"
    log "Готово"
}

# current — зеркало; weekly/…, monthly/… — полный срез фото; daily/… — старые версии за тот день,
# накладываются поверх (состояние на дату D — слоты от последнего дня к D+1)
restore_files() {
    local what=${1:-photos} slot=${2:-current} src
    case $what in photos | apk) ;; *) die "restore-files: WHAT=photos или apk" ;; esac
    if [[ $slot == current ]]; then src=$REMOTE/$what/current; else src=$REMOTE/$slot/$what; fi
    log "$src → $STORAGE/$what"
    # copy, а не sync: файлы, появившиеся после бекапа, не удаляются
    rclone copy "$src" "$STORAGE/$what" "${RC[@]}"
    # сервер работает от www-data, а rclone пишет от root
    chown -R "$(stat -c %u:%g "$STORAGE")" "$STORAGE/$what"
}

decrypt_secrets() {
    local file out
    [[ -n ${BACKUP_PASSPHRASE:-} ]] || die "decrypt-secrets: задайте BACKUP_PASSPHRASE (из менеджера паролей)."
    file=$(fetch "${1:-}" secrets.tar.gz.enc)
    out=${file%.tar.gz.enc}
    mkdir -p "$out"
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$file" -out "$out.tar.gz" 2> /dev/null \
        || { rm -f "$out.tar.gz"; die "decrypt-secrets: неверный BACKUP_PASSPHRASE или архив повреждён."; }
    tar -C "$out" -xzf "$out.tar.gz"
    rm -f "$out.tar.gz"
    log "Расшифровано в $out: src/.env — в корень проекта, agy/ — в том agy_data"
}

show_status() {
    local t f
    for REMOTE in "${REMOTES[@]}"; do
        echo "$REMOTE"
        for t in daily db; do
            f=$STAGE/status/$t@${REMOTE//\//%}
            if [[ -f $f ]]; then
                printf '  %-6s %s\n' "$t" "$(cat "$f")"
            else
                printf '  %-6s %s\n' "$t" 'ни разу'
            fi
        done
        rclone lsf --dirs-only "$REMOTE" 2> /dev/null | tr '\n' ' ' | sed 's/^/  /;s/$/\n/' || echo "  недоступен"
    done
}

cd "$STAGE"
cmd=${1:-}
shift || true

# выключенный бекап — не ошибка: cron может стоять заранее
if [[ ($cmd == daily || $cmd == db) && ${BACKUP_ENABLED:-0} != 1 ]]; then
    log "Бекап выключен (BACKUP_ENABLED=1 в .env, чтобы включить) — пропускаю $cmd"
    exit 0
fi

case $cmd in
    daily | db) run "$cmd" ;;
    _daily)
        lock daily
        backup_daily
        ;;
    _db)
        lock db
        backup_db
        ;;
    _push_daily) push_daily ;;
    _push_db) push_db ;;
    status) show_status ;;
    restore-db) restore_db "$@" ;;
    restore-files) restore_files "$@" ;;
    decrypt-secrets) decrypt_secrets "$@" ;;
    *) die "команды: daily | db | status | restore-db <слот> | restore-files <photos|apk> [слот] | decrypt-secrets <слот>" ;;
esac
