package com.dtzlid.app

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

data class NotificationHistoryItem(
    val id: String,
    val title: String,
    val body: String,
    val category: String,
    val deepLinkUrl: String,
    val createdAt: Long
)

object NotificationCenter {
    const val INTENT_EXTRA_DEEP_LINK_URL = "deep_link_url"
    private const val PREFS = "dtz_notification_center"
    private const val KEY_ITEMS = "items"
    private const val MAX_ITEMS = 50

    fun append(
        context: Context,
        title: String,
        body: String,
        category: String,
        deepLinkUrl: String
    ) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val existing = list(context).toMutableList()
        val item = NotificationHistoryItem(
            id = "n-${System.currentTimeMillis()}-${(0..9999).random()}",
            title = title,
            body = body,
            category = category,
            deepLinkUrl = deepLinkUrl,
            createdAt = System.currentTimeMillis()
        )
        existing.add(0, item)
        val trimmed = existing.take(MAX_ITEMS)
        val arr = JSONArray()
        for (row in trimmed) {
            arr.put(
                JSONObject()
                    .put("id", row.id)
                    .put("title", row.title)
                    .put("body", row.body)
                    .put("category", row.category)
                    .put("deep_link_url", row.deepLinkUrl)
                    .put("created_at", row.createdAt)
            )
        }
        prefs.edit().putString(KEY_ITEMS, arr.toString()).apply()
    }

    fun list(context: Context): List<NotificationHistoryItem> {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val raw = prefs.getString(KEY_ITEMS, "[]") ?: "[]"
        val arr = runCatching { JSONArray(raw) }.getOrElse { JSONArray() }
        val out = mutableListOf<NotificationHistoryItem>()
        for (i in 0 until arr.length()) {
            val row = arr.optJSONObject(i) ?: continue
            out += NotificationHistoryItem(
                id = row.optString("id"),
                title = row.optString("title"),
                body = row.optString("body"),
                category = row.optString("category"),
                deepLinkUrl = row.optString("deep_link_url"),
                createdAt = row.optLong("created_at")
            )
        }
        return out
    }
}

