plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

android {
    namespace = "com.example.contactsync"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.example.contactsync"
        minSdk = 26
        targetSdk = 35
        // Номер сборки растёт с каждым изменением клиента: число коммитов, затронувших android/.
        // По нему приложение понимает, что на сервере версия новее. make release передаёт его явно (-PversionCode).
        val gitVersionCode = providers.exec {
            workingDir(rootDir)
            commandLine("git", "rev-list", "--count", "HEAD", "--", ".")
            isIgnoreExitValue = true
        }.standardOutput.asText.map { it.trim().toIntOrNull() ?: 1 }
        versionCode = providers.gradleProperty("versionCode").map(String::toInt).orElse(gitVersionCode).get()
        versionName = "1.$versionCode"

        // Адрес сервера по умолчанию — VPS; другой: ./gradlew assembleDebug -PserverUrl=http://host:port
        val serverUrl = providers.gradleProperty("serverUrl").getOrElse("http://94.103.9.172:8001")
        buildConfigField("String", "DEFAULT_SERVER_URL", "\"$serverUrl\"")

        // VS Code на третьем экране главного экрана (code-server): -PcodeServerUrl=http://host:port
        val codeServerUrl = providers.gradleProperty("codeServerUrl").getOrElse("http://94.103.9.172:8083")
        buildConfigField("String", "CODE_SERVER_URL", "\"$codeServerUrl\"")
    }

    signingConfigs {
        // Сборка на сервере (make release в Docker): тот же debug-ключ, что на машине разработчика, но из
        // указанного файла — иначе Android не поставит обновление поверх установленной версии.
        providers.gradleProperty("signingStoreFile").orNull?.let { store ->
            create("shared") {
                storeFile = file(store)
                storePassword = "android"
                keyAlias = "androiddebugkey"
                keyPassword = "android"
            }
        }
    }

    buildTypes {
        release {
            // Приложение ставится не из магазина, своего ключа нет: release подписан debug-ключом этой машины,
            // поэтому ставится поверх debug-сборки без потери данных. Release нужен ради скорости:
            // debuggable-сборка Compose на планшете не успевает в 60 кадров (см. docs/launcher.md).
            signingConfig = signingConfigs.findByName("shared") ?: signingConfigs.getByName("debug")
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.ui)
    implementation(libs.androidx.ui.tooling.preview)
    implementation(libs.androidx.material3)
    implementation(libs.okhttp)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.androidx.biometric)
    implementation(libs.bouncycastle)
    implementation(libs.androidx.fragment.ktx)
    implementation(libs.androidx.glance.appwidget)
    debugImplementation(libs.androidx.ui.tooling)
    testImplementation(libs.junit)
}
