package com.make4web.mail

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.net.Uri
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL

/**
 * A notice from the webmail: shown, and its sound played by the app itself (Android's
 * channels keep one fixed sound; here each person's choice, their own sounds too), unless the
 * phone is on silent or Do not disturb.
 */
object Alerts {
    const val CHANNEL_MAIL = "mail"
    const val CHANNEL_SERVICE = "service"

    fun channels(ctx: Context) {
        val nm = ctx.getSystemService(NotificationManager::class.java)
        val mail = NotificationChannel(CHANNEL_MAIL, ctx.getString(R.string.channel_mail), NotificationManager.IMPORTANCE_HIGH).apply {
            description = ctx.getString(R.string.channel_mail_desc)
            setSound(null, null) // the app plays the chosen sound
            enableVibration(false) // and vibrates as chosen
            setShowBadge(true)
        }
        val service = NotificationChannel(CHANNEL_SERVICE, ctx.getString(R.string.channel_service), NotificationManager.IMPORTANCE_MIN).apply {
            description = ctx.getString(R.string.channel_service_desc)
            setShowBadge(false)
        }
        nm.createNotificationChannel(mail)
        nm.createNotificationChannel(service)
    }

    private fun openIntent(ctx: Context, path: String): PendingIntent {
        val i = Intent(ctx, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("path", path)
        }
        return PendingIntent.getActivity(ctx, path.hashCode(), i, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    fun show(ctx: Context, e: JSONObject) {
        val nm = ctx.getSystemService(NotificationManager::class.java)
        val title = e.optString("title", ctx.getString(R.string.app_name))
        val body = e.optString("body", "")
        val path = e.optString("url", "mail")
        val tag = e.optString("tag", "m4w")
        val b = Notification.Builder(ctx, CHANNEL_MAIL)
            .setSmallIcon(R.drawable.ic_notify)
            .setColor(0xFF2563EB.toInt())
            .setContentTitle(title)
            .setContentText(body.lineSequence().firstOrNull() ?: "")
            .setStyle(Notification.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setCategory(Notification.CATEGORY_EMAIL)
            .setContentIntent(openIntent(ctx, path))
            .setWhen(System.currentTimeMillis())
            .setShowWhen(true)
        if (e.has("count")) b.setNumber(e.optInt("count"))
        nm.notify(tag, 1, b.build())
        if (e.optString("kind") == "new" && e.has("count")) Badge.set(ctx, e.optInt("count"))
        ring(ctx, e)
    }

    /** The sound and the vibration, as the phone allows (silent, vibrate only, Do not disturb). */
    private fun ring(ctx: Context, e: JSONObject) {
        val nm = ctx.getSystemService(NotificationManager::class.java)
        val am = ctx.getSystemService(AudioManager::class.java)
        if (nm.currentInterruptionFilter != NotificationManager.INTERRUPTION_FILTER_ALL) return
        val mode = am.ringerMode
        if (mode == AudioManager.RINGER_MODE_SILENT) return
        val pattern = e.optJSONArray("vibrate")
        if (pattern != null && pattern.length() > 0) {
            val timings = LongArray(pattern.length() + 1) { if (it == 0) 0L else pattern.optLong(it - 1) }
            vibrator(ctx)?.vibrate(VibrationEffect.createWaveform(timings, -1))
        }
        if (mode != AudioManager.RINGER_MODE_NORMAL) return
        val sound = e.optString("sound", "ding")
        if (sound == "none" || e.optBoolean("silent")) return
        val uri = soundUri(ctx, sound, e.optString("sound_url")) ?: return
        try {
            MediaPlayer().apply {
                setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                setDataSource(ctx, uri)
                setOnCompletionListener { it.release() }
                setOnErrorListener { mp, _, _ -> mp.release(); true }
                prepare()
                start()
            }
        } catch (_: Exception) {
        }
    }

    private fun vibrator(ctx: Context): Vibrator? =
        if (Build.VERSION.SDK_INT >= 31) ctx.getSystemService(VibratorManager::class.java)?.defaultVibrator
        else @Suppress("DEPRECATION") ctx.getSystemService(Vibrator::class.java)

    /** A sound of the app, or one of the person's own (fetched once, then kept). */
    private fun soundUri(ctx: Context, name: String, url: String): Uri? {
        val builtIn = mapOf("ding" to R.raw.ding, "chime" to R.raw.chime, "bell" to R.raw.bell, "pop" to R.raw.pop, "marimba" to R.raw.marimba, "beep" to R.raw.beep)
        builtIn[name]?.let { return Uri.parse("android.resource://${ctx.packageName}/$it") }
        if (url.isEmpty()) return Uri.parse("android.resource://${ctx.packageName}/${R.raw.ding}")
        val f = File(ctx.filesDir, "sounds/" + name.replace(Regex("[^a-z0-9]"), "_"))
        if (!f.exists()) {
            val prefs = Prefs(ctx)
            try {
                val c = URL(prefs.baseUrl + url).openConnection() as HttpURLConnection
                c.setRequestProperty("Authorization", "Bearer " + prefs.token)
                c.connectTimeout = 10000
                c.readTimeout = 20000
                if (c.responseCode == 200) {
                    f.parentFile?.mkdirs()
                    c.inputStream.use { input -> f.outputStream().use { input.copyTo(it) } }
                }
                c.disconnect()
            } catch (_: Exception) {
            }
        }
        return if (f.exists()) Uri.fromFile(f) else Uri.parse("android.resource://${ctx.packageName}/${R.raw.ding}")
    }
}

/** The unread count on the app's icon (launchers that show numbers read it from the notices). */
object Badge {
    /** Nothing unread any more (read here or elsewhere): the mail notices go. */
    fun set(ctx: Context, n: Int) {
        if (n > 0) return
        val nm = ctx.getSystemService(NotificationManager::class.java)
        nm.activeNotifications.filter { it.notification.channelId == Alerts.CHANNEL_MAIL }.forEach { nm.cancel(it.tag, it.id) }
    }
}
