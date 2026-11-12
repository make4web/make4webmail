package com.make4web.mail

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.PowerManager
import android.provider.Settings
import android.text.InputType
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast

/**
 * The webmail itself, in the app: the same pages as in the browser (all that is built for the
 * web is here at once), with the phone's notifications and sounds (WatchService).
 */
class MainActivity : Activity() {
    private lateinit var prefs: Prefs
    private var web: WebView? = null
    private var fileCallback: ValueCallback<Array<Uri>>? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = Prefs(this)
        Alerts.channels(this)
        intent?.data?.let { fromLink(it) }
        if (prefs.baseUrl.isEmpty()) setupScreen() else openWeb(intent?.getStringExtra("path"))
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        val before = prefs.baseUrl
        intent.data?.let { fromLink(it) }
        val path = intent.getStringExtra("path")
        if (prefs.baseUrl != before || web == null) openWeb(path) else if (path != null) web?.loadUrl(prefs.baseUrl + path)
    }

    /** m4wapp://open?url=https://… (the QR code of the webmail's settings). */
    private fun fromLink(u: Uri) {
        if (u.scheme == "m4wapp") {
            val url = u.getQueryParameter("url") ?: return
            if (url.startsWith("https://") && url != prefs.baseUrl) {
                if (prefs.baseUrl.isNotEmpty()) prefs.clearAccount()
                prefs.baseUrl = url
            }
        }
    }

    override fun onResume() {
        super.onResume()
        WatchService.appVisible = true
        web?.onResume()
        WatchService.start(this)
    }

    override fun onPause() {
        WatchService.appVisible = false
        web?.onPause()
        CookieManager.getInstance().flush()
        super.onPause()
    }

    // ------------------------------------------------------------ first launch

    private fun dp(v: Int) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v.toFloat(), resources.displayMetrics).toInt()

    private fun setupScreen() {
        web = null
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(28), dp(28), dp(28), dp(28))
            setBackgroundColor(Color.WHITE)
        }
        box.addView(TextView(this).apply { text = getString(R.string.setup_title); textSize = 26f; setTextColor(Color.parseColor("#0f172a")) })
        box.addView(TextView(this).apply { text = getString(R.string.setup_text); textSize = 15f; setTextColor(Color.parseColor("#475569")); setPadding(0, dp(12), 0, dp(16)) })
        val input = EditText(this).apply {
            hint = getString(R.string.setup_hint)
            inputType = InputType.TYPE_TEXT_VARIATION_URI
            setSingleLine()
            setText(prefs.baseUrl.ifEmpty { "https://" })
        }
        box.addView(input)
        box.addView(Button(this).apply {
            text = getString(R.string.setup_go)
            setOnClickListener {
                var url = input.text.toString().trim()
                if (!url.contains("://")) url = "https://$url"
                if (!url.startsWith("https://") || Uri.parse(url).host.isNullOrEmpty()) {
                    Toast.makeText(this@MainActivity, R.string.setup_bad, Toast.LENGTH_LONG).show()
                } else {
                    prefs.baseUrl = url
                    openWeb(null)
                }
            }
        }, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(16) })
        setContentView(box)
    }

    // ------------------------------------------------------------ the webmail

    @SuppressLint("SetJavaScriptEnabled")
    private fun openWeb(path: String?) {
        val w = WebView(this)
        web = w
        w.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            mediaPlaybackRequiresUserGesture = false // the page's own sounds
            setSupportZoom(false)
            allowFileAccess = false
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            userAgentString = userAgentString + " M4WApp/" + BuildConfigLite.version(this@MainActivity)
        }
        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(w, false)
        w.addJavascriptInterface(Bridge(), "M4WApp")
        w.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, req: WebResourceRequest): Boolean {
                val u = req.url
                val base = Uri.parse(prefs.baseUrl)
                if ((u.scheme == "https" || u.scheme == "http") && u.host == base.host) return false
                // Elsewhere (a link in a message, mailto:, tel:): the phone's own apps.
                try {
                    startActivity(Intent(Intent.ACTION_VIEW, u))
                } catch (_: ActivityNotFoundException) {
                }
                return true
            }
        }
        w.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = callback
                return try {
                    startActivityForResult(params.createIntent().apply {
                        if (params.mode == FileChooserParams.MODE_OPEN_MULTIPLE) putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true)
                    }, 1)
                    true
                } catch (_: ActivityNotFoundException) {
                    fileCallback = null
                    false
                }
            }
        }
        w.setDownloadListener { url, ua, disposition, mime, _ ->
            try {
                val r = DownloadManager.Request(Uri.parse(url))
                    .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url) ?: "")
                    .addRequestHeader("User-Agent", ua)
                    .setMimeType(mime)
                    .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, URLUtil.guessFileName(url, disposition, mime))
                getSystemService(DownloadManager::class.java).enqueue(r)
            } catch (_: Exception) {
            }
        }
        setContentView(w)
        w.loadUrl(prefs.baseUrl + (path ?: ""))
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == 1) {
            val uris: Array<Uri>? = if (resultCode != RESULT_OK || data == null) null else {
                val clip = data.clipData
                if (clip != null) Array(clip.itemCount) { clip.getItemAt(it).uri } else data.data?.let { arrayOf(it) }
            }
            fileCallback?.onReceiveValue(uris)
            fileCallback = null
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        val w = web
        if (w != null && w.canGoBack()) w.goBack() else @Suppress("DEPRECATION") super.onBackPressed()
    }

    // ------------------------------------------------------------ what the page may ask

    /** window.M4WApp in the page (push.js, notify.js). */
    inner class Bridge {
        @JavascriptInterface fun token(): String = prefs.token
        @JavascriptInterface fun deviceName(): String = Build.MANUFACTURER.replaceFirstChar { it.uppercase() } + " " + Build.MODEL
        @JavascriptInterface fun deviceModel(): String = Build.MODEL + " (Android " + Build.VERSION.RELEASE + ")"
        @JavascriptInterface fun version(): String = BuildConfigLite.version(this@MainActivity)

        /** Signed in: the device's token, and listening starts (permissions asked once). */
        @JavascriptInterface fun register(token: String, base: String) {
            prefs.token = token
            prefs.ack = 0
            runOnUiThread {
                askPermissions()
                WatchService.start(this@MainActivity)
            }
        }

        /** Signed out on purpose: nothing more is waited for. */
        @JavascriptInterface fun clear() {
            prefs.clearAccount()
            runOnUiThread { WatchService.stop(this@MainActivity) }
        }

        /** The unread count changed (read here or elsewhere). */
        @JavascriptInterface fun unread(n: Int) = Badge.set(this@MainActivity, n)

        /** Another webmail (the account menu of the page). */
        @JavascriptInterface fun changeServer() = runOnUiThread {
            prefs.clearAll()
            WatchService.stop(this@MainActivity)
            CookieManager.getInstance().removeAllCookies(null)
            setupScreen()
        }
    }

    private fun askPermissions() {
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), 2)
        }
        val pm = getSystemService(PowerManager::class.java)
        if (!pm.isIgnoringBatteryOptimizations(packageName) && !prefs.batteryAsked) {
            prefs.batteryAsked = true
            AlertDialog.Builder(this)
                .setTitle(R.string.battery_title)
                .setMessage(R.string.battery_text)
                .setPositiveButton(R.string.battery_ok) { _, _ ->
                    try {
                        @SuppressLint("BatteryLife")
                        val i = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName"))
                        startActivity(i)
                    } catch (_: Exception) {
                    }
                }
                .setNegativeButton(R.string.later, null)
                .show()
        }
    }
}

/** The app's version (from the package, no generated BuildConfig needed). */
object BuildConfigLite {
    fun version(a: android.content.Context): String = try {
        a.packageManager.getPackageInfo(a.packageName, 0).versionName ?: ""
    } catch (_: Exception) {
        ""
    }
}
