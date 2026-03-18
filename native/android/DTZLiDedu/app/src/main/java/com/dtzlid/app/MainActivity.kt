package com.dtzlid.app

import android.annotation.SuppressLint
import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.compose.BackHandler
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
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
        setContent { AppRoot() }
    }
}

@Composable
fun AppRoot() {
    WebAppScreen()
}

@SuppressLint("SetJavaScriptEnabled")
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun WebAppScreen() {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var webViewRef by remember { mutableStateOf<WebView?>(null) }
    var canGoBack by remember { mutableStateOf(false) }
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
            Api.syncFcmTokenWithCurrentSession(context)
        }
    }

    BackHandler(enabled = canGoBack) {
        webViewRef?.goBack()
    }

    Scaffold(
        topBar = {
            SmallTopAppBar(
                title = { Text("DTZ-LID edu") }
            )
        }
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            AndroidView(
                modifier = Modifier.fillMaxSize(),
                factory = {
                    WebView(context).apply {
                        settings.javaScriptEnabled = true
                        settings.domStorageEnabled = true
                        settings.javaScriptCanOpenWindowsAutomatically = true
                        settings.loadsImagesAutomatically = true
                        webChromeClient = object : WebChromeClient() {
                            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                                loading = newProgress < 100
                            }
                        }
                        webViewClient = object : WebViewClient() {
                            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                                return false
                            }

                            override fun onPageFinished(view: WebView?, url: String?) {
                                canGoBack = view?.canGoBack() == true
                                loading = false
                                scope.launch {
                                    Api.syncFcmTokenWithCurrentSession(context)
                                }
                            }
                        }
                        loadUrl("https://dtz-lid.com")
                    }
                },
                update = { view ->
                    webViewRef = view
                    canGoBack = view.canGoBack()
                }
            )

            if (loading) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth().align(Alignment.TopCenter))
            }
        }
    }

    DisposableEffect(Unit) {
        onDispose {
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
