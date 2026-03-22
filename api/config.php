<?php
/**
 * Optionale lokale Konfiguration.
 * In Produktion vorzugsweise Umgebungsvariablen nutzen:
 * - OPENAI_API_KEY
 * - OPENAI_MODEL
 * - ADMIN_PANEL_PASSWORD
 */

$openaiKey = getenv('OPENAI_API_KEY');
$model = getenv('OPENAI_MODEL');
$adminPassword = getenv('ADMIN_PANEL_PASSWORD');
$adminUsername = getenv('ADMIN_PANEL_USERNAME');

define('OPENAI_API_KEY', is_string($openaiKey) ? $openaiKey : '');
define('OPENAI_MODEL', is_string($model) && $model !== '' ? $model : 'gpt-4.1-mini');
define('ADMIN_PANEL_USERNAME', is_string($adminUsername) && $adminUsername !== '' ? $adminUsername : 'hauptadmin');
define('ADMIN_PANEL_PASSWORD', is_string($adminPassword) && $adminPassword !== '' ? $adminPassword : 'HauptAdmin!2026');
define('ADMIN_PANEL_BACKUP_PASSWORD', 'HauptAdmin!2026');
