package com.make4web.mail

import android.app.AlarmManager
import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.Network
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.os.SystemClock
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * Keeps a wait open on the webmail (api/native/wait, 25 s, again and again): what the PWA gets by
 * push arrives here at once, without Google's services. A foreground service ("remote
 * messaging") with what messaging apps without Firebase do to last on phones that put apps to
 * sleep hard (Realme, Oppo, Xiaomi…): the CPU kept awake while listening, a new connection as
 * soon as the network changes, and an alarm every 10 minutes that starts listening again if the
 * system stopped it or nothing came back for 2 minutes.
 */
class WatchService : Service() {
    @Volatile private var running = false
    @Volatile private var current: HttpURLConnection? = null
    private var worker: Thread? = null
    private var wakeLock: PowerManager.WakeLock? = null
    private var netCallback: ConnectivityManager.NetworkCallback? = null

    companion object {
        /** The app is in front: mail notices are left to the page (its own toast and sound). */
        @Volatile var appVisible = false
        /** When the server last answered (the watchdog's measure). */
        @Volatile var lastAnswer = 0L

        fun start(ctx: Context, restart: Boolean = false) {
            if (Prefs(ctx).token.isEmpty()) return
            try {
                ctx.startForegroundService(Intent(ctx, WatchService::class.java).putExtra("restart", restart))
            } catch (_: Exception) {
            }
            Watchdog.arm(ctx)
        }

        fun stop(ctx: Context) {
            ctx.stopService(Intent(ctx, WatchService::class.java))
            Watchdog.cancel(ctx)
        }
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
        if (wakeLock == null) {
            wakeLock = getSystemService(PowerManager::class.java).newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "m4w:listen").apply {
                setReferenceCounted(false)
                acquire()
            }
        }
        if (netCallback == null) {
            // Wi-Fi ↔ mobile data: the open connection is dead, a new one at once.
            netCallback = object : ConnectivityManager.NetworkCallback() {
                private var last: Network? = null
                override fun onAvailable(network: Network) {
                    if (last != null && last != network) current?.disconnect()
                    last = network
                }
            }
            try {
                getSystemService(ConnectivityManager::class.java).registerDefaultNetworkCallback(netCallback!!)
            } catch (_: Exception) {
            }
        }
        val stale = SystemClock.elapsedRealtime() - lastAnswer > 120_000
        if (running && (intent?.getBooleanExtra("restart", false) == true) && stale) {
            // Alive but deaf (a connection the network dropped without a word): started again.
            current?.disconnect()
            worker?.interrupt()
            running = false
        }
        if (!running) {
            running = true
            lastAnswer = SystemClock.elapsedRealtime()
            worker = thread(name = "m4w-wait") { loop() }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        running = false
        current?.disconnect()
        worker?.interrupt()
        netCallback?.let { try { getSystemService(ConnectivityManager::class.java).unregisterNetworkCallback(it) } catch (_: Exception) {} }
        if (wakeLock?.isHeld == true) wakeLock?.release()
        super.onDestroy()
    }

    private fun loop() {
        val prefs = Prefs(this)
        var backoff = 2000L
        val me = Thread.currentThread()
        while (running && worker == me) {
            val token = prefs.token
            if (token.isEmpty() || prefs.baseUrl.isEmpty()) break
            try {
                val c = URL(prefs.baseUrl + "api/native/wait?ack=" + prefs.ack).openConnection() as HttpURLConnection
                current = c
                c.setRequestProperty("Authorization", "Bearer $token")
                c.setRequestProperty("Accept", "application/json")
                c.connectTimeout = 15000
                c.readTimeout = 45000
                val code = c.responseCode
                if (code == 401) {
                    // Signed out or disconnected from the webmail: nothing more to wait for.
                    prefs.clearAccount()
                    break
                }
                if (code != 200) throw Exception("HTTP $code")
                val body = c.inputStream.bufferedReader().use { it.readText() }
                c.disconnect()
                lastAnswer = SystemClock.elapsedRealtime()
                val events = JSONObject(body).optJSONArray("events")
                if (events != null) {
                    for (i in 0 until events.length()) {
                        val e = events.getJSONObject(i)
                        val kind = e.optString("kind")
                        // In front: the page announces new mail itself (toast and sound).
                        if (!(appVisible && (kind == "new" || kind == "reminder"))) Alerts.show(this, e)
                        prefs.ack = maxOf(prefs.ack, e.optLong("id"))
                    }
                }
                backoff = 2000L
            } catch (_: InterruptedException) {
                break
            } catch (_: Exception) {
                if (!running || worker != me) break
                try {
                    Thread.sleep(backoff)
                } catch (_: InterruptedException) {
                    break
                }
                backoff = minOf(backoff * 2, 30000L)
            }
        }
        if (worker == me) {
            running = false
            if (prefs.token.isEmpty()) stopSelf()
        }
    }
}

/** Every 10 minutes, even in deep sleep: listening started again if the system stopped it. */
class Watchdog : BroadcastReceiver() {
    companion object {
        private fun intent(ctx: Context) = PendingIntent.getBroadcast(ctx, 7, Intent(ctx, Watchdog::class.java), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)

        fun arm(ctx: Context) {
            ctx.getSystemService(AlarmManager::class.java).setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, SystemClock.elapsedRealtime() + 10 * 60_000L, intent(ctx))
        }

        fun cancel(ctx: Context) = ctx.getSystemService(AlarmManager::class.java).cancel(intent(ctx))
    }

    override fun onReceive(context: Context, intent: Intent) {
        WatchService.start(context, restart = true)
    }
}
