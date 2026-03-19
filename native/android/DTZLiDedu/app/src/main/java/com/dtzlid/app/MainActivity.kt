package com.dtzlid.app

import android.annotation.SuppressLint
import android.app.Activity
import android.Manifest
import android.app.DownloadManager
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.Uri
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import android.view.WindowManager
import android.webkit.GeolocationPermissions
import android.webkit.PermissionRequest
import android.webkit.SafeBrowsingResponse
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.SslErrorHandler
import android.webkit.WebSettings
import android.webkit.ValueCallback
import android.webkit.WebView
import android.webkit.WebViewClient
import android.webkit.URLUtil
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.compose.BackHandler
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.tasks.await
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.net.CookieManager
import java.net.CookiePolicy
import java.io.File
import java.io.IOException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val isDebuggable = (applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0
        if (isDebuggable) {
            WebView.setWebContentsDebuggingEnabled(true)
        }
        stashPendingDeepLinkIntent(intent)
        setContent { AppRoot() }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        stashPendingDeepLinkIntent(intent)
    }

    private fun stashPendingDeepLinkIntent(intent: Intent?) {
        val fromExtra = intent?.getStringExtra(NotificationCenter.INTENT_EXTRA_DEEP_LINK_URL).orEmpty()
        val fromData = intent?.dataString.orEmpty()
        val chosen = listOf(fromExtra, fromData).firstOrNull { isAllowedWebUrl(it) } ?: return
        val prefs = getSharedPreferences(WEB_PREFS, Context.MODE_PRIVATE)
        prefs.edit().putString(WEB_PENDING_DEEP_LINK, chosen).apply()
    }
}

private const val WEB_BASE_URL = "https://dtz-lid.com"
private const val WEB_BASE_URL_WWW = "https://www.dtz-lid.com"
private const val WEB_PREFS = "dtz_webview"
private const val WEB_LAST_URL = "last_url"
private const val WEB_PENDING_DEEP_LINK = "pending_deep_link"
private const val WEB_FAVORITES = "favorites"
private const val WEB_DESKTOP_MODE = "desktop_mode"
private const val WEB_TEXT_ZOOM = "text_zoom"
private const val WEB_KEEP_SCREEN_ON = "keep_screen_on"
private const val WEB_DATA_SAVER = "data_saver"
private const val WEB_TEXT_AUTOSIZE = "text_autosize"
private const val WEB_MEDIA_AUTOPLAY = "media_autoplay"
private const val WEB_RECENT_PAGES = "recent_pages"
private const val WEB_SCROLL_POSITIONS = "scroll_positions"
private val WEB_ALLOWED_HOSTS = setOf("dtz-lid.com", "www.dtz-lid.com")
private const val WEBVIEW_TAG_UA = "DTZLiDWebView/1.0"
private const val WEB_RECENT_PAGES_LIMIT = 50

private data class WebRecentPage(
    val title: String,
    val url: String,
    val visitedAt: Long
)

private fun loadRecentPages(prefs: android.content.SharedPreferences): List<WebRecentPage> {
    val raw = prefs.getString(WEB_RECENT_PAGES, "[]").orEmpty()
    val arr = runCatching { JSONArray(raw) }.getOrElse { JSONArray() }
    val out = mutableListOf<WebRecentPage>()
    for (i in 0 until arr.length()) {
        val row = arr.optJSONObject(i) ?: continue
        val normalized = normalizeAllowedWebUrl(row.optString("url", "")) ?: continue
        val title = row.optString("title", normalized).ifBlank { normalized }
        val visitedAt = row.optLong("visitedAt", System.currentTimeMillis())
        out += WebRecentPage(title = title, url = normalized, visitedAt = visitedAt)
    }
    return out.sortedByDescending { it.visitedAt }.take(WEB_RECENT_PAGES_LIMIT)
}

private fun persistRecentPages(
    prefs: android.content.SharedPreferences,
    pages: List<WebRecentPage>
) {
    val arr = JSONArray()
    pages.take(WEB_RECENT_PAGES_LIMIT).forEach { row ->
        arr.put(
            JSONObject()
                .put("title", row.title)
                .put("url", row.url)
                .put("visitedAt", row.visitedAt)
        )
    }
    prefs.edit().putString(WEB_RECENT_PAGES, arr.toString()).apply()
}

private fun loadScrollPositions(
    prefs: android.content.SharedPreferences
): Map<String, Int> {
    val raw = prefs.getString(WEB_SCROLL_POSITIONS, "{}").orEmpty()
    val obj = runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
    val out = mutableMapOf<String, Int>()
    val keys = obj.keys()
    while (keys.hasNext()) {
        val key = keys.next()
        val normalized = normalizeAllowedWebUrl(key) ?: continue
        val y = obj.optInt(key, 0).coerceAtLeast(0)
        out[normalized] = y
    }
    return out
}

private fun persistScrollPositions(
    prefs: android.content.SharedPreferences,
    positions: Map<String, Int>
) {
    val obj = JSONObject()
    positions.forEach { (url, y) ->
        obj.put(url, y.coerceAtLeast(0))
    }
    prefs.edit().putString(WEB_SCROLL_POSITIONS, obj.toString()).apply()
}

private fun isAllowedWebUrl(url: String): Boolean {
    return normalizeAllowedWebUrl(url) != null
}

private fun normalizeAllowedWebUrl(url: String): String? {
    val trimmed = url.trim()
    if (trimmed.isBlank()) return null
    val uri = runCatching { Uri.parse(trimmed) }.getOrNull() ?: return null
    val host = uri.host?.lowercase(Locale.US) ?: return null
    val scheme = uri.scheme?.lowercase(Locale.US) ?: return null
    if (scheme != "https" && scheme != "http") return null
    if (!WEB_ALLOWED_HOSTS.contains(host)) return null
    return uri.buildUpon().scheme("https").build().toString()
}

private fun hasActiveInternet(context: Context): Boolean {
    val connectivity = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
    val network = connectivity.activeNetwork ?: return false
    val capabilities = connectivity.getNetworkCapabilities(network) ?: return false
    return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
}

@Composable
fun AppRoot() {
    WebAppScreen()
}

@Composable
fun NativeAppScreen() {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var session by remember { mutableStateOf(StudentSession()) }
    var loading by remember { mutableStateOf(true) }
    val notificationsLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission()
    ) { }

    LaunchedEffect(Unit) {
        PushNotificationWorker.ensureScheduled(context)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            val granted = ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED
            if (!granted) {
                notificationsLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        }

        scope.launch {
            try {
                val admin = Api.adminSession()
                session = if (admin.authenticated == true) admin else Api.studentSession()
                if (session.authenticated == true) {
                    Api.syncFcmTokenWithCurrentSession(context)
                }
            } finally {
                loading = false
            }
        }
    }

    if (loading) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }

    if (session.authenticated == true) {
        MainTabs(session = session, onLogout = {
            scope.launch {
                if (session.isTeacherRole()) {
                    Api.adminLogout()
                } else {
                    Api.studentLogout()
                }
                session = StudentSession()
            }
        })
    } else {
        LoginScreen(
            onStudentLogin = { user, pass ->
                scope.launch {
                    val next = Api.studentLogin(user, pass)
                    session = next
                    if (next.authenticated == true) {
                        Api.syncFcmTokenWithCurrentSession(context)
                    }
                }
            },
            onTeacherLogin = { user, pass ->
                scope.launch {
                    val next = Api.adminLogin(user, pass)
                    session = next
                    if (next.authenticated == true) {
                        Api.syncFcmTokenWithCurrentSession(context)
                    }
                }
            }
        )
    }
}

