import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// The version follows the webmail's (app/bootstrap.php): 1.1.2026.1112.3 → name "1.1.2026.1112.3".
val webmailVersion: String = Regex("M4W_VERSION', '([^']+)'").find(rootProject.file("../app/bootstrap.php").readText())?.groupValues?.get(1) ?: "1.0"
val parts = webmailVersion.split(".").map { it.toIntOrNull() ?: 0 } + listOf(0, 0, 0, 0, 0)
// 1.1.2026.1112.3 → 26111203 (year, month-day, counter): grows with every release.
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
        versionName = webmailVersion
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
