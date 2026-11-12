package com.make4web.mail

import android.content.Context

/** What the app keeps: the webmail's address, the device's token, the last notice shown. */
class Prefs(context: Context) {
    private val p = context.getSharedPreferences("m4w", Context.MODE_PRIVATE)

    var baseUrl: String
        get() = p.getString("base", "") ?: ""
        set(v) = p.edit().putString("base", v.trimEnd('/') + "/").apply()

    var token: String
        get() = p.getString("token", "") ?: ""
        set(v) = p.edit().putString("token", v).apply()

    var ack: Long
        get() = p.getLong("ack", 0)
        set(v) = p.edit().putLong("ack", v).apply()

    var batteryAsked: Boolean
        get() = p.getBoolean("battery_asked", false)
        set(v) = p.edit().putBoolean("battery_asked", v).apply()

    fun clearAccount() = p.edit().remove("token").remove("ack").apply()

    fun clearAll() = p.edit().clear().apply()
}
