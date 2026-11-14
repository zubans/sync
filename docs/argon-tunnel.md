# Надстройка протокола обмена данными Argon4 Tunnel (`Argon2id` + `AES-256-GCM`)

Статус: реализовано (серверный демон для Ubuntu `tunnel-server/` и отдельное Android-приложение `android/tunnel/`).

Надстройка инкапсулирует и шифрует сетевые пакеты между клиентским устройством и сервером под управлением Ubuntu с использованием мастер-пароля. По умолчанию маршрутизирует трафик приложения **Contact Sync** (`com.example.contactsync`), а также позволяет выбрать любые другие установленные приложения (например, **КБ**, **CEP** и др.) или весь трафик устройства.

```
android/tunnel/   Отдельное Android-приложение «Argon Tunnel» (VpnService, TUN, Argon2id + AES-256-GCM)
tunnel-server/    Серверный демон для Ubuntu (Go, UDP listener на выбранном интерфейсе, TUN/NAT маршрутизация)
```

---

## 1. Формат пакета и криптография («Argon 4»)

Каждый пакет шифруется ключом, выведенным из мастер-пароля через **Argon2id (RFC 9106, v0x13)**, и аутентифицированным шифром **AES-256-GCM**:

```
мастер-пароль + соль (16 байт) ──Argon2id (19 МиБ, 2 прохода, 1 поток)──▶ 256-битный ключ сессии
```

Структура пакета по проводу (UDP-датаграмма, по умолчанию протокол `0x05`, обратная совместимость с `0x04`):

| Смещение | Размер | Поле | Описание |
|---|---|---|---|
| `0..16` | 16 байт | `Salt` | Случайная 16-байтная соль Argon2id клиента |
| `16..30` | 14 байт | `MaskedMeta` | `(Version[1] \|\| FrameType[1] \|\| HeaderCheck[4] \|\| Seq[8]) XOR HMAC-SHA256(PSK, "argon4-hdr:" \|\| Salt)[0..14]`, где `HeaderCheck = HMAC-SHA256(PSK, "argon4-chk:" \|\| Salt \|\| Version \|\| FrameType \|\| Seq)[0..4]`, а `Nonce = HeaderCheck[4] \|\| Seq[8]` (96-бит IV для AES-256-GCM) |
| `30..N-16` | переменный | `Ciphertext` | В `v5` (`0x05`): зашифрованный конверт `PayloadLen[2, BE] \|\| PadLen[2, BE] \|\| Payload \|\| ZeroPad[PadLen]`. В `v4` (`0x04`): сырой payload без конверта padding |
| `N-16..N` | 16 байт | `Tag` | 128-битный тег аутентификации GCM |

- **Дополнительные аутентифицируемые данные (AAD)**: `"argon4-tun:" || Version || FrameType || Salt || Seq`. Заголовок и номер последовательности защищены от подмены: изменение любого поля или перестановка приводит к ошибке проверки заголовка / GCM-тега.
- **Переменный аутентифицированный padding (v5)**: в конверте внутри AEAD добавляется случайный zero-padding по профилям (`balanced`: 8–96 байт / 16–192 для payload <= 160; `compact`: 4–32 / 8–64 для <= 120; `wide`: 16–192 / 32–320 для <= 256; `custom`). Для UDP размер внешнего кадра ограничен бюджетом `pathMTU - 28` (по умолчанию `1472` байта при MTU `1500`).
- **Анти-откат (anti-downgrade) и идемпотентность handshake**: клиент фиксирует ожидаемую версию `0x05` и отклоняет пакеты `0x04` на активной v5-сессии (при этом может быть явно разрешён режим совместимости `allowV4` до завершения согласования). Повтор потерянного UDP-рукопожатия переотправляет байт-в-байт тот же зашифрованный кадр `HandshakeReq` до первого ответа, а серверный handshake-cache переиспользует выданную сессию без изменения IP.
- **Кэширование ключа по соли на сервере**: при первом пакете с новой 16-байтной солью сервер вычисляет ключ через Argon2id и после успешной проверки GCM-тега кэширует его для сессии клиента, поэтому последующие пакеты дешифруются за микросекунды без повторного расчёта Argon2id.

---

## 2. Серверная часть для Ubuntu (`tunnel-server/`)

Серверный демон на Go (без внешних зависимостей, собирается в единый статический бинарник):