@SuppressLint("SetJavaScriptEnabled")
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun WebAppScreen() {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val webPrefs = remember { context.getSharedPreferences(WEB_PREFS, Context.MODE_PRIVATE) }
    val startUrl = remember {
        val pending = webPrefs.getString(WEB_PENDING_DEEP_LINK, "") ?: ""
        val normalizedPending = normalizeAllowedWebUrl(pending)
        if (normalizedPending != null) {
            webPrefs.edit().remove(WEB_PENDING_DEEP_LINK).apply()
            normalizedPending
        } else {
            val saved = webPrefs.getString(WEB_LAST_URL, WEB_BASE_URL) ?: WEB_BASE_URL
            normalizeAllowedWebUrl(saved) ?: WEB_BASE_URL
        }
    }
    var webViewRef by remember { mutableStateOf<WebView?>(null) }
    var canGoBack by remember { mutableStateOf(false) }
    var canGoForward by remember { mutableStateOf(false) }
    var showNavChrome by remember { mutableStateOf(true) }
    var lastScrollY by remember { mutableStateOf(0) }
    var lastScrollPersistAt by remember { mutableStateOf(0L) }
    var pageScrollPercent by remember { mutableStateOf(0) }
    var estimatedReadMinutes by remember { mutableStateOf(0) }
    var loading by remember { mutableStateOf(true) }
    var loadProgress by remember { mutableStateOf(0) }
    var offline by remember { mutableStateOf(false) }
    var loadTimedOut by remember { mutableStateOf(false) }
    var webViewGeneration by remember { mutableStateOf(0) }
    var showHistory by remember { mutableStateOf(false) }
    var historyItems by remember { mutableStateOf(NotificationCenter.list(context)) }
    var historyFilter by remember { mutableStateOf("all") }
    var showFavorites by remember { mutableStateOf(false) }
    var showRecentPages by remember { mutableStateOf(false) }
    var showSettings by remember { mutableStateOf(false) }
    var showJumpDialog by remember { mutableStateOf(false) }
    var showScrollTop by remember { mutableStateOf(false) }
    var showFindBar by remember { mutableStateOf(false) }
    var findQuery by remember { mutableStateOf("") }
    var findMatchCount by remember { mutableStateOf(0) }
    var findActiveMatch by remember { mutableStateOf(0) }
    var jumpInput by remember { mutableStateOf("") }
    var topMenuExpanded by remember { mutableStateOf(false) }
    var currentPageTitle by remember { mutableStateOf("DTZ-LID edu") }
    var defaultUserAgent by remember { mutableStateOf("") }
    var fileChooserCallback by remember { mutableStateOf<ValueCallback<Array<Uri>>?>(null) }
    var pendingWebPermissionRequest by remember { mutableStateOf<PermissionRequest?>(null) }
    var pendingGeoOrigin by remember { mutableStateOf<String?>(null) }
    var pendingGeoCallback by remember { mutableStateOf<GeolocationPermissions.Callback?>(null) }
    var desktopMode by remember { mutableStateOf(webPrefs.getBoolean(WEB_DESKTOP_MODE, false)) }
    var textZoom by remember { mutableStateOf(webPrefs.getInt(WEB_TEXT_ZOOM, 100).coerceIn(70, 180)) }
    var keepScreenOn by remember { mutableStateOf(webPrefs.getBoolean(WEB_KEEP_SCREEN_ON, false)) }
    var dataSaver by remember { mutableStateOf(webPrefs.getBoolean(WEB_DATA_SAVER, false)) }
    var textAutosize by remember { mutableStateOf(webPrefs.getBoolean(WEB_TEXT_AUTOSIZE, true)) }
    var mediaAutoplay by remember { mutableStateOf(webPrefs.getBoolean(WEB_MEDIA_AUTOPLAY, true)) }
    val pageScrollMap = remember { loadScrollPositions(webPrefs).toMutableMap() }
    val recentPages = remember { loadRecentPages(webPrefs).toMutableStateList() }
    val favorites = remember {
        val raw = webPrefs.getString(WEB_FAVORITES, "") ?: ""
        raw.split("\n")
            .mapNotNull { normalizeAllowedWebUrl(it) }
            .distinct()
            .toMutableStateList()
    }
    val notificationsLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission()
    ) { }
    val filePickerLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val data = result.data
        val uris: Array<Uri>? = when {
            result.resultCode != Activity.RESULT_OK -> null
            data == null -> null
            data.clipData != null -> {
                Array(data.clipData!!.itemCount) { i -> data.clipData!!.getItemAt(i).uri }
            }
            data.data != null -> arrayOf(data.data!!)
            else -> null
        }
        fileChooserCallback?.onReceiveValue(uris)
        fileChooserCallback = null
    }
    val webPermissionsLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestMultiplePermissions()
    ) { grants ->
        val request = pendingWebPermissionRequest
        pendingWebPermissionRequest = null
        if (request == null) return@rememberLauncherForActivityResult
        val allowedResources = request.resources.filter { resource ->
            when (resource) {
                PermissionRequest.RESOURCE_AUDIO_CAPTURE ->
                    grants[Manifest.permission.RECORD_AUDIO] == true
                PermissionRequest.RESOURCE_VIDEO_CAPTURE ->
                    grants[Manifest.permission.CAMERA] == true
                else -> false
            }
        }.toTypedArray()
        if (allowedResources.isNotEmpty()) {
            request.grant(allowedResources)
        } else {
            request.deny()
        }
    }
    val geoPermissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestMultiplePermissions()
    ) { grants ->
        val callback = pendingGeoCallback
        val origin = pendingGeoOrigin
        pendingGeoCallback = null
        pendingGeoOrigin = null
        val granted = grants[Manifest.permission.ACCESS_FINE_LOCATION] == true ||
            grants[Manifest.permission.ACCESS_COARSE_LOCATION] == true
        callback?.invoke(origin.orEmpty(), granted, false)
    }

    fun openSafeUrl(url: String) {
        webViewRef?.loadUrl(normalizeAllowedWebUrl(url) ?: WEB_BASE_URL)
    }

    fun buildUserAgent(baseUserAgent: String): String {
        val base = baseUserAgent.replace(" $WEBVIEW_TAG_UA", "").trim()
        if (desktopMode) {
            return "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 " +
                "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 $WEBVIEW_TAG_UA"
        }
        return "$base $WEBVIEW_TAG_UA".trim()
    }

    fun applyRuntimeWebPreferences(view: WebView) {
        val settings = view.settings
        if (defaultUserAgent.isBlank()) {
            defaultUserAgent = settings.userAgentString
        }
        settings.textZoom = textZoom
        settings.userAgentString = buildUserAgent(defaultUserAgent)
        settings.mediaPlaybackRequiresUserGesture = !mediaAutoplay
        settings.loadsImagesAutomatically = !dataSaver
        settings.blockNetworkImage = dataSaver
        settings.layoutAlgorithm = if (textAutosize) {
            WebSettings.LayoutAlgorithm.TEXT_AUTOSIZING
        } else {
            WebSettings.LayoutAlgorithm.NORMAL
        }
    }

    fun persistFavorites() {
        val raw = favorites.joinToString("\n")
        webPrefs.edit().putString(WEB_FAVORITES, raw).apply()
    }

    fun addRecentPage(url: String, title: String) {
        val normalizedUrl = normalizeAllowedWebUrl(url) ?: return
        val cleanedTitle = title.trim().ifBlank { normalizedUrl }
        recentPages.removeAll { it.url == normalizedUrl }
        recentPages.add(0, WebRecentPage(cleanedTitle, normalizedUrl, System.currentTimeMillis()))
        while (recentPages.size > WEB_RECENT_PAGES_LIMIT) {
            recentPages.removeLast()
        }
        persistRecentPages(webPrefs, recentPages)
    }

    fun rememberCurrentScroll(view: WebView?) {
        val safeView = view ?: return
        val normalized = normalizeAllowedWebUrl(safeView.url.orEmpty()) ?: return
        pageScrollMap[normalized] = safeView.scrollY.coerceAtLeast(0)
    }

    fun resolveJumpInput(raw: String): String? {
        val text = raw.trim()
        if (text.isBlank()) return null
        if (text.startsWith("#")) return "$WEB_BASE_URL/$text"
        if (text.startsWith("/")) return "$WEB_BASE_URL$text"
        val normalized = normalizeAllowedWebUrl(text)
        if (normalized != null) return normalized
        val withBase = if (text.startsWith("http://") || text.startsWith("https://")) text else "$WEB_BASE_URL/$text"
        return normalizeAllowedWebUrl(withBase)
    }

    fun refreshReadingStats(view: WebView?) {
        val safeView = view ?: return
        safeView.evaluateJavascript(
            "(function(){var t=document.body?document.body.innerText:'';var w=t.trim().split(/\\s+/).filter(Boolean).length;return String(w);})();"
        ) { jsResult ->
            val raw = jsResult.orEmpty().trim().trim('"')
            val words = raw.filter { it.isDigit() }.toIntOrNull() ?: 0
            estimatedReadMinutes = if (words <= 0) 0 else maxOf(1, Math.ceil(words / 180.0).toInt())
        }
    }

    LaunchedEffect(Unit) {
        offline = !hasActiveInternet(context)
        PushNotificationWorker.ensureScheduled(context)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            val granted = ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED
            if (!granted) {
                notificationsLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
        scope.launch {
            Api.syncFcmTokenWithCurrentSession(context)
        }
    }

    DisposableEffect(keepScreenOn) {
        val activity = context as? Activity
        if (keepScreenOn) {
            activity?.window?.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        } else {
            activity?.window?.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        }
        onDispose { }
    }

    DisposableEffect(Unit) {
        val connectivity = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                if (offline) {
                    offline = false
                    webViewRef?.post { webViewRef?.reload() }
                }
            }

            override fun onLost(network: Network) {
                offline = !hasActiveInternet(context)
            }
        }
        runCatching { connectivity.registerDefaultNetworkCallback(callback) }
        onDispose {
            runCatching { connectivity.unregisterNetworkCallback(callback) }
        }
    }

    LaunchedEffect(loading, webViewGeneration) {
        if (loading) {
            loadTimedOut = false
            kotlinx.coroutines.delay(20000)
            if (loading) {
                loadTimedOut = true
            }
        } else {
            loadTimedOut = false
        }
    }

    BackHandler(enabled = canGoBack) {
        webViewRef?.goBack()
    }

    LaunchedEffect(findQuery) {
        val q = findQuery.trim()
        if (showFindBar && q.isNotBlank()) {
            webViewRef?.findAllAsync(q)
        } else {
            webViewRef?.clearMatches()
            findMatchCount = 0
            findActiveMatch = 0
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(currentPageTitle) },
                actions = {
                    IconButton(onClick = {
                        historyItems = NotificationCenter.list(context)
                        showHistory = true
                    }) {
                        Icon(Icons.Default.Notifications, contentDescription = "Bildirim Geçmişi")
                    }
                    IconButton(onClick = {
                        showFindBar = !showFindBar
                        if (!showFindBar) {
                            findQuery = ""
                            findMatchCount = 0
                            findActiveMatch = 0
                            webViewRef?.clearMatches()
                        }
                    }) {
                        Icon(Icons.Default.Search, contentDescription = "Sayfada Ara")
                    }
                    IconButton(onClick = { topMenuExpanded = true }) {
                        Icon(Icons.Default.MoreVert, contentDescription = "Menü")
                    }
                    DropdownMenu(
                        expanded = topMenuExpanded,
                        onDismissRequest = { topMenuExpanded = false }
                    ) {
                        DropdownMenuItem(
                            text = { Text("Ana Sayfa") },
                            onClick = {
                                topMenuExpanded = false
                                openSafeUrl(WEB_BASE_URL)
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Cache Temizle") },
                            onClick = {
                                topMenuExpanded = false
                                webViewRef?.clearCache(true)
                                Toast.makeText(context, "Cache temizlendi", Toast.LENGTH_SHORT).show()
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Bildirimleri Temizle") },
                            onClick = {
                                topMenuExpanded = false
                                NotificationCenter.clear(context)
                                historyItems = emptyList()
                                Toast.makeText(context, "Bildirim geçmişi temizlendi", Toast.LENGTH_SHORT).show()
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Bu Sayfayı Favorilere Ekle") },
                            onClick = {
                                topMenuExpanded = false
                                val current = webViewRef?.url.orEmpty()
                                if (isAllowedWebUrl(current) && !favorites.contains(current)) {
                                    favorites.add(current)
                                    persistFavorites()
                                    Toast.makeText(context, "Favorilere eklendi", Toast.LENGTH_SHORT).show()
                                }
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Favoriler") },
                            onClick = {
                                topMenuExpanded = false
                                showFavorites = true
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Son Sayfalar") },
                            onClick = {
                                topMenuExpanded = false
                                showRecentPages = true
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Linki Kopyala") },
                            onClick = {
                                topMenuExpanded = false
                                val current = webViewRef?.url.orEmpty()
                                val normalized = normalizeAllowedWebUrl(current)
                                if (normalized != null) {
                                    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                                    clipboard.setPrimaryClip(ClipData.newPlainText("DTZ-LID", normalized))
                                    Toast.makeText(context, "Link kopyalandı", Toast.LENGTH_SHORT).show()
                                }
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Paylaş") },
                            onClick = {
                                topMenuExpanded = false
                                val current = webViewRef?.url.orEmpty()
                                val normalized = normalizeAllowedWebUrl(current)
                                if (normalized != null) {
                                    runCatching {
                                        val shareIntent = Intent(Intent.ACTION_SEND).apply {
                                            type = "text/plain"
                                            putExtra(Intent.EXTRA_SUBJECT, currentPageTitle)
                                            putExtra(Intent.EXTRA_TEXT, normalized)
                                        }
                                        context.startActivity(Intent.createChooser(shareIntent, "Bağlantıyı paylaş"))
                                    }
                                }
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Tarayıcıda Aç") },
                            onClick = {
                                topMenuExpanded = false
                                val current = webViewRef?.url.orEmpty()
                                if (isAllowedWebUrl(current)) {
                                    runCatching {
                                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(current)))
                                    }.onFailure {
                                        Toast.makeText(context, "Tarayıcı açılamadı", Toast.LENGTH_SHORT).show()
                                    }
                                }
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("İndirilenler") },
                            onClick = {
                                topMenuExpanded = false
                                runCatching {
                                    context.startActivity(DownloadManager.ACTION_VIEW_DOWNLOADS.let { Intent(it) })
                                }.onFailure {
                                    Toast.makeText(context, "İndirilenler açılamadı", Toast.LENGTH_SHORT).show()
                                }
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("WebView Ayarları") },
                            onClick = {
                                topMenuExpanded = false
                                showSettings = true
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Hızlı Git") },
                            onClick = {
                                topMenuExpanded = false
                                jumpInput = webViewRef?.url.orEmpty()
                                showJumpDialog = true
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Okuma Analizini Yenile") },
                            onClick = {
                                topMenuExpanded = false
                                refreshReadingStats(webViewRef)
                                Toast.makeText(context, "Okuma analizi güncellendi", Toast.LENGTH_SHORT).show()
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Güvenli Oturumu Sıfırla") },
                            onClick = {
                                topMenuExpanded = false
                                webPrefs.edit()
                                    .putString(WEB_LAST_URL, WEB_BASE_URL)
                                    .remove(WEB_PENDING_DEEP_LINK)
                                    .apply()
                                android.webkit.CookieManager.getInstance().removeAllCookies(null)
                                android.webkit.CookieManager.getInstance().flush()
                                android.webkit.WebStorage.getInstance().deleteAllData()
                                webViewRef?.clearHistory()
                                webViewRef?.clearCache(true)
                                openSafeUrl(WEB_BASE_URL)
                                Toast.makeText(context, "Oturum temizlendi", Toast.LENGTH_SHORT).show()
                            }
                        )
                    }
                }
            )
        }
    ) { padding ->
        Box(
            Modifier
                .fillMaxSize()
                .padding(padding)
        ) {
            AnimatedVisibility(
                visible = showNavChrome,
                modifier = Modifier
                    .fillMaxWidth()
                    .align(Alignment.TopStart)
            ) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(start = 8.dp, end = 8.dp, top = 2.dp)
                        .horizontalScroll(rememberScrollState()),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    AssistChip(onClick = { openSafeUrl(WEB_BASE_URL) }, label = { Text("Start") })
                    AssistChip(onClick = { openSafeUrl("$WEB_BASE_URL/#dtz") }, label = { Text("DTZ") })
                    AssistChip(onClick = { openSafeUrl("$WEB_BASE_URL/#schreiben") }, label = { Text("Schreiben") })
                    AssistChip(onClick = { openSafeUrl("$WEB_BASE_URL/#portal") }, label = { Text("Portal") })
                    AssistChip(onClick = { openSafeUrl("$WEB_BASE_URL/admin.html") }, label = { Text("Dozent") })
                }
            }
            if (showFindBar) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .align(Alignment.TopStart)
                        .padding(start = 8.dp, end = 8.dp, top = if (showNavChrome) 42.dp else 2.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    OutlinedTextField(
                        value = findQuery,
                        onValueChange = { findQuery = it },
                        modifier = Modifier.weight(1f),
                        placeholder = { Text("Sayfada ara...") },
                        singleLine = true
                    )
                    OutlinedButton(
                        onClick = { webViewRef?.findNext(false) },
                        enabled = findMatchCount > 0
                    ) { Text("Yukarı") }
                    OutlinedButton(
                        onClick = { webViewRef?.findNext(true) },
                        enabled = findMatchCount > 0
                    ) { Text("Aşağı") }
                    Text(
                        text = if (findMatchCount > 0) "${findActiveMatch + 1}/$findMatchCount" else "0/0",
                        style = MaterialTheme.typography.labelSmall
                    )
                    TextButton(onClick = {
                        showFindBar = false
                        findQuery = ""
                        findMatchCount = 0
                        findActiveMatch = 0
                        webViewRef?.clearMatches()
                    }) { Text("Kapat") }
                }
            }
            key(webViewGeneration) {
                AndroidView(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(
                            top = when {
                                showFindBar && showNavChrome -> 94.dp
                                showFindBar && !showNavChrome -> 52.dp
                                !showFindBar && showNavChrome -> 42.dp
                                else -> 0.dp
                            },
                            bottom = if (showNavChrome) 58.dp else 0.dp
                        ),
                    factory = {
                        WebView(context).apply {
                        android.webkit.CookieManager.getInstance().setAcceptCookie(true)
                        android.webkit.CookieManager.getInstance().setAcceptThirdPartyCookies(this, true)
                        settings.javaScriptEnabled = true
                        settings.domStorageEnabled = true
                        settings.databaseEnabled = true
                        settings.cacheMode = WebSettings.LOAD_DEFAULT
                        settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
                        settings.setSupportMultipleWindows(false)
                        settings.javaScriptCanOpenWindowsAutomatically = true
                        settings.mediaPlaybackRequiresUserGesture = false
                        settings.loadsImagesAutomatically = true
                        settings.allowFileAccess = true
                        settings.allowContentAccess = true
                        settings.setSupportZoom(true)
                        settings.builtInZoomControls = true
                        settings.displayZoomControls = false
                        settings.offscreenPreRaster = true
                        settings.setGeolocationEnabled(true)
                        defaultUserAgent = settings.userAgentString
                        applyRuntimeWebPreferences(this)
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            settings.safeBrowsingEnabled = true
                        }
                        setFindListener { activeMatchOrdinal, numberOfMatches, _ ->
                            findMatchCount = numberOfMatches.coerceAtLeast(0)
                            findActiveMatch = activeMatchOrdinal.coerceAtLeast(0)
                        }
                        setOnScrollChangeListener { _, _, scrollY, _, _ ->
                            val delta = scrollY - lastScrollY
                            if (delta > 18 && scrollY > 220) {
                                showNavChrome = false
                            } else if (delta < -18) {
                                showNavChrome = true
                            }
                            lastScrollY = scrollY
                            showScrollTop = scrollY > 600
                            val normalized = normalizeAllowedWebUrl(url.orEmpty())
                            if (normalized != null) {
                                pageScrollMap[normalized] = scrollY.coerceAtLeast(0)
                                val now = System.currentTimeMillis()
                                if (now - lastScrollPersistAt > 1500) {
                                    persistScrollPositions(webPrefs, pageScrollMap)
                                    lastScrollPersistAt = now
                                }
                            }
                            val viewport = (height / resources.displayMetrics.density).toInt().coerceAtLeast(1)
                            val maxScroll = (contentHeight - viewport).coerceAtLeast(1)
                            pageScrollPercent = ((scrollY * 100f) / maxScroll).toInt().coerceIn(0, 100)
                        }
                        setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
                            runCatching {
                                val req = DownloadManager.Request(Uri.parse(url)).apply {
                                    setMimeType(mimeType)
                                    addRequestHeader("User-Agent", userAgent)
                                    setDescription("İndiriliyor...")
                                    setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                                    setDestinationInExternalPublicDir(
                                        android.os.Environment.DIRECTORY_DOWNLOADS,
                                        URLUtil.guessFileName(url, contentDisposition, mimeType)
                                    )
                                }
                                val dm = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
                                dm.enqueue(req)
                                Toast.makeText(context, "İndirme başlatıldı", Toast.LENGTH_SHORT).show()
                            }.onFailure {
                                Toast.makeText(context, "İndirme başlatılamadı", Toast.LENGTH_SHORT).show()
                            }
                        }
                        webChromeClient = object : WebChromeClient() {
                            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                                loadProgress = newProgress.coerceIn(0, 100)
                                loading = newProgress < 100
                            }

                            override fun onReceivedTitle(view: WebView?, title: String?) {
                                val safeTitle = title?.trim().orEmpty()
                                currentPageTitle = if (safeTitle.isNotBlank()) safeTitle else "DTZ-LID edu"
                            }

                            override fun onShowFileChooser(
                                webView: WebView?,
                                filePathCallback: ValueCallback<Array<Uri>>?,
                                fileChooserParams: FileChooserParams?
                            ): Boolean {
                                fileChooserCallback?.onReceiveValue(null)
                                fileChooserCallback = filePathCallback
                                val intent = fileChooserParams?.createIntent() ?: Intent(Intent.ACTION_GET_CONTENT).apply {
                                    addCategory(Intent.CATEGORY_OPENABLE)
                                    type = "*/*"
                                }
                                runCatching {
                                    filePickerLauncher.launch(intent)
                                }.onFailure {
                                    fileChooserCallback?.onReceiveValue(null)
                                    fileChooserCallback = null
                                }
                                return true
                            }

                            override fun onPermissionRequest(request: PermissionRequest?) {
                                val safeRequest = request ?: return
                                val origin = safeRequest.origin?.toString().orEmpty()
                                if (!isAllowedWebUrl(origin)) {
                                    safeRequest.deny()
                                    return
                                }
                                val neededPermissions = mutableSetOf<String>()
                                val requestedResources = safeRequest.resources.toSet()
                                if (requestedResources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)) {
                                    neededPermissions.add(Manifest.permission.RECORD_AUDIO)
                                }
                                if (requestedResources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)) {
                                    neededPermissions.add(Manifest.permission.CAMERA)
                                }
                                if (neededPermissions.isEmpty()) {
                                    safeRequest.deny()
                                    return
                                }
                                val allGranted = neededPermissions.all { perm ->
                                    ContextCompat.checkSelfPermission(context, perm) == PackageManager.PERMISSION_GRANTED
                                }
                                if (allGranted) {
                                    safeRequest.grant(
                                        safeRequest.resources.filter {
                                            it == PermissionRequest.RESOURCE_AUDIO_CAPTURE ||
                                                it == PermissionRequest.RESOURCE_VIDEO_CAPTURE
                                        }.toTypedArray()
                                    )
                                } else {
                                    pendingWebPermissionRequest?.deny()
                                    pendingWebPermissionRequest = safeRequest
                                    webPermissionsLauncher.launch(neededPermissions.toTypedArray())
                                }
                            }

                            override fun onPermissionRequestCanceled(request: PermissionRequest?) {
                                if (pendingWebPermissionRequest == request) {
                                    pendingWebPermissionRequest = null
                                }
                            }

                            override fun onGeolocationPermissionsShowPrompt(
                                origin: String?,
                                callback: GeolocationPermissions.Callback?
                            ) {
                                val safeCallback = callback ?: return
                                val safeOrigin = origin.orEmpty()
                                if (!isAllowedWebUrl(safeOrigin)) {
                                    safeCallback.invoke(safeOrigin, false, false)
                                    return
                                }
                                val fineGranted = ContextCompat.checkSelfPermission(
                                    context,
                                    Manifest.permission.ACCESS_FINE_LOCATION
                                ) == PackageManager.PERMISSION_GRANTED
                                val coarseGranted = ContextCompat.checkSelfPermission(
                                    context,
                                    Manifest.permission.ACCESS_COARSE_LOCATION
                                ) == PackageManager.PERMISSION_GRANTED
                                if (fineGranted || coarseGranted) {
                                    safeCallback.invoke(safeOrigin, true, false)
                                } else {
                                    pendingGeoCallback = safeCallback
                                    pendingGeoOrigin = safeOrigin
                                    geoPermissionLauncher.launch(
                                        arrayOf(
                                            Manifest.permission.ACCESS_FINE_LOCATION,
                                            Manifest.permission.ACCESS_COARSE_LOCATION
                                        )
                                    )
                                }
                            }

                            override fun onGeolocationPermissionsHidePrompt() {
                                pendingGeoCallback = null
                                pendingGeoOrigin = null
                            }
                        }
                        webViewClient = object : WebViewClient() {
                            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                                val target = request?.url?.toString().orEmpty()
                                if (target.isBlank()) {
                                    return false
                                }
                                val lower = target.lowercase(Locale.getDefault())
                                if (lower.startsWith("intent://")) {
                                    runCatching {
                                        val intent = Intent.parseUri(target, Intent.URI_INTENT_SCHEME)
                                        val fallback = intent.getStringExtra("browser_fallback_url").orEmpty()
                                        val resolved = intent.resolveActivity(context.packageManager)
                                        when {
                                            resolved != null -> context.startActivity(intent)
                                            fallback.isNotBlank() -> {
                                                val normalizedFallback = normalizeAllowedWebUrl(fallback)
                                                if (normalizedFallback != null) {
                                                    view?.post { view.loadUrl(normalizedFallback) }
                                                } else {
                                                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(fallback)))
                                                }
                                            }
                                            else -> Toast.makeText(context, "Uygulama bulunamadı", Toast.LENGTH_SHORT).show()
                                        }
                                    }.onFailure {
                                        Toast.makeText(context, "Bağlantı açılamadı", Toast.LENGTH_SHORT).show()
                                    }
                                    return true
                                }
                                val isHttp = lower.startsWith("http://") || lower.startsWith("https://")
                                if (isHttp) {
                                    val normalized = normalizeAllowedWebUrl(target)
                                    if (normalized != null) {
                                        if (normalized != target) {
                                            view?.post { view.loadUrl(normalized) }
                                            return true
                                        }
                                        return false
                                    }
                                }
                                runCatching {
                                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(target)))
                                }.onFailure {
                                    Toast.makeText(context, "Bağlantı açılamadı", Toast.LENGTH_SHORT).show()
                                }
                                return true
                            }

                            override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
                                loading = true
                                loadProgress = 0
                                loadTimedOut = false
                                showNavChrome = true
                                showScrollTop = false
                                pageScrollPercent = 0
                                estimatedReadMinutes = 0
                                lastScrollY = 0
                                findMatchCount = 0
                                findActiveMatch = 0
                            }

                            override fun onReceivedError(
                                view: WebView?,
                                request: WebResourceRequest?,
                                error: android.webkit.WebResourceError?
                            ) {
                                if (request?.isForMainFrame == true) {
                                    offline = true
                                    loading = false
                                    loadTimedOut = false
                                    settings.cacheMode = WebSettings.LOAD_CACHE_ELSE_NETWORK
                                }
                            }

                            override fun onReceivedHttpError(
                                view: WebView?,
                                request: WebResourceRequest?,
                                errorResponse: android.webkit.WebResourceResponse?
                            ) {
                                if (request?.isForMainFrame == true) {
                                    val status = errorResponse?.statusCode ?: 0
                                    if (status == 401 || status == 403) {
                                        webPrefs.edit().putString(WEB_LAST_URL, WEB_BASE_URL).apply()
                                        view?.post { view.loadUrl(WEB_BASE_URL) }
                                    }
                                }
                            }

                            override fun onSafeBrowsingHit(
                                view: WebView?,
                                request: WebResourceRequest?,
                                threatType: Int,
                                callback: SafeBrowsingResponse?
                            ) {
                                callback?.backToSafety(true)
                                Toast.makeText(context, "Tehlikeli içerik engellendi", Toast.LENGTH_LONG).show()
                            }

                            override fun onReceivedSslError(
                                view: WebView?,
                                handler: SslErrorHandler?,
                                error: SslError?
                            ) {
                                handler?.cancel()
                                loading = false
                                Toast.makeText(context, "SSL hatası nedeniyle bağlantı engellendi", Toast.LENGTH_LONG).show()
                            }

                            override fun onPageFinished(view: WebView?, url: String?) {
                                canGoBack = view?.canGoBack() == true
                                canGoForward = view?.canGoForward() == true
                                loading = false
                                loadProgress = 100
                                offline = false
                                loadTimedOut = false
                                settings.cacheMode = WebSettings.LOAD_DEFAULT
                                val currentUrl = url ?: view?.url ?: ""
                                val normalized = normalizeAllowedWebUrl(currentUrl)
                                if (normalized != null) {
                                    webPrefs.edit().putString(WEB_LAST_URL, normalized).apply()
                                    addRecentPage(normalized, currentPageTitle)
                                    val restoreY = pageScrollMap[normalized] ?: 0
                                    if (restoreY > 0) {
                                        view?.postDelayed({ view.scrollTo(0, restoreY) }, 120)
                                    }
                                }
                                rememberCurrentScroll(view)
                                persistScrollPositions(webPrefs, pageScrollMap)
                                refreshReadingStats(view)
                                scope.launch {
                                    Api.syncFcmTokenWithCurrentSession(context)
                                }
                            }

                            override fun onRenderProcessGone(view: WebView?, detail: RenderProcessGoneDetail?): Boolean {
                                view?.destroy()
                                webViewRef = null
                                loading = true
                                loadTimedOut = false
                                webViewGeneration += 1
                                return true
                            }
                        }
                        loadUrl(startUrl)
                        }
                    },
                    update = { view ->
                        webViewRef = view
                        canGoBack = view.canGoBack()
                        canGoForward = view.canGoForward()
                        showScrollTop = view.scrollY > 600
                        val viewport = (view.height / view.resources.displayMetrics.density).toInt().coerceAtLeast(1)
                        val maxScroll = (view.contentHeight - viewport).coerceAtLeast(1)
                        pageScrollPercent = ((view.scrollY * 100f) / maxScroll).toInt().coerceIn(0, 100)
                        applyRuntimeWebPreferences(view)
                    }
                )
            }

            if (loading) {
                Column(modifier = Modifier.fillMaxWidth().align(Alignment.TopCenter)) {
                    LinearProgressIndicator(
                        progress = { (loadProgress.coerceIn(0, 100) / 100f) },
                        modifier = Modifier.fillMaxWidth()
                    )
                    Text(
                        text = "%$loadProgress",
                        modifier = Modifier.align(Alignment.End).padding(end = 8.dp, top = 2.dp),
                        style = MaterialTheme.typography.labelSmall
                    )
                }
            }
            if (offline) {
                Card(
                    modifier = Modifier
                        .align(Alignment.Center)
                        .padding(16.dp)
                ) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Bağlantı yok", style = MaterialTheme.typography.titleMedium)
                        Text("İnternet gelince sayfa otomatik yenilenir.")
                        OutlinedButton(onClick = { webViewRef?.reload() }) {
                            Text("Yeniden Dene")
                        }
                    }
                }
            }
            if (loadTimedOut && !offline) {
                Card(
                    modifier = Modifier
                        .align(Alignment.Center)
                        .padding(16.dp)
                ) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Yükleme uzun sürdü", style = MaterialTheme.typography.titleMedium)
                        Text("Sayfayı yeniden deneyebilir veya ana sayfaya dönebilirsiniz.")
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = { webViewRef?.reload() }) { Text("Tekrar Dene") }
                            Button(onClick = { openSafeUrl(WEB_BASE_URL) }) { Text("Ana Sayfa") }
                        }
                    }
                }
            }
            if (showScrollTop) {
                SmallFloatingActionButton(
                    onClick = {
                        webViewRef?.post {
                            webViewRef?.pageUp(true)
                            webViewRef?.scrollTo(0, 0)
                            pageScrollPercent = 0
                        }
                    },
                    modifier = Modifier
                        .align(Alignment.BottomEnd)
                        .padding(end = 14.dp, bottom = 70.dp)
                ) {
                    Icon(Icons.Default.KeyboardArrowUp, contentDescription = "Yukarı Çık")
                }
            }
            if (!loading && pageScrollPercent > 0) {
                AssistChip(
                    onClick = {
                        webViewRef?.post {
                            webViewRef?.scrollTo(0, 0)
                            pageScrollPercent = 0
                        }
                    },
                    label = {
                        val eta = if (estimatedReadMinutes > 0) " • ~$estimatedReadMinutes dk" else ""
                        Text("Okuma %$pageScrollPercent$eta")
                    },
                    modifier = Modifier
                        .align(Alignment.BottomStart)
                        .padding(start = 12.dp, bottom = 70.dp)
                )
            }
            AnimatedVisibility(
                visible = showNavChrome,
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
            ) {
                Surface(
                    tonalElevation = 4.dp,
                    shadowElevation = 6.dp,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(horizontal = 8.dp, vertical = 6.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        IconButton(
                            onClick = {
                                rememberCurrentScroll(webViewRef)
                                persistScrollPositions(webPrefs, pageScrollMap)
                                webViewRef?.goBack()
                                webViewRef?.post {
                                    canGoBack = webViewRef?.canGoBack() == true
                                    canGoForward = webViewRef?.canGoForward() == true
                                }
                            },
                            enabled = canGoBack
                        ) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Geri") }
                        IconButton(
                            onClick = {
                                rememberCurrentScroll(webViewRef)
                                persistScrollPositions(webPrefs, pageScrollMap)
                                webViewRef?.goForward()
                                webViewRef?.post {
                                    canGoBack = webViewRef?.canGoBack() == true
                                    canGoForward = webViewRef?.canGoForward() == true
                                }
                            },
                            enabled = canGoForward
                        ) { Icon(Icons.AutoMirrored.Filled.ArrowForward, contentDescription = "İleri") }
                        IconButton(
                            onClick = { openSafeUrl(WEB_BASE_URL) }
                        ) { Icon(Icons.Default.Home, contentDescription = "Ana Sayfa") }
                        IconButton(
                            onClick = {
                                if (loading) webViewRef?.stopLoading() else webViewRef?.reload()
                            }
                        ) {
                            Icon(
                                if (loading) Icons.Default.Close else Icons.Default.Refresh,
                                contentDescription = if (loading) "Yüklemeyi Durdur" else "Yenile"
                            )
                        }
                        IconButton(
                            onClick = { showFavorites = true }
                        ) { Icon(Icons.Default.Favorite, contentDescription = "Favoriler") }
                        IconButton(
                            onClick = { showRecentPages = true }
                        ) { Icon(Icons.Default.History, contentDescription = "Son Sayfalar") }
                    }
                }
            }
        }
    }

    if (showHistory) {
        val filteredHistory = historyItems.filter {
            historyFilter == "all" || it.category == historyFilter
        }
        AlertDialog(
            onDismissRequest = { showHistory = false },
            title = { Text("Bildirim Geçmişi") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(
                        modifier = Modifier.horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        FilterChip(selected = historyFilter == "all", onClick = { historyFilter = "all" }, label = { Text("Tümü") })
                        FilterChip(selected = historyFilter == "homework_new", onClick = { historyFilter = "homework_new" }, label = { Text("Ödev") })
                        FilterChip(selected = historyFilter == "homework_reminder", onClick = { historyFilter = "homework_reminder" }, label = { Text("Hatırlatma") })
                        FilterChip(selected = historyFilter == "teacher_note", onClick = { historyFilter = "teacher_note" }, label = { Text("Not") })
                        FilterChip(selected = historyFilter == "correction_result", onClick = { historyFilter = "correction_result" }, label = { Text("Sonuç") })
                    }
                    if (filteredHistory.isEmpty()) {
                        Text("Filtreye uygun bildirim yok.")
                    } else {
                        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(filteredHistory) { row ->
                                Card(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clickable {
                                            showHistory = false
                                            if (row.deepLinkUrl.isNotBlank()) {
                                                openSafeUrl(row.deepLinkUrl)
                                            }
                                        }
                                ) {
                                    Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                        Text(row.title, fontWeight = FontWeight.SemiBold)
                                        Text(row.body)
                                        Text(
                                            SimpleDateFormat("dd.MM HH:mm", Locale.getDefault()).format(Date(row.createdAt)),
                                            style = MaterialTheme.typography.labelSmall
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { showHistory = false }) { Text("Kapat") }
            },
            dismissButton = {
                TextButton(onClick = {
                    NotificationCenter.clear(context)
                    historyItems = emptyList()
                }) { Text("Temizle") }
            }
        )
    }

    if (showFavorites) {
        AlertDialog(
            onDismissRequest = { showFavorites = false },
            title = { Text("Favoriler") },
            text = {
                if (favorites.isEmpty()) {
                    Text("Henüz favori yok.")
                } else {
                    LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(favorites) { item ->
                            Card(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable {
                                        showFavorites = false
                                        openSafeUrl(item)
                                    }
                            ) {
                                Row(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .padding(10.dp),
                                    horizontalArrangement = Arrangement.SpaceBetween,
                                    verticalAlignment = Alignment.CenterVertically
                                ) {
                                    Text(item, modifier = Modifier.weight(1f))
                                    TextButton(onClick = {
                                        favorites.remove(item)
                                        persistFavorites()
                                    }) { Text("Sil") }
                                }
                            }
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { showFavorites = false }) { Text("Kapat") }
            }
        )
    }

    if (showRecentPages) {
        AlertDialog(
            onDismissRequest = { showRecentPages = false },
            title = { Text("Son Sayfalar") },
            text = {
                if (recentPages.isEmpty()) {
                    Text("Henüz kayıtlı sayfa yok.")
                } else {
                    LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(recentPages) { row ->
                            Card(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable {
                                        showRecentPages = false
                                        openSafeUrl(row.url)
                                    }
                            ) {
                                Column(
                                    modifier = Modifier.fillMaxWidth().padding(10.dp),
                                    verticalArrangement = Arrangement.spacedBy(4.dp)
                                ) {
                                    Text(row.title, fontWeight = FontWeight.SemiBold)
                                    Text(row.url, style = MaterialTheme.typography.bodySmall)
                                    Text(
                                        SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault()).format(Date(row.visitedAt)),
                                        style = MaterialTheme.typography.labelSmall
                                    )
                                }
                            }
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { showRecentPages = false }) { Text("Kapat") }
            },
            dismissButton = {
                TextButton(onClick = {
                    recentPages.clear()
                    persistRecentPages(webPrefs, recentPages)
                }) { Text("Temizle") }
            }
        )
    }

    if (showSettings) {
        AlertDialog(
            onDismissRequest = { showSettings = false },
            title = { Text("WebView Ayarları") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("Desktop Modu", fontWeight = FontWeight.SemiBold)
                            Text("Web sitesini masaüstü görünümüyle açar.", style = MaterialTheme.typography.bodySmall)
                        }
                        Switch(
                            checked = desktopMode,
                            onCheckedChange = { checked ->
                                desktopMode = checked
                                webPrefs.edit().putBoolean(WEB_DESKTOP_MODE, checked).apply()
                                webViewRef?.let { applyRuntimeWebPreferences(it); it.reload() }
                            }
                        )
                    }
                    Column {
                        Text("Yazı Boyutu: %$textZoom", fontWeight = FontWeight.SemiBold)
                        Slider(
                            value = textZoom.toFloat(),
                            onValueChange = { value ->
                                textZoom = value.toInt().coerceIn(70, 180)
                                webPrefs.edit().putInt(WEB_TEXT_ZOOM, textZoom).apply()
                                webViewRef?.let { applyRuntimeWebPreferences(it) }
                            },
                            valueRange = 70f..180f
                        )
                    }
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("Data Saver", fontWeight = FontWeight.SemiBold)
                            Text("Görüntüleri kısıp veri kullanımını azaltır.", style = MaterialTheme.typography.bodySmall)
                        }
                        Switch(
                            checked = dataSaver,
                            onCheckedChange = { checked ->
                                dataSaver = checked
                                webPrefs.edit().putBoolean(WEB_DATA_SAVER, checked).apply()
                                webViewRef?.let { applyRuntimeWebPreferences(it); it.reload() }
                            }
                        )
                    }
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("Metni Otomatik Sığdır", fontWeight = FontWeight.SemiBold)
                            Text("Uzun satırları ekrana göre optimize eder.", style = MaterialTheme.typography.bodySmall)
                        }
                        Switch(
                            checked = textAutosize,
                            onCheckedChange = { checked ->
                                textAutosize = checked
                                webPrefs.edit().putBoolean(WEB_TEXT_AUTOSIZE, checked).apply()
                                webViewRef?.let { applyRuntimeWebPreferences(it); it.reload() }
                            }
                        )
                    }
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("Medya Otomatik Oynat", fontWeight = FontWeight.SemiBold)
                            Text("Video/ses içeriği dokunmadan başlayabilir.", style = MaterialTheme.typography.bodySmall)
                        }
                        Switch(
                            checked = mediaAutoplay,
                            onCheckedChange = { checked ->
                                mediaAutoplay = checked
                                webPrefs.edit().putBoolean(WEB_MEDIA_AUTOPLAY, checked).apply()
                                webViewRef?.let { applyRuntimeWebPreferences(it) }
                            }
                        )
                    }
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("Ekran Açık Kalsın", fontWeight = FontWeight.SemiBold)
                            Text("Ders sırasında ekranın kapanmasını engeller.", style = MaterialTheme.typography.bodySmall)
                        }
                        Switch(
                            checked = keepScreenOn,
                            onCheckedChange = { checked ->
                                keepScreenOn = checked
                                webPrefs.edit().putBoolean(WEB_KEEP_SCREEN_ON, checked).apply()
                            }
                        )
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { showSettings = false }) { Text("Kapat") }
            }
        )
    }

    if (showJumpDialog) {
        AlertDialog(
            onDismissRequest = { showJumpDialog = false },
            title = { Text("Hızlı Git") },
            text = {
                OutlinedTextField(
                    value = jumpInput,
                    onValueChange = { jumpInput = it },
                    modifier = Modifier.fillMaxWidth(),
                    placeholder = { Text("Örn: /portal, #dtz veya tam URL") },
                    singleLine = true
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    val target = resolveJumpInput(jumpInput)
                    if (target != null) {
                        openSafeUrl(target)
                        showJumpDialog = false
                    } else {
                        Toast.makeText(context, "Geçersiz adres", Toast.LENGTH_SHORT).show()
                    }
                }) { Text("Git") }
            },
            dismissButton = {
                TextButton(onClick = { showJumpDialog = false }) { Text("Kapat") }
            }
        )
    }

    DisposableEffect(Unit) {
        onDispose {
            rememberCurrentScroll(webViewRef)
            persistScrollPositions(webPrefs, pageScrollMap)
            pendingWebPermissionRequest?.deny()
            pendingWebPermissionRequest = null
            pendingGeoCallback = null
            pendingGeoOrigin = null
            webViewRef?.destroy()
        }
    }
}

