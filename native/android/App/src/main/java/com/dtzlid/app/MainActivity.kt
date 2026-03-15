package com.dtzlid.app

import android.os.Bundle
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { AppRoot() }
    }
}

@Composable
fun AppRoot() {
    AndroidView(factory = { ctx ->
        WebView(ctx).apply {
            webViewClient = WebViewClient()
            loadUrl("https://dtz-lid.com/index.html")
        }
    })
}

@Composable
fun WelcomeScreen(onMember: () -> Unit, onInternal: () -> Unit) {
    Scaffold { pad ->
        Column(modifier = Modifier.padding(pad).padding(16.dp)) {
            Text("DTZ-LID edu", style = MaterialTheme.typography.headlineMedium)
            Text("Bitte wählen Sie den Bereich", style = MaterialTheme.typography.bodyMedium)
            Spacer(Modifier.height(16.dp))
            Button(onClick = onMember, modifier = Modifier.fillMaxWidth()) { Text("Mitgliedsbereich") }
            Spacer(Modifier.height(8.dp))
            OutlinedButton(onClick = onInternal, modifier = Modifier.fillMaxWidth()) { Text("Intern (Kurs/Lehrkraft)") }
        }
    }
}

object Api {
    private val client = OkHttpClient()
    private const val baseUrl = "https://dtz-lid.com/"

    suspend fun post(path: String, payload: JSONObject): JSONObject = withContext(Dispatchers.IO) {
        val req = Request.Builder()
            .url(baseUrl + path)
            .post(payload.toString().toRequestBody("application/json".toMediaType()))
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string() ?: "{}"
            return@withContext JSONObject(body)
        }
    }
}

@Composable
fun MemberAuthScreen() {
    var regUser by remember { mutableStateOf("") }
    var regPass by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("") }

    Column(Modifier.padding(16.dp)) {
        Text("Registrierung", style = MaterialTheme.typography.titleMedium)
        OutlinedTextField(value = regUser, onValueChange = { regUser = it }, label = { Text("Benutzername") })
        OutlinedTextField(value = regPass, onValueChange = { regPass = it }, label = { Text("Passwort") }, visualTransformation = PasswordVisualTransformation())
        Button(onClick = {
            status = "Registrierung senden" // placeholder
        }) { Text("Jetzt registrieren") }
        if (status.isNotEmpty()) Text(status)
    }
}
