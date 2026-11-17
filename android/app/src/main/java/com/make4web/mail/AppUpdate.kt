package com.make4web.mail

import android.app.Activity
import android.app.AlertDialog
import android.app.Notification
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.widget.Toast
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * The app keeps itself up to date: it compares its version with the one the webmail offers
 * (app/android.json), and when it is behind, offers to install the new one in a tap (download,
 * then Android's own confirmation). The first time, Android asks to let it install updates.
 */
object AppUpdate {
    private const val EVERY = 6 * 3600_000L

    fun installedCode(ctx: Context): Long = try {
        val pi = ctx.packageManager.getPackageInfo(ctx.packageName, 0)
        if (Build.VERSION.SDK_INT >= 28) pi.longVersionCode else @Suppress("DEPRECATION") pi.versionCode.toLong()
    } catch (_: Exception) {
        0
    }

    /** The newer version on offer: [name, apk url], or null (up to date, or not reachable). */
    fun offered(ctx: Context): Pair<String, String>? {
        val base = Prefs(ctx).baseUrl
        if (base.isEmpty()) return null
        return try {
            val c = URL(base + "app/android.json").openConnection() as HttpURLConnection
            c.connectTimeout = 10000
            c.readTimeout = 15000
            if (c.responseCode != 200) return null
            val j = JSONObject(c.inputStream.bufferedReader().use { it.readText() })
            c.disconnect()
            if (j.optLong("code") > installedCode(ctx)) j.optString("version") to j.optString("url") else null
        } catch (_: Exception) {
            null
        }
    }

    /** From the activity: at most every 6 hours (or when asked), the offer as a dialog. */
    fun check(a: Activity, force: Boolean = false) {
        val p = a.getSharedPreferences("m4w", Context.MODE_PRIVATE)
        if (!force && System.currentTimeMillis() - p.getLong("upd_checked", 0) < EVERY) return
        p.edit().putLong("upd_checked", System.currentTimeMillis()).apply()
        thread {
            val o = offered(a) ?: return@thread
            a.runOnUiThread {
                if (a.isFinishing) return@runOnUiThread
                AlertDialog.Builder(a)
                    .setTitle(R.string.upd_title)
                    .setMessage(a.getString(R.string.upd_text, o.first))
                    .setPositiveButton(R.string.upd_go) { _, _ -> install(a, o.second) }
                    .setNegativeButton(R.string.later, null)
                    .show()
            }
        }
    }

    /** From the background service, once a day: a notification that opens the offer. */
    fun checkInBackground(ctx: Context) {
        val p = ctx.getSharedPreferences("m4w", Context.MODE_PRIVATE)
        if (System.currentTimeMillis() - p.getLong("upd_bg", 0) < 24 * 3600_000L) return
        p.edit().putLong("upd_bg", System.currentTimeMillis()).apply()
        val o = offered(ctx) ?: return
        val open = PendingIntent.getActivity(ctx, 9, Intent(ctx, MainActivity::class.java).putExtra("update", true).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        ctx.getSystemService(NotificationManager::class.java).notify("m4w-update", 1, Notification.Builder(ctx, Alerts.CHANNEL_MAIL)
            .setSmallIcon(R.drawable.ic_notify)
            .setContentTitle(ctx.getString(R.string.upd_title))
            .setContentText(ctx.getString(R.string.upd_text, o.first))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setGroup("m4w.update")
            .build())
    }

    /** Downloaded, then handed to Android's installer (its confirmation, over the app). */
    fun install(a: Activity, url: String) {
        if (!a.packageManager.canRequestPackageInstalls()) {
            // Once: Android must let this app install its updates.
            Toast.makeText(a, R.string.upd_allow, Toast.LENGTH_LONG).show()
            a.getSharedPreferences("m4w", Context.MODE_PRIVATE).edit().putLong("upd_checked", 0).apply()
            try {
                a.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + a.packageName)))
            } catch (_: Exception) {
            }
            return
        }
        val wait = AlertDialog.Builder(a).setMessage(R.string.upd_downloading).setCancelable(false).show()
        thread {
            try {
                val c = URL(url).openConnection() as HttpURLConnection
                c.connectTimeout = 15000
                c.readTimeout = 60000
                if (c.responseCode != 200) throw Exception("HTTP " + c.responseCode)
                val pi = a.packageManager.packageInstaller
                val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL)
                params.setAppPackageName(a.packageName)
                val id = pi.createSession(params)
                pi.openSession(id).use { s ->
                    c.inputStream.use { input -> s.openWrite("make4webmail.apk", 0, -1).use { out -> input.copyTo(out); s.fsync(out) } }
                    c.disconnect()
                    val done = PendingIntent.getBroadcast(a, id, Intent(a, InstallReceiver::class.java),
                        PendingIntent.FLAG_UPDATE_CURRENT or (if (Build.VERSION.SDK_INT >= 31) PendingIntent.FLAG_MUTABLE else 0))
                    s.commit(done.intentSender)
                }
                a.runOnUiThread { wait.dismiss() }
            } catch (_: Exception) {
                a.runOnUiThread {
                    wait.dismiss()
                    Toast.makeText(a, R.string.upd_failed, Toast.LENGTH_LONG).show()
                }
            }
        }
    }
}

/** Android's installer answers: its confirmation is shown, or the failure told. */
class InstallReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        when (intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)) {
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                @Suppress("DEPRECATION")
                val confirm = intent.getParcelableExtra<Intent>(Intent.EXTRA_INTENT) ?: return
                context.startActivity(confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            }
            PackageInstaller.STATUS_SUCCESS -> {}
            else -> Toast.makeText(context, R.string.upd_failed, Toast.LENGTH_LONG).show()
        }
    }
}