@Composable
fun LoginScreen(onStudentLogin: (String, String) -> Unit, onTeacherLogin: (String, String) -> Unit) {
    var user by remember { mutableStateOf("") }
    var pass by remember { mutableStateOf("") }
    var role by remember { mutableStateOf("student") }
    Column(Modifier.padding(16.dp)) {
        Text("DTZ-LID edu", style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(12.dp))
        Text("Giriş tipi", style = MaterialTheme.typography.labelLarge)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(
                selected = role == "student",
                onClick = { role = "student" },
                label = { Text("Schüler") }
            )
            FilterChip(
                selected = role == "teacher",
                onClick = { role = "teacher" },
                label = { Text("Dozent") }
            )
        }
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(value = user, onValueChange = { user = it }, label = { Text("Benutzername") })
        OutlinedTextField(value = pass, onValueChange = { pass = it }, label = { Text("Passwort") }, visualTransformation = PasswordVisualTransformation())
        Spacer(Modifier.height(12.dp))
        Button(
            onClick = {
                if (role == "teacher") onTeacherLogin(user, pass) else onStudentLogin(user, pass)
            },
            modifier = Modifier.fillMaxWidth()
        ) { Text("Anmelden") }
    }
}

@Composable
fun MainTabs(session: StudentSession, onLogout: () -> Unit) {
    if (session.isTeacherRole()) {
        TeacherTabs(onLogout = onLogout)
        return
    }

    var selected by remember { mutableStateOf(0) }
    val items = listOf("Start", "DTZ", "Schreiben", "Portal", "Einstellungen")

    Scaffold(
        bottomBar = {
            NavigationBar {
                items.forEachIndexed { index, label ->
                    val icon = when (label) {
                        "Start" -> Icons.Default.Home
                        "DTZ" -> Icons.Default.Headphones
                        "Schreiben" -> Icons.Default.Edit
                        "Portal" -> Icons.Default.CheckCircle
                        else -> Icons.Default.Settings
                    }
                    NavigationBarItem(
                        selected = selected == index,
                        onClick = { selected = index },
                        icon = { Icon(icon, contentDescription = null) },
                        label = { Text(label) }
                    )
                }
            }
        }
    ) { pad ->
        Box(Modifier.padding(pad)) {
            when (selected) {
                0 -> HomeScreen()
                1 -> DtzScreen()
                2 -> WritingScreen()
                3 -> PortalScreen()
                4 -> SettingsScreen(onLogout)
            }
        }
    }
}

