# Админка VPN / CEP для узла

Каждый узел разворачивает собственный экземпляр Symfony-админки (`/admin`) и управляет своей конфигурацией через раздел **«Узел VPN / CEP»** (`/admin/vpn-node`).

## Режимы узла («Клиент» / «VPN-сервер»)

Настройки узла хранятся в `NodeSettings` (`/admin/vpn-node`) и определяют доступные разделы панели:

- **`Клиент` (`client`)** — настройка клиентских подключений, многохоповых цепочек, выбор активной цепочки устройства и экспорт `profiles.json` для приложений CEP (macOS / Android). Серверные разделы скрыты из бокового меню и защищены атрибутом `#[RequiresServerMode]` (`ServerModeGuardSubscriber`, HTTP 403).
- **`VPN-сервер` (`server`)** — в дополнение к клиентской части открываются разделы управления серверными службами VPN, правилами проброса портов и пользователями VPN / системы.

> **Включение/выключение блока VPN:** весь блок VPN/CEP и серверные контейнеры (`tunnel`, `ocserv`) также могут быть включены или выключены целиком на странице [Модули и функции ИИ](modules-and-ai.md) (`/admin/modules`, флаг `vpnEnabled`). При выключенном модуле пункты VPN скрываются из меню, прямые URL админки возвращают HTTP `503`, а `FeatureSettingsController` синхронно вызывает `NodeRuntimeExporter::sync(applyNow: true)`: выгружается пустой `profiles.json`, удаляются `.env`/конфиги серверных служб, очищается `port-forward.sh`, в `desired-state.json` записываются `vpnModuleEnabled: false` и `server.enabled: false`, и выполняется `VPN_RUNTIME_APPLY_CMD` для остановки туннелей и правил на хосте.

## Разделы админки

1. **Узел VPN / CEP (`/admin/vpn-node`)** (`VpnNodeController`):
   - переключение роли узла (`Клиент` / `VPN-сервер`), выбор внешнего хоста, WAN-интерфейса (`eth0`), подсети VPN (`10.44.0.0/24`), DNS, блокировки IPv6 и активной клиентской цепочки (`POST /admin/vpn-node/settings`);
   - мастер **«Сделать этот узел VPN-сервером»** (`POST /admin/vpn-node/provision`, `VpnServerProvisioner`): переводит узел в режим `server`, поднимает декларативные конфигурации выбранных служб (по умолчанию `argon4` и др.), генерирует для них клиентские профили подключений и создаёт учётную запись `admin-vpn`;
   - принудительная синхронизация runtime-артефактов (`POST /admin/vpn-node/sync`) и скачивание `profiles.json` (`/admin/vpn-node/export/profiles.json`), `desired-state.json` (`/admin/vpn-node/export/desired-state.json`) и `port-forward.sh` (`/admin/vpn-node/export/port-forward.sh`).

