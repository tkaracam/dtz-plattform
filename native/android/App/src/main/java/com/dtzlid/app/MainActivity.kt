package com.dtzlid.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.net.CookieManager
import java.net.CookiePolicy

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { AppRoot() }
    }
}

@Composable
fun AppRoot() {
    val scope = rememberCoroutineScope()
    var session by remember { mutableStateOf(StudentSession()) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        scope.launch {
            session = Api.studentSession()
            loading = false
        }
    }

    if (loading) {
        Box(Modifier.fillMaxSize(), contentAlignment = androidx.compose.ui.Alignment.Center) {
            CircularProgressIndicator()
        }
    } else {
        if (session.authenticated == true) {
            MainTabs(onLogout = {
                scope.launch {
                    Api.studentLogout()
                    session = StudentSession()
                }
            })
        } else {
            LoginScreen(onLogin = { u, p ->
                scope.launch {
                    session = Api.studentLogin(u, p)
                }
            })
        }
    }
}

@Composable
fun LoginScreen(onLogin: (String, String) -> Unit) {
    var user by remember { mutableStateOf("") }
    var pass by remember { mutableStateOf("") }
    Column(Modifier.padding(16.dp)) {
        Text("DTZ-LID edu", style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(value = user, onValueChange = { user = it }, label = { Text("Benutzername") })
        OutlinedTextField(value = pass, onValueChange = { pass = it }, label = { Text("Passwort") }, visualTransformation = PasswordVisualTransformation())
        Spacer(Modifier.height(12.dp))
        Button(onClick = { onLogin(user, pass) }, modifier = Modifier.fillMaxWidth()) { Text("Anmelden") }
    }
}

@Composable
fun MainTabs(onLogout: () -> Unit) {
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
fun HomeScreen() {
    Column(Modifier.padding(16.dp)) {
        Text("Willkommen", style = MaterialTheme.typography.headlineSmall)
        Text("DTZ Training und Schreiben", style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
fun DtzScreen() {
    var module by remember { mutableStateOf("hoeren") }
    var teil by remember { mutableStateOf(1) }
    var item by remember { mutableStateOf<JSONObject?>(null) }
    var status by remember { mutableStateOf("") }
    var answers by remember { mutableStateOf(mutableMapOf<String, String>()) }
    val scope = rememberCoroutineScope()

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
        Text("DTZ Training", style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = { module = "hoeren" }) { Text("Hören") }
            Button(onClick = { module = "lesen" }) { Text("Lesen") }
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
                answers = mutableMapOf()
                item = Api.trainingSet(module, teil)
                if (item == null) status = "Aufgaben konnten nicht geladen werden"
            }
        }) { Text("Aufgaben laden") }
        Spacer(Modifier.height(12.dp))
        if (item != null) {
            TrainingItemView(item!!, answers)
            Spacer(Modifier.height(12.dp))
            Button(onClick = { status = "Antworten gespeichert" }) { Text("Auswerten") }
        }
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
            val questions = item.optJSONArray("questions")
            if (questions != null) {
                for (i in 0 until questions.length()) {
                    val q = questions.getJSONObject(i)
                    Text(q.optString("audio_script"))
                    Text(q.optString("question"), fontWeight = FontWeight.SemiBold)
                    val options = q.optJSONArray("options")
                    if (options != null) {
                        for (idx in 0 until options.length()) {
                            val label = listOf("A", "B", "C").getOrNull(idx) ?: ""
                            ChoiceRow(label, options.getString(idx), answers, q.optString("id"))
                        }
                    }
                    Spacer(Modifier.height(8.dp))
                }
            }
        }
        else -> {
            Text("Aufgabe wird vorbereitet.")
        }
    }
}

@Composable
fun ChoiceRow(label: String, text: String, answers: MutableMap<String, String>, key: String) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        val selected = answers[key] == label
        RadioButton(selected = selected, onClick = { answers[key] = label })
        Text("$label) $text")
    }
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
    val username: String? = "",
    val display_name: String? = ""
)

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

object Api {
    private val cookieManager = CookieManager().apply { setCookiePolicy(CookiePolicy.ACCEPT_ALL) }
    private val client = OkHttpClient.Builder()
        .cookieJar(JavaNetCookieJar(cookieManager))
        .build()
    private const val baseUrl = "https://dtz-lid.com/"

    suspend fun studentSession(): StudentSession = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(baseUrl + "api/student_session.php").build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            val json = JSONObject(body)
            return@withContext StudentSession(
                authenticated = json.optBoolean("authenticated"),
                username = json.optString("username"),
                display_name = json.optString("display_name")
            )
        }
    }

    suspend fun studentLogin(user: String, pass: String): StudentSession = withContext(Dispatchers.IO) {
        val payload = JSONObject().put("username", user).put("password", pass)
        val req = Request.Builder()
            .url(baseUrl + "api/student_login.php")
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            val json = JSONObject(body)
            return@withContext StudentSession(
                authenticated = json.optBoolean("ok"),
                username = json.optString("username"),
                display_name = json.optString("display_name")
            )
        }
    }

    suspend fun studentLogout() = withContext(Dispatchers.IO) {
        val payload = "{}"
        val req = Request.Builder()
            .url(baseUrl + "api/student_logout.php")
            .post(payload.toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().close()
    }

    suspend fun trainingSet(module: String, teil: Int): JSONObject? = withContext(Dispatchers.IO) {
        val payload = JSONObject().put("module", module).put("teil", teil)
        val req = Request.Builder()
            .url(baseUrl + "api/student_training_set.php")
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: return@withContext null
            val json = JSONObject(body)
            return@withContext json.optJSONObject("set")?.optJSONArray("items")?.optJSONObject(0)
        }
    }

    suspend fun currentHomework(): HomeworkCurrentResponse = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(baseUrl + "api/student_homework_current.php").build()
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
            .url(baseUrl + "api/save_letter.php")
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().close()
    }

    suspend fun studentPortal(): StudentPortalResponse = withContext(Dispatchers.IO) {
        val req = Request.Builder().url(baseUrl + "api/student_portal.php").build()
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
}