@Composable
fun TeacherTabs(onLogout: () -> Unit) {
    var selected by remember { mutableStateOf(0) }
    val items = listOf("Dozent", "Einstellungen")

    Scaffold(
        bottomBar = {
            NavigationBar {
                items.forEachIndexed { index, label ->
                    val icon = if (index == 0) Icons.Default.School else Icons.Default.Settings
                    NavigationBarItem(
                        selected = selected == index,
                        onClick = { selected = index },
                        icon = { Icon(icon, contentDescription = null) },
                        label = { Text(label) }
                    )
                }
            }
        }
    ) { pad ->
        Box(Modifier.padding(pad)) {
            when (selected) {
                0 -> TeacherPanelScreen()
                else -> SettingsScreen(onLogout)
            }
        }
    }
}

@Composable
fun HomeScreen() {
    Column(Modifier.padding(16.dp)) {
        Text("Willkommen", style = MaterialTheme.typography.headlineSmall)
        Text("DTZ Training und Schreiben", style = MaterialTheme.typography.bodyMedium)
    }
}

private fun defaultHomeworkStartAt(): String {
    val format = SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.getDefault())
    return format.format(Date(System.currentTimeMillis() + 15L * 60L * 1000L))
}

private fun parseDateToMillis(text: String): Long {
    val raw = text.trim()
    if (raw.isBlank()) return 0L
    val iso = runCatching { java.time.Instant.parse(raw).toEpochMilli() }.getOrNull()
    if (iso != null) return iso
    val patterns = listOf("yyyy-MM-dd HH:mm", "yyyy-MM-dd'T'HH:mm:ssX", "yyyy-MM-dd")
    for (pattern in patterns) {
        val value = runCatching {
            val fmt = SimpleDateFormat(pattern, Locale.getDefault())
            fmt.parse(raw)?.time ?: 0L
        }.getOrDefault(0L)
        if (value > 0L) return value
    }
    return 0L
}