2. **Подключения VPN/CEP (`/admin/vpn-connection`)** (`VpnConnectionCrudController`):
   - поддерживаются все 9 протоколов (`VpnProtocol::ALL`): `argon4`, `transit`, `vless`, `hysteria2`, `hysteria`, `openconnect`, `openvpn`, `amneziawg`, `sstp`;
   - настройки обфускации Argon4 `v4`/`v5` (`useArgon`, `argonMode`, `argonPassword`, `argonVersion`, `argonPadding`, поведенческие профили `off` / `baseline` / `video-call`, `argonPathMtu` — пусто для автоподбора либо `576..9000`);
   - действие **«Подобрать MTU»** на каждой строке/карточке (`POST /admin/vpn-connection/{id}/probe-mtu`) и прямо в форме создания/редактирования (`POST /admin/vpn-connection/probe-mtu-preview`, с кнопкой сброса «Авто»): `MtuProbeService` зондирует реальный сокет с обязательным подтверждением доставки от пира (UDP Don't Fragment с бинарным поиском до 1 байта, TCP `TCP_MAXSEG`, интерфейсный MTU и `cep-cli probe-mtu` с передачей `Target` следующего узла цепочки для аутентифицированного `transit`, без подмены отказов на `1500` или `TCP MSS`), учитывает бюджет предыдущих шагов активной цепочки и сохраняет/подставляет оптимальный внешний MTU (а при экспорте `argonPathMTU` передаётся в `profiles.json` для любого типа узла);
   - для промежуточного транзита (`transit`) поддерживаются как слепой passthrough (без `argonPassword` — промежуточный узел не расшифровывает внутренний трафик Argon4 v5), так и аутентифицированный L4-транзит с `transitTarget` и списком `transitAllow`.

3. **Цепочки узлов (`/admin/vpn-chain`)** (`VpnChainCrudController`):
   - сборка упорядоченной последовательности шагов (`VpnChainHopType`), например `Слепой Transit -> Argon4 v5 video-call` или многохоповых прокси-цепочек;
   - `VpnChainValidator` проверяет порядок шагов (выходным узлом не может быть `transit`), диапазон `argonPathMtu` (`576..9000`) на всех узлах включая слепой `transit` и запрет несовместимых комбинаций (например, `video-call` поверх слепого `transit`, `v4` или TCP underlay);
   - виртуальный столбец **«MTU и бюджет цепочки»** (`MtuProbeService::analyzeChainMtu`) показывает узкое место внешнего MTU и расчётный выходной TUN MTU или режим автоподбора;
   - действие **«Сделать активной»** (`/admin/vpn-chain/{id}/activate`) назначает цепочку активной на узле и включает её в `profiles.json`.

4. **Серверные службы VPN (`/admin/vpn-server-service`)** *(только режим `VPN-сервер`)*:
   - описание сервисов, которые должны работать на текущем сервере (протокол, `listenHost`, `listenPort`, `transportProto` UDP/TCP/оба, `publicHostOverride`, параметры Argon4 / OpenConnect / VLESS / AmneziaWG / Hysteria2 и др., включая `argonPathMtu = 0` для автоподбора оптимального MTU);
   - криптографические пары ключей VLESS Reality и AmneziaWG генерируются и валидируются по X25519 (RFC 7748 clamping, `sodium_crypto_scalarmult_base`): публичные ключи (`pbk=` и `[Peer] PublicKey`) всегда детерминированно соответствуют приватным;
   - профили Hysteria2 экспортируются в формате `hysteria2://<username>:<password>@...` (и `default:<masterPassword>` при отсутствии привязанного пользователя), совпадающем с секцией `auth.userpass` в `hysteria2.yaml`;
   - действие **«Создать клиентский профиль»** сразу генерирует готовое подключение в `/admin/vpn-connection`.

5. **Проброс портов (`/admin/port-forward`)** *(только режим `VPN-сервер`)*:
   - правила DNAT / MASQUERADE: протокол (`tcp`, `udp`, `both`), входной интерфейс (или WAN узла), входной порт или диапазон `listenPort..listenPortEnd`, `targetIp`, `targetPort`, ограничение `sourceCidr` и флаг `enableMasquerade`;
   - `PortForwardValidator` проверяет корректность портов, диапазонов 1:1 и блокирует конфликты с другими правилами и активными серверными службами VPN.

6. **Пользователи VPN / системы (`/admin/vpn-user`)** *(только режим `VPN-сервер`)*:
   - учётные записи пользователей VPN с автогенерацией `clientId`, `clientSecret`, `vlessUuid`, `tunnelPassword`, опциональным статическим VPN IP и ограничением доступных служб;
   - кнопка **«Личный profiles.json»** отдаёт персональный профиль CEP с учётными данными выбранного пользователя;
   - флаг **«Разрешить вход в веб-панель»** (`grantSystemAccess`) через `VpnUserManager` синхронизирует связанную системную учётную запись `User` для входа в админку (с проверкой уникальности `username`, `clientId`, `systemEmail`). При снятии `grantSystemAccess`, выключении `VpnUser`/`User` или удалении `VpnUser` учётная запись блокируется (`enabled = false`, роли очищаются, пароль при отзыве сбрасывается, все `ApiToken` немедленно отзываются, а `UserChecker`/`ApiTokenHandler` и `AuthController::login` отклоняют доступ).

## Экспорт runtime и интеграция с хостом

При сохранении настроек, подключений, цепочек, служб, правил проброса или пользователей `NodeRuntimeExporter` пересобирает декларативный каталог `VPN_RUNTIME_DIR` (по умолчанию `server/var/vpn-runtime`):

- `desired-state.json` — полное целевое состояние узла, служб, правил и пользователей;
- `profiles.json` — конфигурация в формате CEP (`CepExportService`);
- `port-forward.sh` — идемпотентный bash-скрипт правил `iptables` (`SYNC_DNAT`, `SYNC_POST`, `SYNC_FWD`) и `sysctl`;
- `.env`-конфиги для `argon-tunnel-server` (`ARGON_LISTEN_ADDR`, `ARGON_SUBNET`, `ARGON_OUT_INTERFACE`, `ARGON_ENABLE_NAT`, `ARGON_MODE`, `ARGON_MASTER_PASSWORD`, `ARGON_TRANSIT_BLIND`, `ARGON_TRANSIT_TARGET`, `ARGON_TRANSIT_ALLOW`, `ARGON_PROTOCOL_VERSION`, `ARGON_ALLOW_V4`, `ARGON_PADDING_PROFILE`, `ARGON_PATH_MTU`, `ARGON_BEHAVIOR_PROFILE`, `ARGON_ALLOW_VIDEO_CALL`, `ARGON_DNS`, `ARGON_CLIENTS_JSON`) и `ocserv` (`OCSERV_TCP_PORT`, `OCSERV_UDP_PORT`, `OCSERV_CN`, `OCSERV_SERVER_IP`, `OCSERV_SUBNET`, `OCSERV_NETMASK`, `OCSERV_OUT_INTERFACE`, `OCSERV_DNS`, `OCSERV_USERS`).

Панель не выполняет `iptables` или `systemctl` от `root` напрямую из PHP-процесса. Применение изменений на Linux-хосте выполняется внешним скриптом-хуком, заданным в `VPN_RUNTIME_APPLY_CMD` (вызывается при включённом `autoApplyRuntime` или нажатии «Синхронизировать сейчас»).
