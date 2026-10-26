# Сборка Android-клиента на сервере (make release): JDK 17, Android SDK с NDK и Go для движка CEP, без Android Studio.
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
    && "$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager" "platforms;android-35" "build-tools;34.0.0" "platform-tools" "ndk;27.2.12479018" > /dev/null \
    && chmod -R a+rX "$ANDROID_HOME"

# Движок CEP (android/cep-engine): Go и gomobile; NDK — выше. Версия x/mobile — как в cep-engine/go.mod.
# Модули и кэш сборки Go лежат в томе Gradle (/gradle/go), чтобы не скачиваться при каждой сборке.
ENV GO_VERSION=1.27.1 \
    ANDROID_NDK_HOME=/opt/android-sdk/ndk/27.2.12479018 \
    GOPATH=/gradle/go \
    GOCACHE=/gradle/go-build \
    PATH=/usr/local/go/bin:$PATH
RUN curl -fsSL "https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz" | tar -xz -C /usr/local \
    && GOPATH=/tmp/go GOCACHE=/tmp/go-build GOBIN=/usr/local/bin \
       go install golang.org/x/mobile/cmd/gomobile@v0.0.0-20261011034528-197770444867 \
                  golang.org/x/mobile/cmd/gobind@v0.0.0-20261011034528-197770444867 \
    && rm -rf /tmp/go /tmp/go-build

WORKDIR /src/android
