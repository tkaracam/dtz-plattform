# DTZ-LIB Betrieb-Checkliste

## Täglich (vor Unterricht)
1. `https://www.dtz-lib.com/admin.html` öffnen und anmelden.
2. `Virtueller Saal` erstellen (Titel, Dauer, Aufgabe).
3. Raumcode kopieren und an Lernende senden.

## Während Unterricht
1. `Live laden` klicken.
2. Optional `Auto-Refresh: An` aktivieren.
3. Mit Filtern arbeiten:
   - `Nur nicht abgegeben`
   - `Nur unbewertet`

## Nach Unterricht
1. Punkte/Note/Lehrkraft-Notiz eintragen.
2. `Alle Bewertungen speichern` klicken.
3. `Abgaben als CSV` exportieren.

## Wöchentlich
1. Archiv-CSV exportieren (Briefe/BSK/Raum-Abgaben).
2. Storage sichern (`api/storage`).
3. Audit Log prüfen.

## Schnell-Fehlerbehebung
- Admin-Login geht nicht:
  - Render `ADMIN_PANEL_PASSWORD` prüfen
  - `Save Changes` + `Deploy latest commit`
- Seite lädt alt:
  - Hard refresh (`Cmd+Shift+R`)
  - Inkognito testen
- Domainproblem:
  - zuerst `https://www.dtz-lib.com` testen

## Referenz-URLs
- Schüler: `https://www.dtz-lib.com/index.html`
- Admin: `https://www.dtz-lib.com/admin.html`
- Render Service: `https://dashboard.render.com`