1. **Приём на определённый сетевой интерфейс**: флаг `-interface` / переменная `ARGON_LISTEN_INTERFACE` (например, `eth0`, `ens3`) привязывает UDP-сокет именно к IPv4-адресу указанного интерфейса Ubuntu.
2. **Дешифрование мастер-паролем**: флаг `-password` / переменная `ARGON_MASTER_PASSWORD`. Пакеты с неверным мастер-паролем отбрасываются на этапе проверки тега AES-256-GCM.
3. **Создание или оборачивание сетевого интерфейса**:
   - Создаёт или подключается к виртуальному TUN-интерфейсу (`ARGON_TUN_NAME=argon0`, подсеть `10.44.0.1/24`).
   - При указании существующего интерфейса Ubuntu (`ARGON_OUT_INTERFACE=eth0` и `ARGON_ENABLE_NAT=1`) автоматически включает маршрутизацию (`ip_forward=1`) и правила `iptables MASQUERADE` / `FORWARD`, оборачивая трафик между зашифрованным туннелем и выбранным интерфейсом сервера.

### Установка на Ubuntu (systemd)

```bash
make install-ubuntu-tunnel
```

Либо вручную:

```bash
sudo ./tunnel-server/ubuntu/install-ubuntu.sh
sudo nano /etc/argon-tunnel/server.env   # укажите ARGON_MASTER_PASSWORD и интерфейсы
sudo systemctl restart argon-tunnel
sudo journalctl -u argon-tunnel -f
```

### Запуск в Docker Compose (вместе с Contact Sync)

В `compose.yaml` добавлен сервис `tunnel`. В `.env` задаются `ARGON_MASTER_PASSWORD` и `ARGON_UDP_PORT` (по умолчанию `51820`):

```bash
make up
```

---

## 3. Клиентское приложение для Android (`android/tunnel/`)

Отдельный модуль `:tunnel` собирается в самостоятельный APK (`com.example.argontunnel`, **Argon Tunnel**), который устанавливается на телефон наравне с **Contact Sync**, **КБ** или **CEP**.

Возможности клиента:
- **Выбор исходящего интерфейса и адреса сервера**: указание IP/хоста и UDP-порта сервера Ubuntu, а также привязка исходящего сокета к конкретному сетевому интерфейсу устройства (`wlan0`, `rmnet_data0` и др.) с защитой от петли маршрутизации (`VpnService.protect`).
- **Шифрование через мастер-пароль**: ввод мастер-пароля в настройках, вывод ключа через `Argon2id` и шифрование каждого пакета `AES-256-GCM`.
- **Прямая отправка зашифрованного материала**: карточка в интерфейсе позволяет сразу зашифровать произвольный пакет/материал мастер-паролем и отправить его на интерфейс сервера.
- **Универсальная маршрутизация приложений (`VpnService`)**:
  - По умолчанию в зашифрованный канал включён **Contact Sync** (`com.example.contactsync`).
  - В списке установленных приложений можно отметить **КБ**, **CEP** или любые другие приложения (либо добавить имя пакета вручную), а также переключить режим на маршрутизацию всех приложений устройства.

### Сборка и установка APK клиента

```bash
make tunnel-apk
make install-tunnel-apk
```

---

## 4. Режим L4-транзита (`-mode transit` и `-transit-blind`)

Сервер `argon-tunnel-server` умеет работать не только как IPv4 TUN-шлюз (`-mode tunnel`, по умолчанию), но и как L4-транзитный узел (`-mode transit` / `ARGON_MODE=transit`) без создания TUN-интерфейса и выделения виртуальной подсети:

1. **Аутентифицированный TCP/UDP транзит**: клиент подключается к тому же порту (UDP или TCP с 2-байтным префиксом длины кадра), проходит X25519-рукопожатие и указывает целевой `host:port` и транспорт (`udp` / `tcp`), а также пересылает keepalive/`"fin"` для корректного полузакрытия TCP (`CloseWrite`). Цели ограничиваются `-transit-target` (`ARGON_TRANSIT_TARGET`) или списком `-transit-allow` (`ARGON_TRANSIT_ALLOW`).
2. **Слепой passthrough (`-transit-blind` / `ARGON_TRANSIT_BLIND=1`)**: сервер не расшифровывает пакеты (мастер-пароль не обязателен) и прозрачно пересылает UDP/TCP-поток на фиксированный `-transit-target`. В CEP такой узел задаётся с типом `transit` и пустым паролем перед следующим узлом `Argon4` или узлом с `useArgon`.
3. **Согласование `auto`**: клиентская надстройка `useArgon` отправляет запрос с `mode="auto"`, автоматически выбирая L4-транзит перед сервером с `-mode transit` или TUN-туннель перед `-mode tunnel`.

