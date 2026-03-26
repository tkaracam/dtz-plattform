# DTZ Plattform

DTZ-LIB Webanwendung mit Schüler-Login, Schreiben-Korrektur, LiD-Übungen, Sprechen-Training, Simulation und virtuellem Saal.

## Projektstruktur

- `index.html` – Schüleroberfläche
- `admin.html` – Lehrerbereich
- `api/` – PHP-Endpunkte
- `api/storage/` – Laufzeitdaten (Briefe, BSK-Archiv, Simulation, virtuelle Räume)
- `assets/` – Fonts/Logos/Bilder
- `tools/` – Wartungsskripte

## Konfiguration

Die App nutzt Umgebungsvariablen:

- `OPENAI_API_KEY`
- `OPENAI_MODEL` (Default: `gpt-4.1-mini`)
- `ADMIN_PANEL_USERNAME` (Default: `admin`)
- `ADMIN_PANEL_PASSWORD`
- `BSK_MODULE_ENABLED` (Default: `0`, BSK bleibt archiviert/passiv)

Optional lokal:

- `api/config.php` (wird per `.gitignore` nicht versioniert)
- Beispiel: `api/config.example.php`

## Lokales Hosting (PHP 8.2+)

Beispiel mit PHP Built-in Server:

```bash
php -S 127.0.0.1:8080 -t .
```

Oder direkt mit Hilfsskript:

```bash
chmod +x tools/start-local.sh
./tools/start-local.sh
```

Dann öffnen:

- `http://127.0.0.1:8080/index.html`
- `http://127.0.0.1:8080/admin.html`

Das Skript zeigt zusätzlich die LAN-URL für iPhone/Safari im selben WLAN an.

## Deploy auf Render (Docker)

Dieses Repo enthält bereits:

- `Dockerfile`
- `docker/entrypoint.sh`
- `docker/apache-site.conf`
- `render.yaml`

### Schritte

1. Repo nach GitHub pushen.
2. In Render: **New + Blueprint** und das Repo verbinden.
3. Render liest `render.yaml` automatisch.
4. In Render Service Settings die Secrets setzen:
   - `OPENAI_API_KEY`
   - `ADMIN_PANEL_USERNAME`
   - `ADMIN_PANEL_PASSWORD`
5. Deploy starten.

## Rollen im Lehrerbereich

- **Haupt-Admin (owner):** Login mit `ADMIN_PANEL_USERNAME` + `ADMIN_PANEL_PASSWORD`, volle Rechte.
- **Dozent:** Wird im Bereich **Lehrkräfte** angelegt und bekommt einen eigenen Lehrerzugang.
- Dozent-Zugriffe werden auf eigene Kurse/Benutzer eingeschraenkt.

## Deploy sonrası sağlık testi

Her deploy sonrası kritik kontrolleri tek komutla çalıştır:

```bash
chmod +x tools/healthcheck.sh
./tools/healthcheck.sh https://dtz-lid.com
```

İstersen base URL vermezsen script varsayılan olarak `https://dtz-lid.com` kullanır.

### Otomatik (GitHub Actions)

`main` branch’e her push sonrası otomatik healthcheck çalışır:

- Workflow: `Post-Deploy Healthcheck`
- Dosya: `.github/workflows/post-deploy-healthcheck.yml`
- Varsayılan hedef: `https://dtz-lid.com`
- Deploy ısınması için başlangıç beklemesi + retry mekanizması içerir.

Manuel tetikleme için:

1. GitHub -> `Actions` -> `Post-Deploy Healthcheck`
2. `Run workflow`
3. Gerekirse `base_url` ve `warmup_seconds` alanlarını değiştir

## Prod Sağlamlık ve Test Planı

- Prod checklist: `docs/PROD_SAGLAMLIK_CHECKLIST.md`
- Otomatik test planı: `docs/AUTOMATIK_TEST_PLAN.md`

### CI Smoke (lokal veya prod URL)

```bash
BASE_URL="https://dtz-lid.com" ./tools/ci_smoke.sh
```

Auth smoke dahil çalıştırmak için:

```bash
BASE_URL="https://dtz-lid.com" \
ADMIN_USER="..." ADMIN_PASS="..." \
STUDENT_USER="..." STUDENT_PASS="..." \
./tools/ci_smoke.sh
```

### Persistent Storage

`render.yaml` bindet eine Disk auf `/var/data`.
Beim Start wird `api/storage` automatisch auf `/var/data/storage` verlinkt.
Damit bleiben Schülerdaten, Räume und Archive über Deploys hinweg erhalten.

## Sicherheit

- Keine Secrets in HTML/JS ablegen.
- `api/storage/.htaccess` sperrt Direktzugriff.
- HTTPS erzwingen (Render standardmäßig via TLS).
- Session + Rate-Limits sind aktiv.

## Hinweis zu Urheberrecht

Die Plattform soll nur eigene, freie oder lizenzierte Inhalte nutzen.
Offizielle Prüfungsaufgaben nicht 1:1 kopieren und verteilen.
