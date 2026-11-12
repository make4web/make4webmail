package com.make4web.mail

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** The phone restarted, or the app was updated: listening again. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        WatchService.start(context)
    }
}
