# Резервные копии на Яндекс.Диск: pg_dump той же версии, что сервер БД, rclone для внешней копии
FROM alpine:3.22

RUN apk add --no-cache bash coreutils findutils tar gzip openssl rclone postgresql16-client tzdata

COPY backup.sh /usr/local/bin/sync-backup
RUN chmod +x /usr/local/bin/sync-backup

ENTRYPOINT ["sync-backup"]