@Composable
fun TeacherPanelScreen() {
    val scope = rememberCoroutineScope()
    var courses by remember { mutableStateOf(listOf<CourseItem>()) }
    var assignments by remember { mutableStateOf(listOf<HomeworkAdminItem>()) }
    var letters by remember { mutableStateOf(listOf<LetterRecord>()) }
    var loading by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf("") }

    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var durationMinutes by remember { mutableStateOf("30") }
    var startsAt by remember { mutableStateOf(defaultHomeworkStartAt()) }
    var targetType by remember { mutableStateOf("course") }
    var selectedCourseId by remember { mutableStateOf("") }
    var selectedCourseName by remember { mutableStateOf("") }
    var usernamesCsv by remember { mutableStateOf("") }
    var assignmentSort by remember { mutableStateOf("newest") }
    var assignmentSortExpanded by remember { mutableStateOf(false) }
    var selectedLetterCourseId by remember { mutableStateOf("") }
    var selectedLetterCourseName by remember { mutableStateOf("Tüm kurslar") }
    var letterCourseExpanded by remember { mutableStateOf(false) }
    var onlyPendingLetters by remember { mutableStateOf(false) }
    var pendingBulkDecision by remember { mutableStateOf<String?>(null) }

    val reviewNotes = remember { mutableStateMapOf<String, String>() }
    val selectedUploadIds = remember { mutableStateListOf<String>() }
    var courseExpanded by remember { mutableStateOf(false) }

    suspend fun refreshLetters() {
        letters = Api.listLetters(
            limit = 40,
            courseId = selectedLetterCourseId.takeIf { it.isNotBlank() }
        )
        selectedUploadIds.clear()
    }

    suspend fun bulkReview(visibleLetters: List<LetterRecord>, decision: String) {
        val targets = visibleLetters.filter { selectedUploadIds.contains(it.upload_id) }
        if (targets.isEmpty()) {
            status = "Önce en az bir mektup seç."
            return
        }
        var success = 0
        var failed = 0
        for (row in targets) {
            try {
                Api.reviewLetter(
                    uploadId = row.upload_id,
                    decision = decision,
                    note = reviewNotes[row.upload_id] ?: ""
                )
                success += 1
            } catch (_: Exception) {
                failed += 1
            }
        }
        refreshLetters()
        status = if (failed == 0) {
            "$success mektup işlendi."
        } else {
            "$success başarılı, $failed hata var."
        }
    }

    fun refreshAll() {
        scope.launch {
            loading = true
            try {
                val lookup = Api.courseList()
                courses = lookup.courses
                if (selectedCourseId.isBlank() && courses.isNotEmpty()) {
                    selectedCourseId = courses.first().course_id
                    selectedCourseName = courses.first().name
                }
                assignments = Api.homeworkList()
                refreshLetters()
                status = ""
            } catch (e: Exception) {
                status = e.message ?: "Veriler alınamadı."
            } finally {
                loading = false
            }
        }
    }

    LaunchedEffect(Unit) { refreshAll() }

    Column(
        modifier = Modifier
            .verticalScroll(rememberScrollState())
            .padding(16.dp)
    ) {
        Text("Dozent Paneli", style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(12.dp))

        Card {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Hausaufgabe geben", fontWeight = FontWeight.Bold)
                OutlinedTextField(value = title, onValueChange = { title = it }, label = { Text("Titel") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = description, onValueChange = { description = it }, label = { Text("Beschreibung") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = durationMinutes, onValueChange = { durationMinutes = it.filter { c -> c.isDigit() } }, label = { Text("Dauer (Min)") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = startsAt, onValueChange = { startsAt = it }, label = { Text("Start (yyyy-MM-dd HH:mm)") }, modifier = Modifier.fillMaxWidth())

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = targetType == "course", onClick = { targetType = "course" }, label = { Text("Kurs") })
                    FilterChip(selected = targetType == "users", onClick = { targetType = "users" }, label = { Text("Einzeln") })
                }

                if (targetType == "course") {
                    Box {
                        OutlinedButton(onClick = { courseExpanded = true }) {
                            Text(if (selectedCourseName.isNotBlank()) selectedCourseName else "Kurs wählen")
                        }
                        DropdownMenu(expanded = courseExpanded, onDismissRequest = { courseExpanded = false }) {
                            courses.forEach { c ->
                                DropdownMenuItem(
                                    text = { Text(c.name) },
                                    onClick = {
                                        selectedCourseId = c.course_id
                                        selectedCourseName = c.name
                                        courseExpanded = false
                                    }
                                )
                            }
                        }
                    }
                } else {
                    OutlinedTextField(
                        value = usernamesCsv,
                        onValueChange = { usernamesCsv = it },
                        label = { Text("Usernames (virgül ile)") },
                        modifier = Modifier.fillMaxWidth()
                    )
                }

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = {
                        scope.launch {
                            try {
                                loading = true
                                val users = usernamesCsv.split(",").map { it.trim() }.filter { it.isNotBlank() }
                                val result = Api.createHomeworkAssignment(
                                    title = title,
                                    description = description,
                                    durationMinutes = durationMinutes.toIntOrNull() ?: 30,
                                    startsAt = startsAt,
                                    targetType = targetType,
                                    courseId = selectedCourseId,
                                    usernames = users
                                )
                                status = "Odev verildi. Hedef ogrenci: ${result.target_count}"
                                assignments = Api.homeworkList()
                                title = ""
                                description = ""
                            } catch (e: Exception) {
                                status = e.message ?: "Odev verilemedi."
                            } finally {
                                loading = false
                            }
                        }
                    }) { Text("Ödevi Ver") }
                    OutlinedButton(onClick = { refreshAll() }) { Text("Yenile") }
                }
            }
        }

        Spacer(Modifier.height(12.dp))
        Card {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Hausaufgaben Takip", fontWeight = FontWeight.Bold)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = {
                        scope.launch {
                            try {
                                loading = true
                                val res = Api.runHomeworkReminders(levels = listOf("warn24"), dryRun = false)
                                status = "24s hatırlatma: ${res.created} gönderildi, ${res.skipped_already_sent} atlandı."
                            } catch (e: Exception) {
                                status = e.message ?: "24s hatırlatma çalışmadı."
                            } finally {
                                loading = false
                            }
                        }
                    }) { Text("24s Hatırlatma Çalıştır") }
                    OutlinedButton(onClick = {
                        scope.launch {
                            try {
                                loading = true
                                val res = Api.runHomeworkReminders(levels = listOf("warn2"), dryRun = false)
                                status = "2s hatırlatma: ${res.created} gönderildi, ${res.skipped_already_sent} atlandı."
                            } catch (e: Exception) {
                                status = e.message ?: "2s hatırlatma çalışmadı."
                            } finally {
                                loading = false
                            }
                        }
                    }) { Text("2s Hatırlatma Çalıştır") }
                }
                Box {
                    OutlinedButton(onClick = { assignmentSortExpanded = true }) {
                        Text(if (assignmentSort == "newest") "Tarih: Yeni -> Eski" else "Tarih: Eski -> Yeni")
                    }
                    DropdownMenu(expanded = assignmentSortExpanded, onDismissRequest = { assignmentSortExpanded = false }) {
                        DropdownMenuItem(
                            text = { Text("Yeni -> Eski") },
                            onClick = {
                                assignmentSort = "newest"
                                assignmentSortExpanded = false
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Eski -> Yeni") },
                            onClick = {
                                assignmentSort = "oldest"
                                assignmentSortExpanded = false
                            }
                        )
                    }
                }

                val sortedAssignments = assignments.sortedBy { item ->
                    val startsAtTs = parseDateToMillis(item.starts_at)
                    val createdAtTs = parseDateToMillis(item.created_at)
                    if (startsAtTs > 0L) startsAtTs else createdAtTs
                }.let { list ->
                    if (assignmentSort == "newest") list.reversed() else list
                }

                sortedAssignments.forEach { item ->
                    Card {
                        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(item.title, fontWeight = FontWeight.SemiBold)
                            Text("Hedef: ${item.target_label} | Durum: ${item.status}")
                            if (item.starts_at.isNotBlank()) {
                                Text("Baslangic: ${item.starts_at}")
                            }
                            Text("Teslim: ${item.submitted_total}/${item.assigned_total} | Baslayan: ${item.started_total}")
                            OutlinedButton(onClick = {
                                scope.launch {
                                    try {
                                        loading = true
                                        Api.setHomeworkActive(item.id, item.status != "active")
                                        assignments = Api.homeworkList()
                                        status = "Durum güncellendi."
                                    } catch (e: Exception) {
                                        status = e.message ?: "Durum değişmedi."
                                    } finally {
                                        loading = false
                                    }
                                }
                            }) {
                                Text(if (item.status == "active") "Pasife Al" else "Aktif Et")
                            }
                        }
                    }
                }
                if (sortedAssignments.isEmpty()) Text("Henüz görev yok.")
            }
        }

        Spacer(Modifier.height(12.dp))
        val visibleLetters = letters.filter {
            !onlyPendingLetters || it.review_status.equals("pending", ignoreCase = true)
        }
        Card {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Briefe bewerten", fontWeight = FontWeight.Bold)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Box {
                        OutlinedButton(onClick = { letterCourseExpanded = true }) {
                            Text(selectedLetterCourseName)
                        }
                        DropdownMenu(expanded = letterCourseExpanded, onDismissRequest = { letterCourseExpanded = false }) {
                            DropdownMenuItem(
                                text = { Text("Tüm kurslar") },
                                onClick = {
                                    selectedLetterCourseId = ""
                                    selectedLetterCourseName = "Tüm kurslar"
                                    letterCourseExpanded = false
                                }
                            )
                            courses.forEach { c ->
                                DropdownMenuItem(
                                    text = { Text(c.name) },
                                    onClick = {
                                        selectedLetterCourseId = c.course_id
                                        selectedLetterCourseName = c.name
                                        letterCourseExpanded = false
                                    }
                                )
                            }
                        }
                    }
                    FilterChip(
                        selected = onlyPendingLetters,
                        onClick = { onlyPendingLetters = !onlyPendingLetters },
                        label = { Text("Sadece bekleyen") }
                    )
                    OutlinedButton(onClick = {
                        scope.launch {
                            try {
                                loading = true
                                refreshLetters()
                                status = ""
                            } catch (e: Exception) {
                                status = e.message ?: "Mektup listesi alınamadı."
                            } finally {
                                loading = false
                            }
                        }
                    }) { Text("Filtrele") }
                }

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = {
                        selectedUploadIds.clear()
                        selectedUploadIds.addAll(visibleLetters.map { it.upload_id })
                    }) { Text("Tümünü Seç") }
                    OutlinedButton(onClick = { selectedUploadIds.clear() }) { Text("Seçimi Temizle") }
                    Button(onClick = {
                        pendingBulkDecision = "approve"
                    }) { Text("Seçiliyi Freigeben") }
                    OutlinedButton(onClick = {
                        pendingBulkDecision = "reject"
                    }) { Text("Seçiliyi Ablehnen") }
                }

                visibleLetters.forEach { row ->
                    Card {
                        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Checkbox(
                                        checked = selectedUploadIds.contains(row.upload_id),
                                        onCheckedChange = { checked ->
                                            if (checked) {
                                                if (!selectedUploadIds.contains(row.upload_id)) {
                                                    selectedUploadIds.add(row.upload_id)
                                                }
                                            } else {
                                                selectedUploadIds.remove(row.upload_id)
                                            }
                                        }
                                    )
                                    Text("${row.student_name} (${row.student_username})", fontWeight = FontWeight.SemiBold)
                                }
                                Text("Durum: ${row.review_status}")
                            }
                            Text("Puan: ${row.score_total?.toString() ?: "-"}")
                            if (row.task_prompt.isNotBlank()) Text("Konu: ${row.task_prompt}")
                            if (row.letter_text.isNotBlank()) Text(row.letter_text.take(160))
                            OutlinedTextField(
                                value = reviewNotes[row.upload_id] ?: "",
                                onValueChange = { reviewNotes[row.upload_id] = it },
                                label = { Text("Not") },
                                modifier = Modifier.fillMaxWidth()
                            )
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Button(onClick = {
                                    scope.launch {
                                        try {
                                            loading = true
                                            Api.reviewLetter(
                                                uploadId = row.upload_id,
                                                decision = "approve",
                                                note = reviewNotes[row.upload_id] ?: ""
                                            )
                                            refreshLetters()
                                            status = "Brief freigegeben."
                                        } catch (e: Exception) {
                                            status = e.message ?: "Freigabe hat nicht geklappt."
                                        } finally {
                                            loading = false
                                        }
                                    }
                                }) { Text("Freigeben") }
                                OutlinedButton(onClick = {
                                    scope.launch {
                                        try {
                                            loading = true
                                            Api.reviewLetter(
                                                uploadId = row.upload_id,
                                                decision = "reject",
                                                note = reviewNotes[row.upload_id] ?: ""
                                            )
                                            refreshLetters()
                                            status = "Brief abgelehnt."
                                        } catch (e: Exception) {
                                            status = e.message ?: "Ablehnung hat nicht geklappt."
                                        } finally {
                                            loading = false
                                        }
                                    }
                                }) { Text("Ablehnen") }
                            }
                        }
                    }
                }
                if (visibleLetters.isEmpty()) Text("Filtreye uygun mektup yok.")
            }
        }

        val decision = pendingBulkDecision
        if (decision != null) {
            AlertDialog(
                onDismissRequest = { pendingBulkDecision = null },
                title = { Text("Toplu işlem onayı") },
                text = {
                    Text(
                        if (decision == "approve") {
                            "Seçili mektupları toplu olarak freigeben yapmak istiyor musun?"
                        } else {
                            "Seçili mektupları toplu olarak ablehnen yapmak istiyor musun?"
                        }
                    )
                },
                confirmButton = {
                    Button(onClick = {
                        pendingBulkDecision = null
                        scope.launch {
                            try {
                                loading = true
                                bulkReview(visibleLetters, decision)
                            } finally {
                                loading = false
                            }
                        }
                    }) { Text("Evet") }
                },
                dismissButton = {
                    OutlinedButton(onClick = { pendingBulkDecision = null }) { Text("Vazgeç") }
                }
            )
        }

        Spacer(Modifier.height(8.dp))
        if (loading) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
        if (status.isNotBlank()) {
            Spacer(Modifier.height(8.dp))
            Text(status)
        }
    }
}

