# Technisches Standardprofil

Diese Konventionen sind Projektentscheidungen, keine Zusagen des Hosters. Explizite Projektvorgaben gehen vor. Bei bestehenden Projekten Abweichungen dokumentieren und nur beauftragte Anpassungen umsetzen.

## Stack und Versionen

| Bereich | Standard für neue Projekte |
| --- | --- |
| Hosting | all-inkl Webhosting mit SSH; Tarif und Zugang prüfen |
| Backend | PHP 8.5, CLI-Befehl `php85`; Framework nach Funktionsumfang und Wartbarkeit wählen und begründen |
| Datenbank | MariaDB 10.11 bei relationalem Datenbedarf; sonst entfällt |
| Frontend | Projektgeeignetes HTML/CSS/JavaScript bzw. TypeScript; kein festes Framework |
| Abhängigkeiten | Composer für PHP-Pakete; vorhandenen Frontend-Paketmanager beibehalten; Lockfiles versionieren |
| Build | Lokal oder in CI; produktionsfertiges Artefakt inklusive PHP-Abhängigkeiten und Frontend-Ausgabe |
| Übertragung | SSH/SFTP; rsync nur bei nachgewiesener Verfügbarkeit auf beiden Seiten |
| Hintergrundaufgaben | Zeitlich begrenzt über bestätigte Cron-Möglichkeiten, sofern benötigt |

