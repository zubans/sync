# OpenConnect VPN-сервер (`ocserv`)

Статус: реализовано (контейнер `ocserv` в Docker Compose, собирается из исходников).

OpenConnect VPN-сервер — альтернатива Argon4-туннелю для устройств и систем, где есть встроенный
клиент AnyConnect / OpenConnect (iOS, macOS, Windows, Linux, Android через сторонний клиент).
Говорит по протоколу Cisco AnyConnect поверх TLS (TCP) и DTLS (UDP).

```
docker/ocserv/   Dockerfile (сборка ocserv из исходников), ocserv.conf, entrypoint.sh
docs/ocserv.md   этот документ
```

---

## 1. Запуск

Сервис `ocserv` добавлен в `compose.yaml`. Пользователи и настройки — в `.env` в корне проекта.

```bash
make ocserv-up
```

При первом старте entrypoint генерирует CA и серверный сертификат (сохраняются в томе `ocserv_data`,
чтобы клиентам не пришлось заново доверять CA при пересоздании контейнера) и создаёт файл
пользователей из `OCSERV_USERS`.

Проверить, что сервер слушает:

```bash
make ocserv-status    # подключённые клиенты
make ocserv-logs      # журнал
```

## 2. Пользователи

Пользователи задаются в `.env` переменной `OCSERV_USERS` (через запятую, `user:password`):

```bash
echo 'OCSERV_USERS=alice:пароль1,bob:пароль2' >> .env
make ocserv-up
```

Удобнее через make (правит `.env` и перезапускает сервис):

```bash
make ocserv-add-user OCSERV_USER=alice OCSERV_PASS=пароль1
make ocserv-rm-user  OCSERV_USER=alice
```

Пароли хранятся в открытом виде в `.env` (как и другие секреты проекта; `.env` в `.gitignore`),
а в контейнере — в хэшированном виде (`ocpasswd`).

## 3. Настройки `.env`

Все переменные необязательные (есть значения по умолчанию):

| Переменная | По умолчанию | Что задаёт |
|---|---|---|
| `OCSERV_TCP_PORT` | `443` | TCP-порт (TLS) |
| `OCSERV_UDP_PORT` | `443` | UDP-порт (DTLS) |
| `OCSERV_SUBNET` / `OCSERV_NETMASK` | `10.45.0.0` / `255.255.255.0` | виртуальная сеть туннеля (не пересекается с Argon4 `10.44.0.0/24`) |
| `OCSERV_DNS` | `1.1.1.1,8.8.8.8` | DNS, выдаваемые клиентам |
| `OCSERV_NO_ROUTES` | `192.168.0.0/16,10.0.0.0/8,172.16.0.0/12,100.64.0.0/10` | локальные сети, не идущие через туннель |
| `OCSERV_OUT_INTERFACE` | `eth0` | внешний интерфейс контейнера для NAT |
| `OCSERV_MAX_CLIENTS` | `16` | максимум одновременных подключений |
| `OCSERV_ORG` / `OCSERV_CN` | `Contact Sync VPN` / `vpn.example.com` | данные самоподписанного сертификата |
| `OCSERV_SERVER_IP` | пусто | IP сервера для SAN сертификата (укажите, если подключаетесь по IP) |
| `OCSERV_ENABLE_NAT` | `1` | включать ли NAT/MASQUERADE |
| `OCSERV_USERS` | пусто | пользователи `user:pass,user:pass` |
| `OCSERV_FORWARD_TARGET` | пусто | VPN-IP клиента для проброса портов (пусто — выключено) |
| `OCSERV_FORWARD_TCP` | `25565` | TCP-порты для проброса (через запятую) |
| `OCSERV_FORWARD_UDP` | `19132` | UDP-порты для проброса (через запятую) |
| `OCSERV_FWD_TCP_PORT` / `OCSERV_FWD_UDP_PORT` | `25565` / `19132` | порты, публикуемые на хосте для проброса |

После правки `.env`: `make ocserv-up` (пересоздаст контейнер с новыми настройками).

## 5. Проброс портов на клиента

Порты, открытые на машине клиента, можно сделать доступными на внешнем адресе сервера (например,
Minecraft: TCP 25565 — Java, UDP 19132 — Bedrock). Трафик, приходящий на сервер на эти порты,
перенаправляется (DNAT) на VPN-IP подключённого клиента.

1. Подключитесь клиентом и узнайте его VPN-IP:

   ```bash
   make ocserv-ip OCSERV_USER=alice
   ```

   (или `make ocserv-status` — там виден IP каждого клиента).

2. Укажите целевой IP и порты в `.env` и перезапустите:

   ```bash
   echo 'OCSERV_FORWARD_TARGET=10.45.0.2' >> .env
   make ocserv-up
   ```

   Порты по умолчанию уже проброшены: TCP `25565` (`OCSERV_FORWARD_TCP`) и UDP `19132`
   (`OCSERV_FORWARD_UDP`), на хосте они опубликованы на тех же портах.

3. Проверьте снаружи: `nc -vz <адрес-сервера> 25565` (TCP) и `nc -u <адрес-сервера> 19132` (UDP).

Замечания:
- IP клиента стабилен для одного пользователя (ocserv использует `predictable-ips`), поэтому правило
  не «слетает» при переподключении.
- Если клиентов несколько, проброс идёт на один целевой IP — укажите IP нужного пользователя.
- Проброс работает только пока целевой клиент подключён к VPN.

## 4. Клиенты

- **iOS / macOS**: системный клиент Cisco AnyConnect или OpenConnect GUI — адрес `https://<сервер>:<порт>`,
  имя пользователя и пароль.
- **Windows**: Cisco AnyConnect или OpenConnect GUI.
- **Linux**: `openconnect -u <user> https://<сервер>:<порт>`.
- **Android**: OpenConnect (по сети) или AnyConnect.

Так как сертификат самоподписанный, клиент попросит подтвердить доверие к CA. Чтобы не подтверждать
каждый раз, импортируйте `ca.pem` (лежит в томе `ocserv_data`, можно скопировать из контейнера:
`make sh` → нет; используйте `docker cp sync-ocserv-1:/etc/ocserv/certs/ca.pem ./ca.pem`).

## 6. Ограничения

- Сервер маршрутизирует весь трафик клиента (`route = 0.0.0.0/0`), кроме локальных сетей
  (`OCSERV_NO_ROUTES`). Если нужно маршрутизировать только Contact Sync — см. Argon4-туннель
  (`docs/argon-tunnel.md`).
- Пользователи пересоздаются при каждом старте из `OCSERV_USERS`; правки `ocpasswd` внутри
  контейнера не сохраняются — пользуйтесь `make ocserv-add-user` / `ocserv-rm-user`.
- Для продакшена замените самоподписанный сертификат на выпущенный для вашего домена
  (положите `server-cert.pem`, `server-key.pem`, `ca.pem` в том `ocserv_data`).