### Параметры протокола v5 и padding (`argon-tunnel-server` / клиенты)

- `-protocol-version` / `ARGON_PROTOCOL_VERSION` (по умолчанию `5`, допустимо `4` или `5`).
- `-allow-v4` / `ARGON_ALLOW_V4` (по умолчанию `true` на сервере для переходного периода с legacy `0x04`-клиентами; установка `false` переводит сервер в строгий режим v5). На активной v5-сессии откат на `v4` отклоняется всегда.
- `-padding-profile` / `ARGON_PADDING_PROFILE` (`balanced` | `compact` | `wide` | `custom`, по умолчанию `balanced`).
- `-first-packet-profile` / `ARGON_FIRST_PACKET_PROFILE` (отдельный профиль padding для первого кадра `HandshakeReq`, по умолчанию `wide`).
- `-path-mtu` / `ARGON_PATH_MTU` (внешний MTU трассы `576..9000`, по умолчанию `1500`; эффективный внутренний TUN MTU снижается автоматически с учётом IP/UDP-заголовка и 50-байтного overhead v5).

### Тайминги handshake и адаптивный idle keepalive

Чтобы устранить фиксированные периодические сигнатуры (`3×3s` handshake и константный цикл `25s`/`30s` keepalive), во всех клиентах и сервере применяется нормализуемая конфигурация таймингов:

- **Handshake (exponential backoff + jitter)**:
  - по умолчанию до `5` попыток с начальным таймаутом `1.2s`, коэффициентом `1.7`, потолком `4.5s`, общим бюджетом `15s`, jitter `±30%` и паузой между попытками `120ms..800ms`;
  - каждая повторная попытка отправляет байт-в-байт тот же зашифрованный `HandshakeReq` для идемпотентности через серверный handshake-cache; при фатальной ошибке протокола (например, anti-downgrade `v5 -> v4`) повторы прекращаются сразу.
- **Idle keepalive со сбросом по активности**:
  - keepalive (`"ping"`/`"pong"` поверх v5 padded-конверта с anti-replay) отправляется только после фактического простоя исходящего канала;
  - любая полезная исходящая передача (`ActivityTracker.Touch`) сдвигает таймер на новый случайный интервал из диапазона `[16s..28s]` (строго `maxIdle > minIdle` и `maxIdle * 2 <= sessionIdleTimeout`, что удерживает NAT-таблицы >30s и сессию на сервере с `SessionIdleTimeout = 10m`);
  - при отмене контекста, остановке прокси или фатальной ошибке сокета горутины/корутины и таймеры закрываются без утечек.
- **Настройка (CLI / env / профили CEP и Kotlin)**:
  - сервер: `-session-idle-timeout` / `ARGON_SESSION_IDLE_TIMEOUT` (по умолчанию `10m`);
  - `argon-tunnel-client`: `-handshake-max-attempts` (`ARGON_HANDSHAKE_MAX_ATTEMPTS`), `-handshake-initial-timeout` (`ARGON_HANDSHAKE_INITIAL_TIMEOUT`), `-handshake-max-timeout` (`ARGON_HANDSHAKE_MAX_TIMEOUT`), `-handshake-max-elapsed` (`ARGON_HANDSHAKE_MAX_ELAPSED`), `-keepalive-min-idle` (`ARGON_KEEPALIVE_MIN_IDLE`), `-keepalive-max-idle` (`ARGON_KEEPALIVE_MAX_IDLE`), `-session-idle-timeout` (`ARGON_SESSION_IDLE_TIMEOUT`);
  - профили CEP (`cep/Sources/CEPCore/Models.swift`, `cep-cli`, `android/cep-engine`): `argonHandshakeMaxAttempts`, `argonHandshakeInitialTimeoutMs`, `argonHandshakeMaxTimeoutMs`, `argonKeepaliveMinIdleSec`, `argonKeepaliveMaxIdleSec`; Kotlin `TunnelSettings`: `handshakeMaxAttempts`, `handshakeInitialTimeoutMs`, `handshakeMaxTimeoutMs`, `keepaliveMinIdleMs`, `keepaliveMaxIdleMs`.