Für neue Projekte PHP 8.5 als Zielversion übernehmen (Nutzervorgabe vom 2026-11-14). Verfügbarkeit am Zielaccount, Framework-Kompatibilität und [PHP-Supportstatus](https://www.php.net/supported-versions.php) prüfen. Bei Unvereinbarkeit die konkrete Abweichung benennen und eine begründete Alternative vorschlagen; den Standard nicht stillschweigend ersetzen. Build und Laufzeit abstimmen. PHP-Patches verwaltet der Hoster: beobachteten Stand dokumentieren, keine dauerhaft fixierbare Server-Patchversion versprechen. Web-PHP und CLI-PHP getrennt prüfen und den tatsächlich verwendeten CLI-Interpreter festhalten.

Laut Nutzervorgabe heißen die versionierten all-inkl-CLI-Befehle `php85`, `php84` usw. Für PHP 8.5 standardmäßig `php85` verwenden und bei Zielzugriff mit `php85 -v` prüfen. Die KAS-Web-PHP-Einstellung bleibt davon getrennt.

Für neue Projekte mit relationalem Datenbedarf MariaDB 10.11 als Zielversion übernehmen (Nutzervorgabe vom 2026-11-14). Tatsächliche MariaDB-Version am Ziel ermitteln, beispielsweise mit `SELECT VERSION();` in einer autorisierten Verbindung, und Abweichungen dokumentieren. SQL-Dialekt, Zeichensatz (für neue Datenbanken vorzugsweise `utf8mb4`) und eine verfügbare Kollation festhalten. MySQL und MariaDB nicht als identische Engines behandeln; lokal gegen eine passende Engine/Version entwickeln.

Framework-, Composer- und gegebenenfalls Node-/Paketmanager-Versionen aus Manifesten, Lockfiles und Build-Umgebung ermitteln. Neue Versionen anhand offizieller Dokumentation auf Kompatibilität prüfen. Node als Build-Werkzeug bedeutet keine Node-Laufzeit auf dem Webspace.

Docker, Rootrechte, permanente Node-/Python-Prozesse, Redis, Supervisor oder WebSocket-Dienste nicht als Standardfähigkeit voraussetzen. Benötigt das Projekt diese, Unterstützung oder ausdrücklich gewählte Alternative klären. SSH allein ist kein Nachweis dafür.

## Accountprüfung

Vorhandene Projektdaten und nicht geheime Angaben nutzen. Read-only-Serverprüfungen nur im Rahmen erlaubten Zugriffs durchführen. Ohne Zugriff offene Felder mit dem erforderlichen Prüfschritt dokumentieren:

- Tarif, SSH-Host/-Benutzer, Port, Accountbasis und zulässiges Projektverzeichnis.
- Domain/Subdomain, Webspace-Ziel, HTTPS, Web-PHP samt Extensions und Limits.
- CLI-PHP und Interpreterpfad, benötigte Werkzeuge, Datenbank-Engine/-Version und Verbindungsparameter ohne Passwort.
- Erforderliche Erweiterungen im Web-PHP und im CLI getrennt ermitteln. Die projektabhängige Prüfliste und deren Pflicht-/Optionalstatus nach [healthchecks.md](healthchecks.md) festhalten.
- Nur wenn benötigt: Cron-Ausführungsart und Intervall, Symlink-Unterstützung beim HTTP-Zugriff, SMTP und Schreibrechte.

Keine öffentlich erreichbare `phpinfo()`-Datei als bleibendes Artefakt erzeugen. Beobachtungen mit Datum dokumentieren.

## Verzeichnisstandard

`<project-root>` ist ein verifizierter absoluter Pfad innerhalb des Accounts. Keine Accountnamen oder Serverpfade erfinden.

```text
<project-root>/
├── deploy/                 # CLI-Deployer und lokale Serverkonfiguration
├── incoming/               # hochgeladene ZIPs, außerhalb aller Document Roots
├── releases/
│   └── <release-id>/
│       ├── public/          # einziger Document Root
│       ├── ...              # Anwendung und Produktionsabhängigkeiten
│       └── .env -> ../../shared/.env  # falls verwendet
├── shared/
│   ├── .env                # Produktionskonfiguration, sofern benötigt
│   ├── uploads/            # dauerhafte Nutzerdaten, sofern benötigt
│   └── logs/               # sofern dateibasierte Logs verwendet werden
└── current -> releases/<release-id>
```

KAS-Domainziel: `current/public`. Erst nach verifiziertem Symlink-/Webserververhalten als umsetzbar festlegen. Andernfalls ein konkretes alternatives Verfahren dokumentieren, etwa KAS-Zielwechsel auf `releases/<release-id>/public` mit Wartungsfenster. Keinen atomaren oder unterbrechungsfreien Wechsel ohne Nachweis versprechen.

Bei statischen Sites ausschließlich den fertigen Build in das öffentliche Release-Verzeichnis legen; PHP-Verzeichnisse entfallen. Bei Frameworks deren Einstiegspunkt, Uploads, Logs, Sessions und Cache abbilden. Nur persistente Daten teilen; inkompatible Framework-Caches nicht pauschal über Releases teilen. Konfiguration, nicht öffentlicher Quellcode und Backups müssen auch über andere Domainziele im Account unerreichbar bleiben. Öffentliche Uploads gezielt zuordnen und Skriptausführung dort verhindern.

## Deploymentvertrag

Konkrete Befehle und Arbeitsverzeichnisse aus dem tatsächlichen Projekt ableiten:

1. Aus festgelegtem Commit und Lockfiles bauen. Produktionsabhängigkeiten und Frontend-Ausgabe bereitstellen und prüfen. Keine Secrets oder lokalen Entwicklungsdaten einpacken. Release-ID und Artefaktinhalt dokumentieren.
2. Zielversionen, Extensions, Rechte und Speicher prüfen. Bei PHP tatsächliche Plattformanforderungen prüfen; `config.platform` allein ist kein Laufzeitnachweis.
3. Neues Release hochladen, Konfiguration und persistente Pfade anbinden. Aktives Release nicht schrittweise überschreiben. Schreibrechte auf benötigte Verzeichnisse begrenzen.
4. Bei Datenänderungen wiederherstellbare Sicherung und konkreten Migrationsweg vorsehen. Reihenfolge von Wartungsmodus, Migration und Umschaltung anhand Schema-Kompatibilität festlegen.
5. Release prüfen und über das bestätigte Verfahren aktivieren. HTTP-Smoke-Test einschließlich tatsächlich laufender Release-ID und mehrerer erfolgreicher Antworten sowie relevante Datenbank-/Anwendungsfunktion prüfen, ohne Secrets offenzulegen. Vorübergehende Antworten alter PHP-Worker nach Symlinkwechsel nicht als erfolgreichen neuen Deploy werten.
6. Vorheriges Release für Code-Rollback behalten. Symlink-Rückwechsel macht Migrationen nicht rückgängig. Bei inkompatiblen Schemaänderungen Wiederherstellungsweg, Schreibpause und möglichen Datenverlust beschreiben. Alte Releases erst nach erfolgreicher Prüfung gemäß festgelegter Aufbewahrung bereinigen; `shared` erhalten.

In der Entwicklung per Agent/SSH deployen, sofern Ziel und Zugang vorliegen, sonst ZIP und manuellen SSH-Befehl liefern. Nach initialem Produktivstand regulär über GitHub Actions bzw. vorhandenes CI deployen. Für neue GitHub-Projekte den Produktionsbuild an ein veröffentlichtes Release binden; bestehende bewusste Release-Trigger beibehalten. Umsetzung und Skriptvertrag stehen in [release-deployment.md](release-deployment.md). Gleichzeitige Deployments auf dasselbe Ziel ausschließen und SSH-Hostidentität prüfen.

## Offizielle Quellen

Zuletzt zur Skill-Erstellung geprüft: **2026-11-14**. Veränderliche Angaben bei konkreter Planung erneut prüfen; Accountbeobachtungen entscheiden über verfügbare Versionen und Funktionen.

- [all-inkl FAQ](https://all-inkl.com/wichtig/faq): PHP-Auswahl je Domain im KAS, MariaDB/SQLite, tarifabhängiges SSH/SFTP. Keine für alle Accounts verbindliche numerische PHP-/MariaDB-Version.
- [KAS-Domainziel](https://all-inkl.com/wichtig/anleitungen/kas/domain/edit-settings/settings-overview_503.html): Domains können auf Unterverzeichnisse zeigen.
- [SSH-Befehle](https://all-inkl.com/wichtig/anleitungen/kas/ssh/file-management/applicable-ssh-commands_281.html): Ausgangspunkt zur Werkzeugprüfung, keine Zusage eigener Serverdienste.
- [Tarifübersicht](https://all-inkl.com/webhosting): Ausstattung des konkreten Tarifs prüfen.
