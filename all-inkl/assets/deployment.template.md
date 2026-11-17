# all-inkl Deploymentprofil

## 1. Projekt und Status

- Projekt / Zweck:
- Stand / geprüfter Commit:
- Profilstatus: Entwurf | lokal vorbereitet | am Ziel geprüft
- Projektphase: Entwicklung | initiale Produktivsetzung | CI-Betrieb
- Initialer Produktivstand: Release-ID, Datum und Healthcheck-Ergebnis oder noch ausstehend
- Hosting: all-inkl Webhosting mit SSH
- Umgebung(en):
- Abweichungen vom Standard und Begründung:

## 2. Stack und Versionen

Einträge als **bestätigt**, **vorgeschlagen**, **offen** oder **entfällt** kennzeichnen. Bei Versionen Quelle und Prüfdatum angeben; PHP-Minor-Ziel und beobachteten Patchstand unterscheiden. Vorausgefüllte Ziele gelten für neue Projekte; bei bestehenden Projekten deren Vorgaben übernehmen. Eine gewählte Zielversion bestätigt noch nicht den tatsächlichen Serverstand.

| Komponente | Auswahl / Zielversion | Tatsächlich ermittelt | Status / Quelle / Prüfdatum |
| --- | --- | --- | --- |
| Backend-Sprache / Web-PHP | PHP 8.5 | offen | Nutzervorgabe 2026-11-14; Zielprüfung offen |
| CLI-PHP und Interpreterpfad | PHP 8.5; `php85` | offen | Nutzervorgabe 2026-11-14; mit `php85 -v` prüfen |
| Framework | | | |
| Datenbank-Engine / Version | MariaDB 10.11, sofern benötigt | offen | Nutzervorgabe 2026-11-14; Zielprüfung offen |
| Zeichensatz / Kollation | | | |
| Composer | | | |
| Frontend-Technik | | | |
| Node / Paketmanager (nur Build) | | | |
| PHP-Extensions / relevante Limits | | | |

## 3. Hosting und Zugänge

Tarif, SSH-Verbindungsprofil, Domain, HTTPS, Accountbasis, Datenbankhost/-name und benötigte Cron-Fähigkeiten dokumentieren. Nur Variablennamen bzw. Verweise auf Secret-Verwaltung, keine Passwörter oder privaten Schlüssel. Staging und Produktion nur soweit vorgesehen aufführen.

## 4. Projekt- und Serverstruktur

Relevanten Projektbaum und konkreten Serverbaum angeben. Document Root/KAS-Ziel, Release-ID-Schema, persistente Pfade, schreibbare Verzeichnisse und Umschaltverfahren festhalten. Verifizierungsstatus für Symlinks und HTTP-Zugriff nennen.

## 5. Konfiguration und persistente Daten

| Variable / Datenpfad | Zweck | Bereitstellung | Secret? / persistent? |
| --- | --- | --- | --- |

Uploads, private Daten, Sessions und Cache soweit benötigt behandeln. Schutz vor öffentlichem Zugriff und Überschreiben beim Deployment beschreiben.

## 6. Build und Artefakt

Arbeitsverzeichnis, Installations-/Build-/Prüfbefehle, Lockfiles, enthaltene Produktionsdateien und ausgeschlossene Dateien aufführen. Reproduzierbaren Commit und Artefaktbezeichnung festlegen. Verbindliche Quelle der Anwendungsversion und Übergabe per `--version` an den Packer dokumentieren; ohne Versionsquelle `version: null`. `deployment.json` aus dem Build ausschließen.

## 7. Deploymentablauf

Entwicklungsweg (Agent bei hinterlegtem SSH-Zugang, sonst manueller ZIP-Upload), initiale Produktivsetzung und anschließenden CI-Weg dokumentieren. Konkreten Paketierungsbefehl, Uploadziel und einzelnen SSH-Befehl zur manuellen Aktivierung angeben. Für CI Buildjob, Artefaktübergabe, Release-Trigger, Secrets und Zielumgebung benennen.

Vorprüfung, Upload, Konfiguration, gegebenenfalls Migration, Umschaltung und Smoke-Test in projektspezifischer Reihenfolge aufführen. Health-URL, Metadatenfelder `release`, `version`, `deployed_at` und begrenzte Wiederholungen bei verzögert aktualisierten PHP-Workern festhalten. Gleiche ZIP-/Aktivierungslogik für alle Wege verwenden, gleichzeitige Deployments verhindern und ungeprüfte Befehle kennzeichnen. Einen reinen Workflow-Entwurf nicht als eingerichteten CI-Betrieb melden.

## 8. Datenbank, Backup und Rollback

Migrationen, Backup-Ort außerhalb öffentlicher Pfade, Wiederherstellung, vorheriges Release und Abbruchbedingungen beschreiben. Code-Rollback und Datenbankwiederherstellung getrennt behandeln; Auswirkungen auf zwischenzeitliche Schreibzugriffe festhalten. Ohne Datenbank: entfällt.

## 9. Prüfung und offene Punkte

Projektabhängige Healthchecks festlegen: erforderliche PHP-Erweiterungen, optionale Hinweise, gegebenenfalls unterstützte Alternativen, Datenbank-`SELECT 1` und benötigte beschreibbare Verzeichnisse. Für jede Pflicht den Bezug zu Projektfunktion oder Produktionsabhängigkeit angeben. Pflichtfehler liefern HTTP 503, optionale Fehlschläge nur Warnungen. Web-PHP-Ergebnisse getrennt von CLI-/Composer-Prüfungen dokumentieren. Keine Secrets, absoluten Pfade oder Exceptiondetails in der öffentlichen Healthantwort.

Anwendungsversion aus dem Artefakt und `deployed_at` aus der Serveraktivierung prüfen. Unbekannte Angaben bleiben `null`; `checked_at` ist kein Deploymentdatum. Rollback/Reaktivierung erneuern Zeitpunkt und interne Cache-Identität. Bei bestehenden Projekten Build, serverseitigen Deployer und Healthroute gemeinsam aktualisieren.

Basic-Auth-Einrichtung, geschützte Serverdateien für Hash und Prüfclient sowie gemeinsamen Health-Cache dokumentieren. Standardintervall zehn Sekunden, optional eine Sekunde. Authentifizierung vor Prüfungen/Cachezugriff; auch Fehler cachen. Deploymentserie anhand verschiedener echter `check_id`-Werte prüfen. Gleichzeitige Requests, HTTP 401 ohne Zugang und Cache-Ablauf testen; keine Passwörter im Testprotokoll festhalten.

| Prüfung / offene Angabe | Ergebnis / Status | Nachweis oder nächster konkreter Schritt |
| --- | --- | --- |

Lokale Prüfungen und Zielprüfungen unterscheiden. Ohne Zielprüfung keine erfolgreiche Deploymentbereitschaft behaupten.

## 10. Quellen und Entscheidungen

Relevante Projektdateien, Accountbeobachtungen, offizielle Dokumentationslinks und Prüfdatum nennen. Framework, Versionen und Abweichungen knapp begründen.
