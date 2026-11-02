# CEP — цепочки VPN

`CepActivity` (пакет `cep`) — отдельная иконка «CEP» (`taskAffinity` `com.example.contactsync.cep`). Это Android-версия CEP для macOS: цепочка из нескольких VPN-узлов, телефон → узел 1 → узел 2 → … → интернет. Сервер каждого следующего узла доступен только через туннель предыдущего, весь остальной трафик уходит в последний узел. Root не нужен.

Протоколы: VLESS (Reality, Vision, gRPC, WebSocket, HTTPUpgrade), Hysteria 2, Hysteria v1, OpenConnect (AnyConnect/ocserv, GlobalProtect, Pulse, Juniper, Fortinet), OpenVPN.

## Как пользоваться

1. **Перенести настройки с Mac.** «Профили» → «Добавить» → «Загрузить profiles.json с Mac…» — файл `~/Library/Application Support/CEP/profiles.json`. Профили и цепочки с теми же id заменяются, новые добавляются. Обратно — «Сохранить profiles.json…».
2. Или завести с нуля: «Профиль из ссылки…» (одна или несколько ссылок `vless://`, `hy2://`, `hysteria2://`, `hysteria://`), «Новый профиль…» для OpenConnect и OpenVPN (`.ovpn` вставляется или загружается из файла). Затем «Цепочки» → «Новая цепочка» → «Добавить узел» в нужном порядке.
3. «Подключить» — система один раз спросит разрешение на VPN. Состояние, узлы и журнал — на вкладке «Подключение», «Отключить» — там же или в уведомлении.

Поля профиля те же, что на Mac: «Сети через этот узел» (`192.168.1.119, 10.0.0.0/8`) пускают эти адреса через свой узел, а не дальше по цепочке; «DNS цепочки» ставится как DNS устройства на время подключения, запросы к нему идут через цепочку.

## Устройство

- **Движок** — `android/cep-engine` (Go, пакет `cepcore`): вся цепочка — один экземпляр sing-box 1.14 внутри `CepVpnService`. Узел N выходит в сеть через узел N-1 (`detour`), трафик VPN уходит в последний узел (`route.final`), «Сети через этот узел» — правило `ip_cidr` на свой узел. OpenConnect и OpenVPN — userspace-endpoint'ы sing-box, без системных интерфейсов. Сокеты движка выводятся из VPN через `VpnService.protect`. Решение — ADR-017 в KB.
- **Адреса серверов** движок разрешает системным DNS до подъёма VPN и подключается по IP; SNI и проверка сертификата — по имени.
- **OpenConnect за прокси-узлом.** OpenConnect ставит таймауты на соединение, а соединения VLESS поверх gRPC их не поддерживают. Между ними стоит обёртка `cep-deadline` (`deadline.go`): она отдаёт соединение как есть и эмулирует таймаут. DTLS (UDP) за прокси-узлом выключен, туннель идёт по TLS.
- **IPv6** при «Блокировать IPv6» Android блокирует сам: у VPN нет адресов и маршрутов IPv6.
- **Сети устройства** движок получает от `CepVpnService` (`ConnectivityManager`): Go на Android не может прочитать их через netlink. Смену сети (Wi-Fi ↔ мобильная) служба передаёт в движок.

## Хранение

Профили и цепочки — `files/cep-profiles.json` в закрытом каталоге приложения, формат как у `profiles.json` на macOS (пароли внутри, как и на Mac). В службу VPN передаётся только id цепочки: пароли через Intent не ходят. Журнал — только в памяти, пароли и ключи в него не пишутся.

## Ограничения

- AmneziaWG и SSTP не поддерживаются: в sing-box их нет. Профили сохраняются и переносятся, но цепочку с ними не запустить.
- VLESS с транспортом `xhttp` и `tcp` с `headerType=http` не поддерживаются (это умеет только xray).
- OpenConnect: Array Networks не поддерживается.
- OpenVPN: сертификаты и ключи — только внутри `.ovpn` блоками `<ca>`, `<cert>`, `<key>`, `<tls-auth>`, `<tls-crypt>`; режим static key и блоки `<connection>` не поддерживаются.
- Split DNS по доменам от сервера (как на macOS) нет: все запросы идут на DNS цепочки.
- ocserv не пускает второй сеанс того же пользователя, пока первый жив (например, та же цепочка поднята на Mac): CSTP отвечает 401, в журнале — «tunnel session was rejected; retrying».
- Движок собран только под arm64.

## Сборка

Движок собирает `make cep-engine` (`android/cep-engine/build.sh`, gomobile) в `android/app/libs/cepcore.aar` (не в git). Нужны Go, NDK и gomobile; `make apk` собирает движок сам, если его нет или менялись исходники. На сервере `make release` собирает движок в `docker/android-builder` (там есть Go, NDK и gomobile). Тесты движка: `cd android/cep-engine && go test -tags with_gvisor,with_quic,with_utls,with_openconnect,with_openvpn .`; живая проверка цепочки с SOCKS вместо VPN — `CEP_LIVE_PLAN=plan.json go test -run Live …` (`live_test.go`).

## Код

- `CepLogic.kt` — чистая логика без Android API: формат `profiles.json`, перенос с Mac, ссылки, задание движку. Тесты — `CepLogicTest`.
- `CepViewModel.kt` — профили, цепочки, импорт и экспорт, подключение.
- `CepVpnService.kt` — VPN: реализует `cepcore.Platform` (protect, VPN по параметрам движка, сети устройства, журнал), уведомление с «Отключить».
- `CepEngine.kt` — состояние подключения и журнал.
- `CepScreen.kt`, `CepActivity.kt` — экраны и запрос разрешения на VPN.
- `android/cep-engine` — движок: `plan.go` (задание → конфиг sing-box), `link.go` (ссылки → outbound'ы), `ovpn.go` (`.ovpn` → endpoint OpenVPN), `deadline.go`, `platform.go` (`PlatformInterface` sing-box поверх `VpnService`), `service.go` (запуск и журнал).