@Composable
fun DtzScreen() {
    var module by remember { mutableStateOf("hoeren") }
    var teil by remember { mutableStateOf(1) }
    var item by remember { mutableStateOf<JSONObject?>(null) }
    var status by remember { mutableStateOf("") }
    var scoreLabel by remember { mutableStateOf("") }
    var answers by remember { mutableStateOf(mutableMapOf<String, String>()) }
    val scope = rememberCoroutineScope()

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
        Text("DTZ Training", style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = { module = "hoeren"; teil = 1 }) { Text("Hören") }
            Button(onClick = { module = "lesen"; teil = 1 }) { Text("Lesen") }
        }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            val maxTeil = if (module == "hoeren") 4 else 5
            for (i in 1..maxTeil) {
                OutlinedButton(onClick = { teil = i }) { Text("Teil $i") }
            }
        }
        Spacer(Modifier.height(12.dp))
        Button(onClick = {
            scope.launch {
                status = ""
                scoreLabel = ""
                answers = mutableMapOf()
                item = Api.trainingSet(module, teil)
                if (item == null) status = "Aufgaben konnten nicht geladen werden"
            }
        }) { Text("Aufgaben laden") }
        Spacer(Modifier.height(12.dp))
        if (item != null) {
            TrainingItemView(item!!, answers)
            Spacer(Modifier.height(12.dp))
            Button(onClick = {
                val res = evaluateTraining(item!!, answers)
                scoreLabel = "Ergebnis: ${res.first}/${res.second}"
            }) { Text("Auswerten") }
            Spacer(Modifier.height(8.dp))
            Button(onClick = {
                scope.launch {
                    status = ""
                    scoreLabel = ""
                    answers = mutableMapOf()
                    item = Api.trainingSet(module, teil)
                    if (item == null) status = "Aufgaben konnten nicht geladen werden"
                }
            }) { Text("Neue Aufgaben") }
        }
        if (scoreLabel.isNotEmpty()) Text(scoreLabel, fontWeight = FontWeight.Bold)
        if (status.isNotEmpty()) Text(status)
    }
}

@Composable
fun TrainingItemView(item: JSONObject, answers: MutableMap<String, String>) {
    val schema = item.optString("dtz_schema")
    Text(item.optString("title"), fontWeight = FontWeight.Bold)
    Text(item.optString("instructions"))

    when (schema) {
        "hoeren_teil1_bundle", "hoeren_teil2_bundle" -> {
            val questions = item.optJSONArray("questions") ?: JSONArray()
            for (i in 0 until questions.length()) {
                val q = questions.getJSONObject(i)
                AudioScriptBlock(q.optString("audio_script"))
                Text(q.optString("question"), fontWeight = FontWeight.SemiBold)
                val options = q.optJSONArray("options") ?: JSONArray()
                for (idx in 0 until options.length()) {
                    val label = listOf("A", "B", "C").getOrNull(idx) ?: ""
                    ChoiceRow(label, options.getString(idx), answers, q.optString("id"))
                }
                Spacer(Modifier.height(8.dp))
            }
        }
        "hoeren_teil3_dialogcards" -> {
            val dialogs = item.optJSONArray("dialogs") ?: JSONArray()
            for (i in 0 until dialogs.length()) {
                val d = dialogs.getJSONObject(i)
                Text(d.optString("title"), fontWeight = FontWeight.Bold)
                AudioScriptBlock(d.optString("audio_script"))
                val tf = d.optJSONObject("true_false")
                if (tf != null) {
                    Text(tf.optString("statement"), fontWeight = FontWeight.SemiBold)
                    TrueFalseView(d.optString("id") + "_tf", answers)
                }
                val detail = d.optJSONObject("detail")
                if (detail != null) {
                    Text(detail.optString("question"), fontWeight = FontWeight.SemiBold)
                    val options = detail.optJSONArray("options") ?: JSONArray()
                    for (idx in 0 until options.length()) {
                        val label = listOf("A", "B", "C").getOrNull(idx) ?: ""
                        ChoiceRow(label, options.getString(idx), answers, d.optString("id") + "_mc")
                    }
                }
                Spacer(Modifier.height(8.dp))
            }
        }
        "hoeren_teil4_matching" -> {
            val options = item.optJSONObject("options") ?: JSONObject()
            OptionsList(options)
            val statements = item.optJSONArray("statements") ?: JSONArray()
            for (i in 0 until statements.length()) {
                val s = statements.getJSONObject(i)
                AudioScriptBlock(s.optString("audio_script"))
                MatchingPicker(s.optString("id"), options, answers)
                Spacer(Modifier.height(8.dp))
            }
        }
        "lesen_teil1_wegweiser" -> {
            WegweiserBlock(item)
            val situations = item.optJSONArray("situations") ?: JSONArray()
            for (i in 0 until situations.length()) {
                val s = situations.getJSONObject(i)
                Text("${s.optInt("no")} ${s.optString("prompt")}", fontWeight = FontWeight.SemiBold)
                val options = s.optJSONArray("options") ?: JSONArray()
                for (idx in 0 until options.length()) {
                    val label = listOf("A", "B", "C").getOrNull(idx) ?: ""
                    ChoiceRow(label, options.getString(idx), answers, s.optString("id"))
                }
                Spacer(Modifier.height(8.dp))
            }
        }
        "lesen_teil2_matching" -> {
            AnzeigenBlock(item)
            val situations = item.optJSONArray("situations") ?: JSONArray()
            val labels = item.optJSONArray("labels") ?: JSONArray()
            for (i in 0 until situations.length()) {
                val s = situations.getJSONObject(i)
                Text("${s.optInt("no")} ${s.optString("prompt")}", fontWeight = FontWeight.SemiBold)
                MatchingPicker(s.optString("id"), item.optJSONObject("ads") ?: JSONObject(), answers, labels)
                Spacer(Modifier.height(8.dp))
            }
        }
        "lesen_teil3_textblock_mix" -> {
            val blocks = item.optJSONArray("blocks") ?: JSONArray()
            for (i in 0 until blocks.length()) {
                val b = blocks.getJSONObject(i)
                Text(b.optString("title"), fontWeight = FontWeight.Bold)
                Text(b.optString("text"))
                val tf = b.optJSONObject("true_false")
                if (tf != null) {
                    Text(tf.optString("statement"), fontWeight = FontWeight.SemiBold)
                    TrueFalseView(b.optString("id") + "_tf", answers)
                }
                val mc = b.optJSONObject("mc")
                if (mc != null) {
                    Text(mc.optString("question"), fontWeight = FontWeight.SemiBold)
                    val options = mc.optJSONArray("options") ?: JSONArray()
                    for (idx in 0 until options.length()) {
                        val label = listOf("A", "B", "C").getOrNull(idx) ?: ""
                        ChoiceRow(label, options.getString(idx), answers, b.optString("id") + "_mc")
                    }
                }
                Spacer(Modifier.height(8.dp))
            }
        }
        "lesen_teil4_richtig_falsch_text" -> {
            Text(item.optString("text"))
            val statements = item.optJSONArray("statements") ?: JSONArray()
            for (i in 0 until statements.length()) {
                val s = statements.getJSONObject(i)
                Text("${s.optInt("no")} ${s.optString("statement")}", fontWeight = FontWeight.SemiBold)
                TrueFalseView(s.optString("id"), answers)
                Spacer(Modifier.height(8.dp))
            }
        }
        "lesen_teil5_lueckentext" -> {
            Text(item.optString("text_template"))
            val example = item.optJSONObject("example")
            if (example != null) {
                Text("Beispiel ${example.optInt("no")}", fontWeight = FontWeight.SemiBold)
                val options = example.optJSONArray("options") ?: JSONArray()
                for (idx in 0 until options.length()) {
                    val label = listOf("A", "B", "C").getOrNull(idx) ?: ""
                    ChoiceRow(label, options.getString(idx), mutableMapOf(), "")
                }
            }
            val gaps = item.optJSONArray("gaps") ?: JSONArray()
            for (i in 0 until gaps.length()) {
                val g = gaps.getJSONObject(i)
                Text("Lücke ${g.optInt("no")}", fontWeight = FontWeight.SemiBold)
                val options = g.optJSONArray("options") ?: JSONArray()
                for (idx in 0 until options.length()) {
                    val label = listOf("A", "B", "C").getOrNull(idx) ?: ""
                    ChoiceRow(label, options.getString(idx), answers, g.optString("id"))
                }
                Spacer(Modifier.height(8.dp))
            }
        }
        else -> Text("Aufgabe wird vorbereitet.")
    }
}

@Composable
fun AudioScriptBlock(text: String) {
    if (text.isBlank()) return
    Column {
        Text(text)
        TtsButton(text)
    }
}

@Composable
fun TtsButton(text: String) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val scope = rememberCoroutineScope()
    val player = rememberAudioScriptPlayer(context)
    Button(onClick = { player.toggle(scope, text) }) {
        Text(if (player.playing) "Stopp" else "Audio abspielen")
    }
}

@Composable
fun rememberAudioScriptPlayer(context: Context): AudioScriptPlayer {
    val player = remember(context) {
        AudioScriptPlayer(context.applicationContext)
    }
    DisposableEffect(Unit) {
        onDispose { player.release() }
    }
    return player
}

class AudioScriptPlayer(private val context: Context) {
    var playing by mutableStateOf(false)
        private set

    private val mainHandler = Handler(Looper.getMainLooper())
    private val tempFiles = mutableListOf<File>()
    private var playJob: Job? = null
    private var mediaPlayer: MediaPlayer? = null
    private var ttsReady = false
    private var tts: TextToSpeech? = null

