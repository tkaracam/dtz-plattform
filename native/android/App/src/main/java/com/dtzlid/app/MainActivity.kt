package com.dtzlid.app

import android.content.Context
import android.os.Bundle
import android.speech.tts.TextToSpeech
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.net.CookieManager
import java.net.CookiePolicy
import java.util.Locale

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
    var showOnboarding by remember { mutableStateOf(true) }
    val context = androidx.compose.ui.platform.LocalContext.current

    LaunchedEffect(Unit) {
        scope.launch {
            session = Api.studentSession()
            loading = false
        }
        val prefs = context.getSharedPreferences("dtzlid_prefs", Context.MODE_PRIVATE)
        showOnboarding = !prefs.getBoolean("onboarding_seen", false)
    }

    if (loading) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
    } else if (showOnboarding) {
        OnboardingScreen(onDone = {
            context.getSharedPreferences("dtzlid_prefs", Context.MODE_PRIVATE)
                .edit().putBoolean("onboarding_seen", true).apply()
            showOnboarding = false
        })
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
                scope.launch { session = Api.studentLogin(u, p) }
            })
        }
    }
}

@Composable
fun OnboardingScreen(onDone: () -> Unit) {
    var step by remember { mutableStateOf(0) }
    val pages = listOf(
        "DTZ Training" to "Hören und Lesen in Teilen üben",
        "Schreiben" to "Brief schreiben und hochladen",
        "Portal" to "Korrigierte Briefe im Überblick"
    )

    Column(Modifier.fillMaxSize().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Spacer(Modifier.weight(1f))
        Text(pages[step].first, style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(12.dp))
        Text(pages[step].second, style = MaterialTheme.typography.bodyLarge)
        Spacer(Modifier.weight(1f))
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            OutlinedButton(onClick = onDone) { Text("Überspringen") }
            Button(onClick = {
                if (step == pages.size - 1) onDone() else step += 1
            }) { Text(if (step == pages.size - 1) "Start" else "Weiter") }
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
                if (item == null) {
                    item = demoTrainingItem(module, teil)
                    status = if (item == null) "Aufgaben konnten nicht geladen werden" else "Demo-Modus"
                }
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
                    if (item == null) {
                        item = demoTrainingItem(module, teil)
                        status = if (item == null) "Aufgaben konnten nicht geladen werden" else "Demo-Modus"
                    }
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
    val tts = rememberTts(context)
    Button(onClick = { tts.speak(text, TextToSpeech.QUEUE_FLUSH, null, "tts1") }) {
        Text("▶")
    }
}

@Composable
fun rememberTts(context: Context): TextToSpeech {
    val tts = remember {
        TextToSpeech(context) { status ->
            if (status == TextToSpeech.SUCCESS) {
                tts.language = Locale.GERMANY
            }
        }
    }
    DisposableEffect(Unit) {
        onDispose { tts.shutdown() }
    }
    return tts
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

fun demoTrainingItem(module: String, teil: Int): JSONObject? {
    if (module == "hoeren") {
        if (teil == 1 || teil == 2) {
            val questions = JSONArray()
            questions.put(JSONObject()
                .put("id", "h$teil-q1")
                .put("question", "Wann beginnt der Kurs?")
                .put("options", JSONArray().put("Um 8 Uhr").put("Um 9 Uhr").put("Um 10 Uhr"))
                .put("correct", "B")
                .put("audio_script", "Der Kurs beginnt um neun Uhr."))
            questions.put(JSONObject()
                .put("id", "h$teil-q2")
                .put("question", "Wo treffen sich die Teilnehmenden?")
                .put("options", JSONArray().put("Im Raum 2").put("Im Raum 3").put("Im Raum 4"))
                .put("correct", "A")
                .put("audio_script", "Wir treffen uns im Raum zwei."))
            questions.put(JSONObject()
                .put("id", "h$teil-q3")
                .put("question", "Was sollen Sie mitbringen?")
                .put("options", JSONArray().put("Einen Ausweis").put("Ein Foto").put("Ein Formular"))
                .put("correct", "C")
                .put("audio_script", "Bitte bringen Sie das Formular mit."))
            questions.put(JSONObject()
                .put("id", "h$teil-q4")
                .put("question", "Wie lange dauert der Termin?")
                .put("options", JSONArray().put("10 Minuten").put("20 Minuten").put("30 Minuten"))
                .put("correct", "B")
                .put("audio_script", "Der Termin dauert etwa zwanzig Minuten."))
            return JSONObject()
                .put("dtz_schema", if (teil == 1) "hoeren_teil1_bundle" else "hoeren_teil2_bundle")
                .put("dtz_part", "H$teil")
                .put("title", "Demo Hören Teil $teil")
                .put("instructions", "Hören Sie den Text und wählen Sie die richtige Lösung.")
                .put("questions", questions)
        }
        if (teil == 3) {
            val dialogs = JSONArray()
            dialogs.put(JSONObject()
                .put("id", "h3-d1")
                .put("title", "Dialog 1")
                .put("audio_script", "A: Hast du morgen Zeit? B: Ja, am Nachmittag.")
                .put("true_false", JSONObject().put("statement", "Sie haben morgen Nachmittag Zeit.").put("correct", "A"))
                .put("detail", JSONObject().put("question", "Wann passt es?")
                    .put("options", JSONArray().put("Morgens").put("Nachmittags").put("Abends"))
                    .put("correct", "B")))
            dialogs.put(JSONObject()
                .put("id", "h3-d2")
                .put("title", "Dialog 2")
                .put("audio_script", "A: Kannst du heute kommen? B: Leider nicht, ich arbeite bis sechs.")
                .put("true_false", JSONObject().put("statement", "Die Person arbeitet bis 18 Uhr.").put("correct", "A"))
                .put("detail", JSONObject().put("question", "Warum kann sie nicht kommen?")
                    .put("options", JSONArray().put("Krankheit").put("Arbeit").put("Urlaub"))
                    .put("correct", "B")))
            return JSONObject()
                .put("dtz_schema", "hoeren_teil3_dialogcards")
                .put("dtz_part", "H3")
                .put("title", "Demo Hören Teil 3")
                .put("instructions", "Hören Sie die Dialoge.")
                .put("dialogs", dialogs)
        }
        if (teil == 4) {
            val options = JSONObject().put("A", "Einladung").put("B", "Termin absagen").put("C", "Information")
            val statements = JSONArray()
            statements.put(JSONObject()
                .put("id", "h4-s1")
                .put("audio_script", "Der Termin morgen muss leider verschoben werden.")
                .put("correct", "B"))
            statements.put(JSONObject()
                .put("id", "h4-s2")
                .put("audio_script", "Sie sind herzlich zur Feier eingeladen.")
                .put("correct", "A"))
            statements.put(JSONObject()
                .put("id", "h4-s3")
                .put("audio_script", "Der Kurs startet am Montag um neun Uhr.")
                .put("correct", "C"))
            statements.put(JSONObject()
                .put("id", "h4-s4")
                .put("audio_script", "Bitte beachten Sie die neuen Öffnungszeiten.")
                .put("correct", "C"))
            return JSONObject()
                .put("dtz_schema", "hoeren_teil4_matching")
                .put("dtz_part", "H4")
                .put("title", "Demo Hören Teil 4")
                .put("instructions", "Ordnen Sie die Aussagen zu.")
                .put("options", options)
                .put("labels", JSONArray().put("A").put("B").put("C"))
                .put("statements", statements)
        }
    }

    if (module == "lesen") {
        if (teil == 1) {
            val situations = JSONArray()
            situations.put(JSONObject()
                .put("id", "l1-s1")
                .put("no", 1)
                .put("prompt", "Sie möchten sich anmelden.")
                .put("options", JSONArray().put("EG").put("1. OG").put("2. OG"))
                .put("correct", "A"))
            situations.put(JSONObject()
                .put("id", "l1-s2")
                .put("no", 2)
                .put("prompt", "Sie suchen die Bibliothek.")
                .put("options", JSONArray().put("EG").put("1. OG").put("2. OG"))
                .put("correct", "C"))
            situations.put(JSONObject()
                .put("id", "l1-s3")
                .put("no", 3)
                .put("prompt", "Sie brauchen Raum 2.")
                .put("options", JSONArray().put("EG").put("1. OG").put("2. OG"))
                .put("correct", "B"))
            situations.put(JSONObject()
                .put("id", "l1-s4")
                .put("no", 4)
                .put("prompt", "Sie möchten Informationen.")
                .put("options", JSONArray().put("EG").put("1. OG").put("2. OG"))
                .put("correct", "A"))
            situations.put(JSONObject()
                .put("id", "l1-s5")
                .put("no", 5)
                .put("prompt", "Sie suchen Kursraum 3.")
                .put("options", JSONArray().put("EG").put("1. OG").put("2. OG"))
                .put("correct", "B"))
            return JSONObject()
                .put("dtz_schema", "lesen_teil1_wegweiser")
                .put("dtz_part", "L1")
                .put("title", "Demo Lesen Teil 1")
                .put("instructions", "Wählen Sie die richtige Stelle.")
                .put("wegweiser_title", "Wegweiser")
                .put("wegweiser", JSONArray().put("EG: Anmeldung, Information").put("1. OG: Kursräume 1–3").put("2. OG: Bibliothek"))
                .put("situations", situations)
        }
        if (teil == 2) {
            val situations = JSONArray()
            situations.put(JSONObject()
                .put("id", "l2-s1")
                .put("no", 1)
                .put("prompt", "Sie suchen eine Wohnung.")
                .put("correct", "A"))
            situations.put(JSONObject()
                .put("id", "l2-s2")
                .put("no", 2)
                .put("prompt", "Sie brauchen einen Sprachkurs.")
                .put("correct", "B"))
            situations.put(JSONObject()
                .put("id", "l2-s3")
                .put("no", 3)
                .put("prompt", "Sie möchten ein Fahrrad kaufen.")
                .put("correct", "C"))
            situations.put(JSONObject()
                .put("id", "l2-s4")
                .put("no", 4)
                .put("prompt", "Sie suchen einen Job.")
                .put("correct", "D"))
            situations.put(JSONObject()
                .put("id", "l2-s5")
                .put("no", 5)
                .put("prompt", "Sie brauchen einen Babysitter.")
                .put("correct", "E"))
            val ads = JSONObject().put("A", "2-Zimmer-Wohnung, zentral").put("B", "Deutschkurse am Abend").put("C", "Fahrrad zu verkaufen").put("D", "Minijob im Café").put("E", "Babysitter gesucht")
            return JSONObject()
                .put("dtz_schema", "lesen_teil2_matching")
                .put("dtz_part", "L2")
                .put("title", "Demo Lesen Teil 2")
                .put("instructions", "Ordnen Sie die Anzeigen zu.")
                .put("ads", ads)
                .put("labels", JSONArray().put("A").put("B").put("C").put("D").put("E"))
                .put("situations", situations)
        }
        if (teil == 3) {
            val blocks = JSONArray()
            blocks.put(JSONObject()
                .put("id", "l3-b1")
                .put("title", "Infoabend")
                .put("text", "Der Infoabend findet am Dienstag um 18 Uhr statt.")
                .put("true_false", JSONObject().put("statement", "Der Infoabend ist am Dienstag.").put("correct", "A"))
                .put("mc", JSONObject().put("question", "Wann beginnt der Infoabend?")
                    .put("options", JSONArray().put("18 Uhr").put("19 Uhr").put("20 Uhr"))
                    .put("correct", "A")))
            blocks.put(JSONObject()
                .put("id", "l3-b2")
                .put("title", "Bibliothek")
                .put("text", "Die Bibliothek ist am Freitag geschlossen.")
                .put("true_false", JSONObject().put("statement", "Am Freitag ist die Bibliothek geschlossen.").put("correct", "A"))
                .put("mc", JSONObject().put("question", "Wann ist geschlossen?")
                    .put("options", JSONArray().put("Freitag").put("Samstag").put("Sonntag"))
                    .put("correct", "A")))
            return JSONObject()
                .put("dtz_schema", "lesen_teil3_textblock_mix")
                .put("dtz_part", "L3")
                .put("title", "Demo Lesen Teil 3")
                .put("instructions", "Lesen Sie die Texte und beantworten Sie die Fragen.")
                .put("blocks", blocks)
        }
        if (teil == 4) {
            val statements = JSONArray()
            statements.put(JSONObject().put("id", "l4-s1").put("no", 37).put("statement", "Am Samstag ist die Bibliothek geöffnet.").put("correct", "B"))
            statements.put(JSONObject().put("id", "l4-s2").put("no", 38).put("statement", "Die Bibliothek schließt um 18 Uhr.").put("correct", "A"))
            statements.put(JSONObject().put("id", "l4-s3").put("no", 39).put("statement", "Die Bibliothek öffnet um 9 Uhr.").put("correct", "A"))
            return JSONObject()
                .put("dtz_schema", "lesen_teil4_richtig_falsch_text")
                .put("dtz_part", "L4")
                .put("title", "Demo Lesen Teil 4")
                .put("instructions", "Lesen Sie den Text und entscheiden Sie.")
                .put("text", "Die Bibliothek ist montags bis freitags von 9 bis 18 Uhr geöffnet.")
                .put("statements", statements)
        }
        if (teil == 5) {
            val gaps = JSONArray()
            gaps.put(JSONObject().put("id", "l5-g1").put("no", 40).put("options", JSONArray().put("für").put("zu").put("an")).put("correct", "A"))
            gaps.put(JSONObject().put("id", "l5-g2").put("no", 41).put("options", JSONArray().put("am").put("im").put("auf")).put("correct", "B"))
            gaps.put(JSONObject().put("id", "l5-g3").put("no", 42).put("options", JSONArray().put("bitte").put("bittet").put("gebeten")).put("correct", "A"))
            gaps.put(JSONObject().put("id", "l5-g4").put("no", 43).put("options", JSONArray().put("seit").put("vor").put("bei")).put("correct", "A"))
            gaps.put(JSONObject().put("id", "l5-g5").put("no", 44).put("options", JSONArray().put("einen").put("einem").put("einer")).put("correct", "A"))
            gaps.put(JSONObject().put("id", "l5-g6").put("no", 45).put("options", JSONArray().put("wenn").put("weil").put("dass")).put("correct", "A"))
            return JSONObject()
                .put("dtz_schema", "lesen_teil5_lueckentext")
                .put("dtz_part", "L5")
                .put("title", "Demo Lesen Teil 5")
                .put("instructions", "Schließen Sie die Lücken.")
                .put("text_template", "Sehr geehrte Damen und Herren, ich möchte ___ einen Termin vereinbaren.")
                .put("example", JSONObject().put("no", 0).put("options", JSONArray().put("gern").put("gerne").put("gernem")).put("correct", "B"))
                .put("gaps", gaps)
        }
    }
    return null
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
