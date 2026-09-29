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
        versionCode = 1
        versionName = "1.0"

        // Адрес сервера по умолчанию: ./gradlew assembleDebug -PserverUrl=http://host:port
        // Без параметра — 10.0.2.2 (хост-машина из эмулятора Android) и HTTP_PORT из корневого .env.
        val httpPort = rootProject.file("../.env").takeIf { it.exists() }?.readLines()
            ?.firstNotNullOfOrNull { it.trim().removePrefix("HTTP_PORT=").takeIf { p -> p != it.trim() } }
            ?.takeIf { it.isNotBlank() } ?: "8000"
        val serverUrl = providers.gradleProperty("serverUrl").getOrElse("http://10.0.2.2:$httpPort")
        buildConfigField("String", "DEFAULT_SERVER_URL", "\"$serverUrl\"")
    }

    buildTypes {
        release {
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
    debugImplementation(libs.androidx.ui.tooling)
    testImplementation(libs.junit)
}