    init {
        tts = TextToSpeech(context) { status ->
            if (status == TextToSpeech.SUCCESS) {
                ttsReady = true
                tts?.language = Locale.GERMANY
                tts?.setSpeechRate(0.92f)
                tts?.setPitch(0.97f)
            }
        }
        tts?.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
            override fun onStart(utteranceId: String?) = Unit

            override fun onDone(utteranceId: String?) {
                mainHandler.post { playing = false }
            }

            @Deprecated("Deprecated in Java")
            override fun onError(utteranceId: String?) {
                mainHandler.post { playing = false }
            }

            override fun onError(utteranceId: String?, errorCode: Int) {
                mainHandler.post { playing = false }
            }
        })
    }

    fun toggle(scope: CoroutineScope, text: String) {
        if (playing) {
            stop()
            return
        }

        playJob?.cancel()
        playJob = scope.launch {
            stopCurrentPlayback()
            playing = true
            try {
                val segments = Api.ttsSegments(text)
                val playable = segments.filter { it.url.isNotBlank() }
                if (playable.isEmpty()) {
                    playFallback(text)
                    return@launch
                }

                for (segment in playable) {
                    val file = Api.downloadAuthenticatedFile(context, segment.url)
                    tempFiles += file
                    playLocalFile(file)
                    delay(segment.pauseMs.coerceAtLeast(0).toLong())
                }
                playing = false
                cleanupTempFiles()
            } catch (_: CancellationException) {
            } catch (_: Exception) {
                playFallback(text)
            }
        }
    }

    fun stop() {
        playJob?.cancel()
        playJob = null
        stopCurrentPlayback()
        playing = false
    }

    fun release() {
        stop()
        tts?.shutdown()
        tts = null
    }

    private fun stopCurrentPlayback() {
        mediaPlayer?.let { player ->
            runCatching {
                player.stop()
                player.reset()
                player.release()
            }
        }
        mediaPlayer = null
        tts?.stop()
        cleanupTempFiles()
    }

    private fun cleanupTempFiles() {
        tempFiles.forEach { it.delete() }
        tempFiles.clear()
    }

    private fun playFallback(text: String) {
        stopCurrentPlayback()
        if (!ttsReady || text.isBlank()) {
            playing = false
            return
        }
        val engine = tts ?: run {
            playing = false
            return
        }
        engine.language = Locale.GERMANY
        engine.setSpeechRate(0.92f)
        engine.setPitch(0.97f)
        engine.speak(text, TextToSpeech.QUEUE_FLUSH, null, "hoeren-fallback")
    }

    private suspend fun playLocalFile(file: File) = suspendCancellableCoroutine { cont ->
        val player = MediaPlayer()
        mediaPlayer = player
        try {
            player.setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_MEDIA)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                    .build()
            )
            player.setDataSource(file.absolutePath)
            player.setOnCompletionListener { completed ->
                if (mediaPlayer === completed) {
                    mediaPlayer = null
                }
                completed.release()
                if (cont.isActive) {
                    cont.resume(Unit)
                }
            }
            player.setOnErrorListener { failed, _, _ ->
                if (mediaPlayer === failed) {
                    mediaPlayer = null
                }
                failed.release()
                if (cont.isActive) {
                    cont.resumeWithException(IOException("MediaPlayer error"))
                }
                true
            }
            player.setOnPreparedListener { prepared ->
                prepared.start()
            }
            player.prepareAsync()
        } catch (e: Exception) {
            if (mediaPlayer === player) {
                mediaPlayer = null
            }
            player.release()
            if (cont.isActive) {
                cont.resumeWithException(e)
            }
        }

        cont.invokeOnCancellation {
            if (mediaPlayer === player) {
                mediaPlayer = null
            }
            runCatching {
                player.stop()
                player.reset()
                player.release()
            }
        }
    }
}

@Composable
fun ChoiceRow(label: String, text: String, answers: MutableMap<String, String>, key: String) {
    Row(Modifier.fillMaxWidth().clickable { answers[key] = label }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        RadioButton(selected = answers[key] == label, onClick = { answers[key] = label })
        Text("$label) $text")
    }
}

@Composable
fun TrueFalseView(key: String, answers: MutableMap<String, String>) {
    Column {
        Row(Modifier.clickable { answers[key] = "Richtig" }) {
            RadioButton(selected = answers[key] == "Richtig", onClick = { answers[key] = "Richtig" })
            Text("Richtig")
        }
        Row(Modifier.clickable { answers[key] = "Falsch" }) {
            RadioButton(selected = answers[key] == "Falsch", onClick = { answers[key] = "Falsch" })
            Text("Falsch")
        }
    }
}

@Composable
fun OptionsList(options: JSONObject) {
    Column {
        options.keys().asSequence().sorted().forEach { key ->
            Text("$key: ${options.optString(key)}")
        }
    }
}

@Composable
fun MatchingPicker(key: String, options: JSONObject, answers: MutableMap<String, String>, labels: JSONArray? = null) {
    val list = labels?.let {
        (0 until it.length()).map { idx -> it.getString(idx) }
    } ?: options.keys().asSequence().toList().sorted()
    var expanded by remember { mutableStateOf(false) }
    val selected = answers[key] ?: ""
    Box {
        OutlinedButton(onClick = { expanded = true }) { Text(if (selected.isBlank()) "Bitte wählen" else selected) }
        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            list.forEach { label ->
                DropdownMenuItem(text = { Text(label) }, onClick = {
                    answers[key] = label
                    expanded = false
                })
            }
        }
    }
}

@Composable
fun WegweiserBlock(item: JSONObject) {
    Text(item.optString("wegweiser_title"), fontWeight = FontWeight.Bold)
    val arr = item.optJSONArray("wegweiser") ?: JSONArray()
    for (i in 0 until arr.length()) {
        Text(arr.getString(i))
    }
}

@Composable
fun AnzeigenBlock(item: JSONObject) {
    Text("Anzeigen", fontWeight = FontWeight.Bold)
    val ads = item.optJSONObject("ads") ?: JSONObject()
    ads.keys().asSequence().sorted().forEach { key ->
        Text("$key: ${ads.optString(key)}")
    }
}

fun evaluateTraining(item: JSONObject, answers: Map<String, String>): Pair<Int, Int> {
    val schema = item.optString("dtz_schema")
    var total = 0
    var correct = 0

    fun check(key: String, expected: String) {
        total += 1
        if (answers[key] == expected) correct += 1
    }

    fun mapTf(code: String): String {
        return if (code.uppercase(Locale.getDefault()) == "A") "Richtig" else "Falsch"
    }

    when (schema) {
        "hoeren_teil1_bundle", "hoeren_teil2_bundle" -> {
            val questions = item.optJSONArray("questions") ?: JSONArray()
            for (i in 0 until questions.length()) {
                val q = questions.getJSONObject(i)
                val key = q.optString("id")
                val expected = q.optString("correct")
                if (key.isNotBlank() && expected.isNotBlank()) {
                    check(key, expected)
                }
            }
        }
        "hoeren_teil3_dialogcards" -> {
            val dialogs = item.optJSONArray("dialogs") ?: JSONArray()
            for (i in 0 until dialogs.length()) {
                val d = dialogs.getJSONObject(i)
                val base = d.optString("id")
                val tf = d.optJSONObject("true_false")
                if (tf != null) {
                    val expected = mapTf(tf.optString("correct"))
                    check(base + "_tf", expected)
                }
                val detail = d.optJSONObject("detail")
                if (detail != null) {
                    val expected = detail.optString("correct")
                    if (expected.isNotBlank()) check(base + "_mc", expected)
                }
            }
        }
        "hoeren_teil4_matching" -> {
            val statements = item.optJSONArray("statements") ?: JSONArray()
            for (i in 0 until statements.length()) {
                val s = statements.getJSONObject(i)
                val key = s.optString("id")
                val expected = s.optString("correct")
                if (key.isNotBlank() && expected.isNotBlank()) check(key, expected)
            }
        }
        "lesen_teil1_wegweiser" -> {
            val situations = item.optJSONArray("situations") ?: JSONArray()
            for (i in 0 until situations.length()) {
                val s = situations.getJSONObject(i)
                val key = s.optString("id")
                val expected = s.optString("correct")
                if (key.isNotBlank() && expected.isNotBlank()) check(key, expected)
            }
        }
        "lesen_teil2_matching" -> {
            val situations = item.optJSONArray("situations") ?: JSONArray()
            for (i in 0 until situations.length()) {
                val s = situations.getJSONObject(i)
                val key = s.optString("id")
                val expected = s.optString("correct")
                if (key.isNotBlank() && expected.isNotBlank()) check(key, expected)
            }
        }
        "lesen_teil3_textblock_mix" -> {
            val blocks = item.optJSONArray("blocks") ?: JSONArray()
            for (i in 0 until blocks.length()) {
                val b = blocks.getJSONObject(i)
                val base = b.optString("id")
                val tf = b.optJSONObject("true_false")
                if (tf != null) {
                    val expected = mapTf(tf.optString("correct"))
                    check(base + "_tf", expected)
                }
                val mc = b.optJSONObject("mc")
                if (mc != null) {
                    val expected = mc.optString("correct")
                    if (expected.isNotBlank()) check(base + "_mc", expected)
                }
            }
        }
        "lesen_teil4_richtig_falsch_text" -> {
            val statements = item.optJSONArray("statements") ?: JSONArray()
            for (i in 0 until statements.length()) {
                val s = statements.getJSONObject(i)
                val key = s.optString("id")
                val expected = mapTf(s.optString("correct"))
                if (key.isNotBlank()) check(key, expected)
            }
        }
        "lesen_teil5_lueckentext" -> {
            val gaps = item.optJSONArray("gaps") ?: JSONArray()
            for (i in 0 until gaps.length()) {
                val g = gaps.getJSONObject(i)
                val key = g.optString("id")
                val expected = g.optString("correct")
                if (key.isNotBlank() && expected.isNotBlank()) check(key, expected)
            }
        }
    }

    return Pair(correct, total)
}

@Composable
fun WritingScreen() {
    var homework by remember { mutableStateOf<HomeworkCurrentResponse?>(null) }
    var text by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        scope.launch { homework = Api.currentHomework() }
    }

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
        Text("Mail schreiben", style = MaterialTheme.typography.headlineSmall)
        homework?.let { hw ->
            if (hw.has_assignment == true && hw.assignment != null) {
                Text(hw.assignment.title ?: "Aufgabe", fontWeight = FontWeight.Bold)
                Text(hw.assignment.description ?: "")
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(value = text, onValueChange = { text = it }, label = { Text("Ihre Mail") }, modifier = Modifier.fillMaxWidth())
                Button(onClick = {
                    scope.launch {
                        Api.submitLetter(hw.assignment.id ?: "", hw.assignment.description ?: "", text)
                        status = "Brief hochgeladen"
                    }
                }) { Text("Brief hochladen") }
            } else {
                Text(hw.message ?: "Keine Aufgabe")
            }
        }
        if (status.isNotEmpty()) Text(status)
    }
}

@Composable
fun PortalScreen() {
    var portal by remember { mutableStateOf<StudentPortalResponse?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        scope.launch { portal = Api.studentPortal() }
    }

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
        Text("Korrigierte Briefe", style = MaterialTheme.typography.headlineSmall)
        portal?.letter_corrections?.forEach {
            Text(it.topic ?: "Brief", fontWeight = FontWeight.Bold)
            Text(it.corrected_text ?: "")
            Spacer(Modifier.height(8.dp))
        }
    }
}

@Composable
fun SettingsScreen(onLogout: () -> Unit) {
    Column(Modifier.padding(16.dp)) {
        Button(onClick = onLogout) { Text("Abmelden") }
    }
}

data class StudentSession(
    val authenticated: Boolean? = false,
    val role_key: String? = "",
    val username: String? = "",
    val display_name: String? = ""
)

fun StudentSession.isTeacherRole(): Boolean {
    val key = (role_key ?: "").lowercase(Locale.getDefault())
    return key == "docent" || key == "hauptadmin"
}

data class HomeworkCurrentResponse(
    val has_assignment: Boolean? = false,
    val message: String? = "",
    val assignment: HomeworkAssignment? = null
)

data class HomeworkAssignment(
    val id: String? = "",
    val title: String? = "",
    val description: String? = ""
)

data class StudentPortalResponse(
    val letter_corrections: List<LetterCorrection> = emptyList()
)

data class LetterCorrection(
    val topic: String? = "",
    val corrected_text: String? = ""
)

data class CourseItem(
    val course_id: String = "",
    val name: String = ""
)

data class CourseLookupResponse(
    val courses: List<CourseItem> = emptyList()
)

data class HomeworkAdminItem(
    val id: String = "",
    val title: String = "",
    val target_label: String = "",
    val status: String = "",
    val starts_at: String = "",
    val created_at: String = "",
    val assigned_total: Int = 0,
    val started_total: Int = 0,
    val submitted_total: Int = 0
)

data class CreateHomeworkResponse(
    val assignment_id: String = "",
    val target_count: Int = 0
)

data class LetterRecord(
    val upload_id: String = "",
    val student_name: String = "",
    val student_username: String = "",
    val task_prompt: String = "",
    val letter_text: String = "",
    val review_status: String = "",
    val score_total: Int? = null
)

