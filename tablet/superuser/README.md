# phh Superuser для планшета

Менеджер root для TrebleDroid: без него `su` на планшете отказывает всем приложениям (из `adb shell` работает). Нужен, чтобы наше приложение запускало `/data/local/boot.sh` после перезагрузки (см. `docs/launcher.md`, «Автозапуск»).

Исходники — [phhusson/Superuser](https://github.com/phhusson/Superuser) `9ae9705` и подмодуль [phhusson/Widgets](https://github.com/phhusson/Widgets) `a64044a`, лицензия Apache 2.0 (`LICENSE`). Готового APK у проекта нет, Google Play на планшете нет.

Отличия от исходников:

- сборка переведена на Gradle 8.10 / AGP 8.7 (было Gradle 2.5 / AGP 1.2), `applicationId me.phh.superuser` задан в `app/build.gradle` вместо файла `packageName`;
- `targetSdk 28`: Android 14 не ставит приложения с targetSdk ниже 23;
- убран `junit.framework.Assert` из рабочего кода, удалены нативные исходники `su` (бинарник уже в прошивке, `/system/bin/phh-su`) и скрипты прошивочного zip из `assets`.

Сборка и установка:

```bash
./gradlew assembleRelease
adb install app/build/outputs/apk/release/app-release.apk
```

Подписан отладочным ключом. Обновлять — тем же ключом с этого же компьютера, иначе придётся удалить менеджер вместе с выданными разрешениями.
