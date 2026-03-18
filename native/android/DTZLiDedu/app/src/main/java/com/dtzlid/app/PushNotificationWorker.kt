package com.dtzlid.app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.webkit.CookieManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class PushNotificationWorker(
    appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        ensureChannel(applicationContext)

        val cookies = CookieManager.getInstance().getCookie(BASE_URL) ?: return Result.success()
        if (!cookies.contains("PHPSESSID=", ignoreCase = true)) {
            return Result.success()
        }

        val prefs = applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

        runCatching {
            val hwJson = getJson("api/student_homework_current.php", cookies)
            val hasAssignment = hwJson.optBoolean("has_assignment", false)
            val assignment = hwJson.optJSONObject("assignment")
            val state = hwJson.optJSONObject("state")
            if (hasAssignment && assignment != null) {
                val assignmentId = assignment.optString("id")
                val assignmentTitle = assignment.optString("title").ifBlank { "Neue Hausaufgabe" }
                val lastAssignmentId = prefs.getString("last_assignment_id", "") ?: ""
                if (assignmentId.isNotBlank() && assignmentId != lastAssignmentId) {
                    notify(
                        id = 1001,
                        title = "Neue Hausaufgabe",
                        text = assignmentTitle
                    )
                    prefs.edit().putString("last_assignment_id", assignmentId).apply()
                }

                if (state != null) {
                    val reminderLevel = state.optString("reminder_level")
                    if (reminderLevel == "warn24" || reminderLevel == "warn2") {
                        val reminderKey = "$assignmentId|$reminderLevel"
                        val lastReminderKey = prefs.getString("last_reminder_key", "") ?: ""
                        if (assignmentId.isNotBlank() && reminderKey != lastReminderKey) {
                            val label = state.optString("reminder_label").ifBlank { "Abgabe-Erinnerung" }
                            notify(
                                id = 1002,
                                title = "Hausaufgabe Erinnerung",
                                text = "$assignmentTitle - $label"
                            )
                            prefs.edit().putString("last_reminder_key", reminderKey).apply()
                        }
                    }
                }
            }
        }

        runCatching {
            val portalJson = getJson("api/student_portal.php", cookies)

            val notes = portalJson.optJSONArray("teacher_notes")
            if (notes != null && notes.length() > 0) {
                val latest = notes.optJSONObject(0)
                val noteId = latest?.optString("id").orEmpty()
                val noteText = latest?.optString("note").orEmpty()
                val lastNoteId = prefs.getString("last_note_id", "") ?: ""
                if (noteId.isNotBlank() && noteId != lastNoteId) {
                    notify(
                        id = 1003,
                        title = "Dozent mesajı",
                        text = noteText.ifBlank { "Yeni bir dozent notu var." }
                    )
                    prefs.edit().putString("last_note_id", noteId).apply()
                }
            }

            val corrections = portalJson.optJSONArray("letter_corrections")
            if (corrections != null && corrections.length() > 0) {
                val latest = corrections.optJSONObject(0)
                val uploadId = latest?.optString("upload_id").orEmpty()
                val topic = latest?.optString("topic").orEmpty()
                val lastUploadId = prefs.getString("last_correction_upload_id", "") ?: ""
                if (uploadId.isNotBlank() && uploadId != lastUploadId) {
                    notify(
                        id = 1004,
                        title = "Brief bewertet",
                        text = topic.ifBlank { "Bir mektubun değerlendirildi." }
                    )
                    prefs.edit().putString("last_correction_upload_id", uploadId).apply()
                }
            }
        }

        return Result.success()
    }

    private fun getJson(path: String, cookies: String): JSONObject {
        val req = Request.Builder()
            .url("$BASE_URL$path")
            .header("Cookie", cookies)
            .header("Accept", "application/json")
            .get()
            .build()
        client.newCall(req).execute().use { res ->
            val body = res.body?.string().orEmpty()
            return JSONObject(if (body.isBlank()) "{}" else body)
        }
    }

    private fun notify(id: Int, title: String, text: String) {
        val intent = Intent(applicationContext, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            applicationContext,
            id,
            intent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

        val notification = NotificationCompat.Builder(applicationContext, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(title)
            .setContentText(text)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()

        NotificationManagerCompat.from(applicationContext).notify(id, notification)
    }

    companion object {
        private const val WORK_NAME = "dtz_push_poll_worker"
        private const val CHANNEL_ID = "dtz_study_alerts"
        private const val CHANNEL_NAME = "DTZ Bildirimleri"
        private const val PREFS = "dtz_push_state"
        private const val BASE_URL = "https://dtz-lid.com/"
        private val client = OkHttpClient()

        fun ensureScheduled(context: Context) {
            ensureChannel(context)
            val request = PeriodicWorkRequestBuilder<PushNotificationWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }

        private fun ensureChannel(context: Context) {
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
                return
            }
            val channel = NotificationChannel(
                CHANNEL_ID,
                CHANNEL_NAME,
                NotificationManager.IMPORTANCE_DEFAULT
            )
            val manager = context.getSystemService(NotificationManager::class.java)
            manager?.createNotificationChannel(channel)
        }
    }
}

