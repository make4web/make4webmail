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

    private const val GROUP_MAIL = "m4w.mail"
    const val GROUP_SERVICE = "m4w.service"
    private const val SUMMARY_TAG = "m4w-summary"

    private fun openIntent(ctx: Context, path: String): PendingIntent {
        val i = Intent(ctx, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("path", path)
        }
        return PendingIntent.getActivity(ctx, path.hashCode(), i, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    /** The sender's initials in their colour (the same as in the webmail). */
    private fun initials(name: String, seed: String): android.graphics.Bitmap {
        val palette = intArrayOf(0xFF1D4ED8.toInt(), 0xFF6D28D9.toInt(), 0xFFBE185D.toInt(), 0xFFB91C1C.toInt(), 0xFFC2410C.toInt(),
            0xFFA16207.toInt(), 0xFF15803D.toInt(), 0xFF0F766E.toInt(), 0xFF0E7490.toInt(), 0xFF4338CA.toInt())
        var h = 0
        for (ch in seed.lowercase()) h = (h shl 5) - h + ch.code
        val words = name.replace(Regex("[<\"].*$"), "").trim().split(Regex("[\\s._@-]+")).filter { it.isNotEmpty() }
        val text = ((words.getOrNull(0)?.take(1) ?: "?") + (words.getOrNull(1)?.take(1) ?: "")).uppercase()
        val size = 128
        val bmp = android.graphics.Bitmap.createBitmap(size, size, android.graphics.Bitmap.Config.ARGB_8888)
        val c = android.graphics.Canvas(bmp)
        val p = android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG)
        p.color = palette[Math.abs(h) % palette.size]
        c.drawCircle(size / 2f, size / 2f, size / 2f, p)
        p.color = android.graphics.Color.WHITE
        p.textSize = 52f
        p.typeface = android.graphics.Typeface.DEFAULT_BOLD
        p.textAlign = android.graphics.Paint.Align.CENTER
        c.drawText(text, size / 2f, size / 2f - (p.descent() + p.ascent()) / 2, p)
        return bmp
    }

    fun show(ctx: Context, e: JSONObject) {
        val nm = ctx.getSystemService(NotificationManager::class.java)
        val path = e.optString("url", "mail")
        val tag = e.optString("tag", "m4w")
        val from = e.optString("from")
        val subject = e.optString("subject")
        val b = Notification.Builder(ctx, CHANNEL_MAIL)
            .setSmallIcon(R.drawable.ic_notify)
            .setColor(0xFF2563EB.toInt())
            .setAutoCancel(true)
            .setContentIntent(openIntent(ctx, path))
            .setWhen(System.currentTimeMillis())
            .setShowWhen(true)
            .setVisibility(Notification.VISIBILITY_PRIVATE)
        if (e.optString("kind") == "new" && from.isNotEmpty()) {
            // Like a mail app: who, about what, which mailbox (and its folder when filed).
            val folder = e.optString("folder")
            val email = e.optString("from_email")
            b.setContentTitle(from)
                .setContentText(subject)
                .setSubText(e.optString("account") + if (folder.isNotEmpty()) " · $folder" else "")
                .setStyle(Notification.BigTextStyle().bigText(subject + if (email.isNotEmpty() && !email.equals(from, true)) "\n$email" else ""))
                .setLargeIcon(initials(from, email.ifEmpty { from }))
                .setCategory(Notification.CATEGORY_EMAIL)
                .setGroup(GROUP_MAIL)
                .setSortKey("a" + (Long.MAX_VALUE - System.currentTimeMillis()))
            val msg = e.optLong("msg")
            if (msg > 0) {
                val read = Intent(ctx, ActionReceiver::class.java).apply {
                    action = "read"
                    putExtra("msg", msg)
                    putExtra("mbx", e.optLong("mbx"))
                    putExtra("tag", tag)
                }
                b.addAction(Notification.Action.Builder(null, ctx.getString(R.string.mark_read),
                    PendingIntent.getBroadcast(ctx, tag.hashCode(), read, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)).build())
            }
        } else {
            val body = e.optString("body", "")
            b.setContentTitle(e.optString("title", ctx.getString(R.string.app_name)))
                .setContentText(body.lineSequence().firstOrNull() ?: "")
                .setStyle(Notification.BigTextStyle().bigText(body))
                .setCategory(if (e.optString("kind") == "new") Notification.CATEGORY_EMAIL else Notification.CATEGORY_REMINDER)
                .setGroup(GROUP_MAIL)
        }
        if (e.has("count")) b.setNumber(e.optInt("count"))
        nm.notify(tag, 1, b.build())
        summary(ctx)
        if (e.optString("kind") == "new" && e.has("count")) Badge.set(ctx, e.optInt("count"))
        ring(ctx, e)
    }

    /** Several notices: one heading above them ("3 new messages", who and what). */
    fun summary(ctx: Context) {
        val nm = ctx.getSystemService(NotificationManager::class.java)
        val mine = nm.activeNotifications.filter { it.notification.channelId == CHANNEL_MAIL && it.tag != SUMMARY_TAG }
        if (mine.size < 2) {
            nm.cancel(SUMMARY_TAG, 1)
            return
        }
        val style = Notification.InboxStyle()
        mine.sortedByDescending { it.postTime }.take(6).forEach {
            val x = it.notification.extras
            style.addLine("${x.getCharSequence(Notification.EXTRA_TITLE) ?: ""} — ${x.getCharSequence(Notification.EXTRA_TEXT) ?: ""}")
        }
        val title = ctx.resources.getQuantityString(R.plurals.new_messages, mine.size, mine.size)
        style.setBigContentTitle(title)
        nm.notify(SUMMARY_TAG, 1, Notification.Builder(ctx, CHANNEL_MAIL)
            .setSmallIcon(R.drawable.ic_notify)
            .setColor(0xFF2563EB.toInt())
            .setContentTitle(title)
            .setStyle(style)
            .setGroup(GROUP_MAIL)
            .setGroupSummary(true)
            .setGroupAlertBehavior(Notification.GROUP_ALERT_CHILDREN)
            .setAutoCancel(true)
            .setContentIntent(openIntent(ctx, "mail"))
            .build())
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
        Alerts.summary(ctx)
    }
}
