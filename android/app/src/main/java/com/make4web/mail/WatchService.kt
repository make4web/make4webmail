package com.make4web.mail

import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * Keeps one long wait open on the webmail (api/native/wait): what the PWA would get by push
 * arrives here at once, without Google's services. A foreground service ("remote messaging"),
 * as messaging apps without Firebase do.
 */
class WatchService : Service() {
    @Volatile private var running = false
    private var worker: Thread? = null

    companion object {
        /** The app is in front: mail notices are left to the page (its own toast and sound). */
        @Volatile var appVisible = false

        fun start(ctx: Context) {
            if (Prefs(ctx).token.isEmpty()) return
            try {
                ctx.startForegroundService(Intent(ctx, WatchService::class.java))
            } catch (_: Exception) {
            }
        }

        fun stop(ctx: Context) = ctx.stopService(Intent(ctx, WatchService::class.java))
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        Alerts.channels(this)
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val n = Notification.Builder(this, Alerts.CHANNEL_SERVICE)
            .setSmallIcon(R.drawable.ic_notify)
            .setContentText(getString(R.string.service_text))
            .setContentIntent(open)
            .setOngoing(true)
            .build()
        if (Build.VERSION.SDK_INT >= 34) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING)
        else startForeground(1, n)
        if (!running) {
            running = true
            worker = thread(name = "m4w-wait") { loop() }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        running = false
        worker?.interrupt()
        super.onDestroy()
    }

    private fun loop() {
        val prefs = Prefs(this)
        var backoff = 2000L
        val wl = getSystemService(PowerManager::class.java).newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "m4w:notice")
        while (running) {
            val token = prefs.token
            if (token.isEmpty() || prefs.baseUrl.isEmpty()) break
            try {
                val c = URL(prefs.baseUrl + "api/native/wait?ack=" + prefs.ack).openConnection() as HttpURLConnection
                c.setRequestProperty("Authorization", "Bearer $token")
                c.setRequestProperty("Accept", "application/json")
                c.connectTimeout = 15000
                c.readTimeout = 90000
                val code = c.responseCode
                if (code == 401) {
                    // Signed out or disconnected from the webmail: nothing more to wait for.
                    prefs.clearAccount()
                    break
                }
                if (code != 200) throw Exception("HTTP $code")
                val body = c.inputStream.bufferedReader().use { it.readText() }
                c.disconnect()
                val events = JSONObject(body).optJSONArray("events")
                if (events != null && events.length() > 0) {
                    wl.acquire(10000)
                    for (i in 0 until events.length()) {
                        val e = events.getJSONObject(i)
                        val kind = e.optString("kind")
                        // In front: the page announces new mail itself (toast and sound).
                        if (!(appVisible && (kind == "new" || kind == "reminder"))) Alerts.show(this, e)
                        prefs.ack = maxOf(prefs.ack, e.optLong("id"))
                    }
                    if (wl.isHeld) wl.release()
                }
                backoff = 2000L
            } catch (_: InterruptedException) {
                break
            } catch (_: Exception) {
                try {
                    Thread.sleep(backoff)
                } catch (_: InterruptedException) {
                    break
                }
                backoff = minOf(backoff * 2, 60000L)
            }
        }
        running = false
        stopSelf()
    }
}