data class HomeworkReminderRunResult(
    val created: Int = 0,
    val skipped_already_sent: Int = 0
)

data class TtsSegment(
    val speaker: String = "",
    val voice: String = "",
    val pauseMs: Int = 0,
    val url: String = ""
)

object Api {
    private val cookieManager = CookieManager().apply { setCookiePolicy(CookiePolicy.ACCEPT_ALL) }
    private val client = OkHttpClient.Builder()
        .cookieJar(JavaNetCookieJar(cookieManager))
        .build()
    private const val baseUrl = "https://dtz-lid.com/"

    private fun buildUrl(path: String): String {
        val trimmed = path.trim()
        return when {
            trimmed.startsWith("https://") || trimmed.startsWith("http://") -> trimmed
            trimmed.startsWith("./") -> baseUrl + trimmed.removePrefix("./")
            trimmed.startsWith("/") -> baseUrl.removeSuffix("/") + trimmed
            else -> baseUrl + trimmed
        }
    }

    private fun errorMessage(body: String, fallback: String): String {
        return try {
            val parsed = JSONObject(body).optString("error")
            if (parsed.isBlank()) fallback else parsed
        } catch (_: Exception) {
            if (body.isBlank()) fallback else body
        }
    }

    suspend fun syncFcmTokenWithCurrentSession(context: Context): Boolean = withContext(Dispatchers.IO) {
        val prefs = context.getSharedPreferences(DTZFirebaseMessagingService.PREFS, Context.MODE_PRIVATE)
        val token = try {
            val pending = prefs.getString(DTZFirebaseMessagingService.KEY_PENDING_TOKEN, "") ?: ""
            if (pending.isNotBlank()) pending else FirebaseMessaging.getInstance().token.await()
        } catch (_: Exception) {
            ""
        }
        if (token.isBlank()) {
            return@withContext false
        }

        val lastToken = prefs.getString("last_registered_token", "") ?: ""
        val lastTryAt = prefs.getLong("last_register_try_at", 0L)
        val now = System.currentTimeMillis()
        if (token == lastToken && (now - lastTryAt) < 10 * 60 * 1000L) {
            return@withContext true
        }

        val webCookies = android.webkit.CookieManager.getInstance().getCookie(baseUrl) ?: ""
        if (!webCookies.contains("PHPSESSID=", ignoreCase = true)) {
            prefs.edit().putLong("last_register_try_at", now).apply()
            return@withContext false
        }

        val appVersion = runCatching {
            val pi = context.packageManager.getPackageInfo(context.packageName, 0)
            pi.versionName ?: ""
        }.getOrDefault("")

        val payload = JSONObject()
            .put("token", token)
            .put("platform", "android")
            .put("app_version", appVersion)
        val req = Request.Builder()
            .url(buildUrl("api/fcm_token_register.php"))
            .header("Cookie", webCookies)
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            prefs.edit().putLong("last_register_try_at", now).apply()
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "FCM token kaydedilemedi."))
            }
            prefs.edit()
                .putString("last_registered_token", token)
                .remove(DTZFirebaseMessagingService.KEY_PENDING_TOKEN)
                .apply()
            return@withContext true
        }
    }

    suspend fun studentSession(): StudentSession = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(buildUrl("api/student_session.php")).build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            val json = JSONObject(body)
            return@withContext StudentSession(
                authenticated = json.optBoolean("authenticated"),
                role_key = json.optString("role_key"),
                username = json.optString("username"),
                display_name = json.optString("display_name")
            )
        }
    }

    suspend fun studentLogin(user: String, pass: String): StudentSession = withContext(Dispatchers.IO) {
        val payload = JSONObject().put("username", user).put("password", pass)
        val req = Request.Builder()
            .url(buildUrl("api/student_login.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            val json = JSONObject(body)
            return@withContext StudentSession(
                authenticated = json.optBoolean("ok"),
                role_key = "student",
                username = json.optString("username"),
                display_name = json.optString("display_name")
            )
        }
    }

    suspend fun adminSession(): StudentSession = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(buildUrl("api/admin_session.php")).build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            val json = JSONObject(body)
            return@withContext StudentSession(
                authenticated = json.optBoolean("authenticated"),
                role_key = json.optString("role_key"),
                username = json.optString("username"),
                display_name = json.optString("display_name")
            )
        }
    }

    suspend fun adminLogin(user: String, pass: String): StudentSession = withContext(Dispatchers.IO) {
        val payload = JSONObject().put("username", user).put("password", pass)
        val req = Request.Builder()
            .url(buildUrl("api/admin_login.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Dozent girişi başarısız."))
            }
            val json = JSONObject(body)
            return@withContext StudentSession(
                authenticated = json.optBoolean("ok"),
                role_key = json.optString("role_key"),
                username = json.optString("username"),
                display_name = json.optString("display_name")
            )
        }
    }

    suspend fun adminLogout() = withContext(Dispatchers.IO) {
        val req = Request.Builder()
            .url(buildUrl("api/admin_logout.php"))
            .post("{}".toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().close()
    }

    suspend fun studentLogout() = withContext(Dispatchers.IO) {
        val payload = "{}"
        val req = Request.Builder()
            .url(buildUrl("api/student_logout.php"))
            .post(payload.toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().close()
    }

    suspend fun trainingSet(module: String, teil: Int): JSONObject? = withContext(Dispatchers.IO) {
        val payload = JSONObject().put("module", module).put("teil", teil)
        val req = Request.Builder()
            .url(buildUrl("api/student_training_set.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: return@withContext null
            val json = JSONObject(body)
            return@withContext json.optJSONObject("set")?.optJSONArray("items")?.optJSONObject(0)
        }
    }

    suspend fun currentHomework(): HomeworkCurrentResponse = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(buildUrl("api/student_homework_current.php")).build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            val json = JSONObject(body)
            val assignment = json.optJSONObject("assignment")
            return@withContext HomeworkCurrentResponse(
                has_assignment = json.optBoolean("has_assignment"),
                message = json.optString("message"),
                assignment = if (assignment != null) HomeworkAssignment(
                    id = assignment.optString("id"),
                    title = assignment.optString("title"),
                    description = assignment.optString("description")
                ) else null
            )
        }
    }

    suspend fun submitLetter(assignmentId: String, prompt: String, text: String) = withContext(Dispatchers.IO) {
        val payload = JSONObject()
            .put("assignment_id", assignmentId)
            .put("task_prompt", prompt)
            .put("letter_text", text)
            .put("student_name", "")
            .put("required_points", emptyList<String>())
            .put("writing_duration_seconds", 0)
        val req = Request.Builder()
            .url(buildUrl("api/save_letter.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().close()
    }

    suspend fun courseList(): CourseLookupResponse = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(buildUrl("api/course_list.php")).build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Kurs listesi alınamadı."))
            }
            val json = JSONObject(body)
            val arr = json.optJSONArray("courses") ?: JSONArray()
            val list = mutableListOf<CourseItem>()
            for (i in 0 until arr.length()) {
                val c = arr.optJSONObject(i) ?: continue
                val id = c.optString("course_id")
                val name = c.optString("name").ifBlank { id }
                if (id.isNotBlank()) {
                    list.add(CourseItem(course_id = id, name = name))
                }
            }
            return@withContext CourseLookupResponse(courses = list)
        }
    }

    suspend fun homeworkList(): List<HomeworkAdminItem> = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(buildUrl("api/homework_list.php")).build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Hausaufgaben alınamadı."))
            }
            val json = JSONObject(body)
            val arr = json.optJSONArray("assignments") ?: JSONArray()
            return@withContext buildList {
                for (i in 0 until arr.length()) {
                    val item = arr.optJSONObject(i) ?: continue
                    add(
                        HomeworkAdminItem(
                            id = item.optString("id"),
                            title = item.optString("title"),
                            target_label = item.optString("target_label"),
                            status = item.optString("status"),
                            starts_at = item.optString("starts_at"),
                            created_at = item.optString("created_at"),
                            assigned_total = item.optInt("assigned_total"),
                            started_total = item.optInt("started_total"),
                            submitted_total = item.optInt("submitted_total")
                        )
                    )
                }
            }
        }
    }

    suspend fun createHomeworkAssignment(
        title: String,
        description: String,
        durationMinutes: Int,
        startsAt: String,
        targetType: String,
        courseId: String,
        usernames: List<String>
    ): CreateHomeworkResponse = withContext(Dispatchers.IO) {
        val payload = JSONObject()
            .put("action", "create")
            .put("title", title)
            .put("description", description)
            .put("duration_minutes", durationMinutes)
            .put("starts_at", startsAt)
            .put("target_type", targetType)
            .put("course_id", courseId)
            .put("usernames", JSONArray(usernames))
        val req = Request.Builder()
            .url(buildUrl("api/homework_assign.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Hausaufgabe oluşturulamadı."))
            }
            val json = JSONObject(body)
            return@withContext CreateHomeworkResponse(
                assignment_id = json.optString("assignment_id"),
                target_count = json.optInt("target_count")
            )
        }
    }

    suspend fun setHomeworkActive(assignmentId: String, active: Boolean) = withContext(Dispatchers.IO) {
        val payload = JSONObject()
            .put("action", "set_active")
            .put("assignment_id", assignmentId)
            .put("active", active)
        val req = Request.Builder()
            .url(buildUrl("api/homework_assign.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Hausaufgabe durumu değiştirilemedi."))
            }
        }
    }

    suspend fun listLetters(limit: Int, courseId: String? = null): List<LetterRecord> = withContext(Dispatchers.IO) {
        val payload = JSONObject().put("limit", limit)
        if (!courseId.isNullOrBlank()) {
            payload.put("course_id", courseId)
        }
        val req = Request.Builder()
            .url(buildUrl("api/list_letters.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Mektuplar alınamadı."))
            }
            val json = JSONObject(body)
            val arr = json.optJSONArray("records") ?: JSONArray()
            return@withContext buildList {
                for (i in 0 until arr.length()) {
                    val row = arr.optJSONObject(i) ?: continue
                    val score = if (row.has("score_total") && !row.isNull("score_total")) row.optInt("score_total") else null
                    add(
                        LetterRecord(
                            upload_id = row.optString("upload_id"),
                            student_name = row.optString("student_name"),
                            student_username = row.optString("student_username"),
                            task_prompt = row.optString("task_prompt"),
                            letter_text = row.optString("letter_text"),
                            review_status = row.optString("review_status"),
                            score_total = score
                        )
                    )
                }
            }
        }
    }

    suspend fun reviewLetter(uploadId: String, decision: String, note: String) = withContext(Dispatchers.IO) {
        val payload = JSONObject()
            .put("upload_id", uploadId)
            .put("decision", decision)
            .put("note", note)
        val req = Request.Builder()
            .url(buildUrl("api/letter_review.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Mektup değerlendirme kaydedilemedi."))
            }
        }
    }

    suspend fun runHomeworkReminders(levels: List<String>, dryRun: Boolean = false): HomeworkReminderRunResult = withContext(Dispatchers.IO) {
        val payload = JSONObject()
            .put("levels", JSONArray(levels))
            .put("dry_run", dryRun)
        val req = Request.Builder()
            .url(buildUrl("api/homework_reminder_run.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(errorMessage(body, "Reminder çalıştırılamadı."))
            }
            val json = JSONObject(body)
            return@withContext HomeworkReminderRunResult(
                created = json.optInt("created"),
                skipped_already_sent = json.optInt("skipped_already_sent")
            )
        }
    }

    suspend fun studentPortal(): StudentPortalResponse = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(buildUrl("api/student_portal.php")).build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            val json = JSONObject(body)
            val list = mutableListOf<LetterCorrection>()
            val arr = json.optJSONArray("letter_corrections")
            if (arr != null) {
                for (i in 0 until arr.length()) {
                    val c = arr.getJSONObject(i)
                    list.add(LetterCorrection(topic = c.optString("topic"), corrected_text = c.optString("corrected_text")))
                }
            }
            return@withContext StudentPortalResponse(letter_corrections = list)
        }
    }

    suspend fun ttsSegments(script: String): List<TtsSegment> = withContext(Dispatchers.IO) {
        val payload = JSONObject().put("script", script)
        val req = Request.Builder()
            .url(buildUrl("api/tts_generate.php"))
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            if (!res.isSuccessful) {
                throw IOException(body.ifBlank { "TTS request failed" })
            }
            val json = JSONObject(body)
            val arr = json.optJSONArray("segments") ?: return@withContext emptyList()
            return@withContext buildList {
                for (i in 0 until arr.length()) {
                    val item = arr.getJSONObject(i)
                    add(
                        TtsSegment(
                            speaker = item.optString("speaker"),
                            voice = item.optString("voice"),
                            pauseMs = item.optInt("pause_ms"),
                            url = item.optString("url")
                        )
                    )
                }
            }
        }
    }

    suspend fun downloadAuthenticatedFile(context: Context, path: String): File = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(buildUrl(path)).build()
        client.newCall(req).execute().use { res ->
            if (!res.isSuccessful) {
                throw IOException("TTS audio download failed: ${res.code}")
            }
            val bytes = res.body?.bytes() ?: throw IOException("TTS audio body empty")
            val file = File.createTempFile("tts-", ".mp3", context.cacheDir)
            file.writeBytes(bytes)
            return@withContext file
        }
    }
}
