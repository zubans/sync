# Сборка Android-клиента на сервере (make release): JDK 17 и Android SDK, без Android Studio.
# Исходники монтируются в /src, кэш Gradle — том, ключ подписи — файл (-PsigningStoreFile).
FROM eclipse-temurin:17-jdk-jammy

ENV ANDROID_HOME=/opt/android-sdk \
    ANDROID_USER_HOME=/tmp/android-user \
    GRADLE_USER_HOME=/gradle \
    HOME=/tmp

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl \
    && rm -rf /var/lib/apt/lists/*

# Версии — как в android/app/build.gradle.kts (compileSdk 35) и по умолчанию у AGP 8.7 (build-tools 34);
# всё, что нужно AGP, ставится здесь: SDK в контейнере доступен только на чтение.
RUN mkdir -p "$ANDROID_HOME/cmdline-tools" \
    && curl -fsSL https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip -o /tmp/tools.zip \
    && unzip -q /tmp/tools.zip -d "$ANDROID_HOME/cmdline-tools" \
    && mv "$ANDROID_HOME/cmdline-tools/cmdline-tools" "$ANDROID_HOME/cmdline-tools/latest" \
    && rm /tmp/tools.zip \
    && yes | "$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager" --licenses > /dev/null \
    && "$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager" "platforms;android-35" "build-tools;34.0.0" "platform-tools" > /dev/null \
    && chmod -R a+rX "$ANDROID_HOME"

WORKDIR /src/android
