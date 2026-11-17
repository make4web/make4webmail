import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// The app has its own version (version.txt, "A" for Android), apart from the webmail's: it only
// changes when the app itself does (./build.sh bump), so a webmail update never asks for an APK.
val appVersion: String = rootProject.file("version.txt").readText().trim()
val parts = appVersion.trimEnd('A', 'a').split(".").map { it.toIntOrNull() ?: 0 } + listOf(0, 0, 0, 0, 0)
// 1.0.2026.1117.5A → 26111705 (year, month-day, counter): grows with every release.
val code = (parts[2] % 100) * 1_000_000 + parts[3] * 100 + parts[4].coerceAtMost(99)

// Signing: keystore/ (a test key kept with the project, so that each new APK installs over the
// previous one). For the Play Store, Google signs with its own key: see README.md.
val keyProps = Properties().apply {
    val f = rootProject.file("keystore/keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}

android {
    namespace = "com.make4web.mail"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.make4web.mail"
        minSdk = 26
        targetSdk = 35
        versionCode = code
        versionName = appVersion
    }

    signingConfigs {
        create("release") {
            storeFile = rootProject.file("keystore/" + (keyProps.getProperty("storeFile") ?: "make4webmail.jks"))
            storePassword = keyProps.getProperty("storePassword")
            keyAlias = keyProps.getProperty("keyAlias")
            keyPassword = keyProps.getProperty("keyPassword")
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("release")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}
