package com.make4web.mail

import android.app.NotificationManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/** "Mark read" under a notification: told to the webmail, the notice goes. */
class ActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val nm = context.getSystemService(NotificationManager::class.java)
        nm.cancel(intent.getStringExtra("tag"), 1)
        Alerts.summary(context)
        val prefs = Prefs(context)
        val msg = intent.getLongExtra("msg", 0)
        val mbx = intent.getLongExtra("mbx", 0)
        if (msg <= 0 || prefs.token.isEmpty()) return
        val pending = goAsync()
        thread {
            try {
                val c = URL(prefs.baseUrl + "api/native/read?msg=$msg&mbx=$mbx").openConnection() as HttpURLConnection
                c.requestMethod = "POST"
                c.setRequestProperty("Authorization", "Bearer " + prefs.token)
                c.connectTimeout = 10000
                c.readTimeout = 15000
                c.doOutput = true
                c.outputStream.close()
                c.responseCode
                c.disconnect()
            } catch (_: Exception) {
            } finally {
                pending.finish()
            }
        }
    }
}