### Поведенческие профили трафика v5 (`profile-v1`, SYNC-656)

По умолчанию поведенческий шейпинг **выключен** (`""` / `off`): включается только явным выбором (`baseline` или `video-call`) и поддерживается только на Argon4 `v5` (попытка включить на `v4`, указать неизвестное имя/версию или использовать `video-call` поверх TCP-транзита немедленно отклоняется без молчаливого отката).

- **Профили**:
  - `off` (по умолчанию): стандартный per-packet padding v5 + адаптивный idle keepalive без отложенной очереди.
  - `baseline`: лёгкое сглаживание длин и временное объединение серии мелких пакетов (`FrameTypeBatch = 0x05`, `<= 8 мс` добавленной задержки, окно `1..4 мс`), уменьшающее число мелких кадров на проводе (~`-5%..-15%` пакетов при коротких всплесках) практически без фонового расхода.
  - `video-call`: сессионная модель, калиброванная по живому дампу Яндекс Телемоста (три архетипа сеанса: `telemost-av-active`, `telemost-hd-dynamic`, `telemost-low-cam`):
    - ограничение внешнего кадра (`1184..1228` Б, рекомендованный TUN MTU `1120..1150` Б, по умолчанию `1140`),
    - многомодальные корзины размеров (кластер мелких аудио/RTCP `92..196` Б, проба/RTX `278..342` Б, медиа-корзины `620..1210` Б),
    - пачечный burst/keyframe pacing с жёсткой границей добавленной задержки (`MaxAddedDelay = 15..18 мс`, жёсткий потолок `30 мс`),
    - объединение мелких пакетов в `FrameTypeBatch (0x05)` с сохранением строго монотонного `seq` перед зашифровкой,
    - низкоприоритетная обратная связь простоя (`"fb"` / `"ping"`), всегда уступающая реальным пакетам, и автоматическое снижение до `baseline` через `MaxDuration` (по умолчанию `2h`).
- **Ограничение памяти и backpressure**: очередь шейпера ограничена `128` пакетами и `256 KiB` (`ErrQueueFull` / `QueueFullException` при переполнении с отбросом сверхлимита и сбросом pacing выше `highWatermark`, без неограниченного роста памяти).
- **Энергосбережение и честные ограничения**:
  - Флаг `low-power` / `argonLowPowerMode` (на Android также включается автоматически в режиме Battery Saver или предупреждает на тарифицируемой сети) отключает фоновые `"fb"`-кадры и переводит keepalive в редкий режим `[4s..10s]`.
  - В редких/малонагруженных потоках (`idle` / `browsing` / `video_vod`) `video-call` существенно увеличивает служебный трафик и расход батареи (по замерам в симуляции до `+327%..+564%` байт при малом пользовательском трафике, `+23.9%` при массовой загрузке), а добавленная задержка сглаживания составляет `p50 ~1..4 мс`, `p95 ~4..8 мс`.
  - Поведенческий профиль **не скрывает** IP-адрес сервера и сам факт использования активного транспортного протокола.
- **Настройка**:
  - `argon-tunnel-server`: `-behavior-profile` (`ARGON_BEHAVIOR_PROFILE`), `-allow-video-call` (`ARGON_ALLOW_VIDEO_CALL=1`), `-video-call-max-duration` (`ARGON_VIDEO_CALL_MAX_DURATION=2h`);
  - `argon-tunnel-client`: `-behavior-profile` (`ARGON_BEHAVIOR_PROFILE`), `-low-power` (`ARGON_LOW_POWER=0|1`), `-video-call-max-duration` (`ARGON_VIDEO_CALL_MAX_DURATION=2h`);
  - CEP (`Models.swift`, `cep-cli`, `android/cep-engine`, `CepModels.kt`): `argonBehaviorProfile`, `argonLowPowerMode`, `argonVideoCallMaxDurationSec`;
  - Android Kotlin `TunnelSettings` / UI: `behaviorProfile`, `lowPowerMode`, `videoCallMaxMinutes`.
