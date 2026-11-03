plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

android {
    namespace = "app.cep"
    compileSdk = 35

    defaultConfig {
        applicationId = "app.cep"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0"
        // движок собран только под arm64 и x86_64 (эмулятор), см. build-engine.sh
        ndk { abiFilters += listOf("arm64-v8a", "x86_64") }
    }

    buildTypes {
        release {
            // своего ключа нет: release подписан debug-ключом этой машины и ставится поверх debug-сборки
            signingConfig = signingConfigs.getByName("debug")
            isMinifyEnabled = false
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
    }
    packaging {
        jniLibs { useLegacyPackaging = true }
    }
}

dependencies {
    // libs/cepcore.aar собирает ./build-engine.sh (gomobile bind движка из engine/)
    implementation(fileTree("libs") { include("*.aar") })
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.ui)
    implementation(libs.androidx.material3)
    implementation(libs.androidx.material.icons)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)
}
