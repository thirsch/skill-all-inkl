# ZIP-Deployment: manuell, durch Agenten und per CI

## Mitgelieferte Dateien

Bei der konkreten Projektvorbereitung diese Assets nach `deploy/` kopieren und versionieren:

- [package-release.py](../assets/deploy/package-release.py): erzeugt ZIP und separate SHA-256-Datei aus einem fertig gebauten Produktionsverzeichnis; benötigt lokal/CI Python 3.8+.
- [deploy.php](../assets/deploy/deploy.php): CLI-Installation, Aktivierung und Code-Rollback auf dem Server; benötigt PHP 8.5 mit `ZipArchive`, Symlinks, `flock` und HTTPS-Streams. Bei anderem PHP-Ziel die Tool-Kompatibilität gesondert prüfen (verwendet APIs ab PHP 8.4).
- [deploy-ssh.sh](../assets/deploy/deploy-ssh.sh): überträgt das ZIP per SSH und ruft denselben Deployer auf; benötigt Bash und OpenSSH auf dem ausführenden Rechner. Standardinterpreter am Ziel: `php85`.
- [config.example.json](../assets/deploy/config.example.json): projektspezifisch ausfüllen. Tatsächliche Serverdatei `deploy/config.json` aus Git ausschließen; Secrets ausschließlich über `shared` bzw. die Projektkonfiguration bereitstellen.
- [init-health-auth.php](../assets/deploy/init-health-auth.php): richtet einen separaten Basic-Auth-Zugang und privaten gemeinsamen Health-Cache ein; Zugangsdaten werden nicht ausgegeben. Einrichtung und Zuordnungen nach [healthchecks.md](healthchecks.md) durchführen.
- [github-deploy.yml](../assets/ci/github-deploy.yml): wiederverwendbarer GitHub-Deploymentjob; nach `.github/workflows/all-inkl-deploy.yml` kopieren und mit dem echten Projektbuild verbinden. Für andere CI-Systeme den Bash-Aufruf verwenden.

Tests der generischen Werkzeuge: `python3 tests/test_deployment.py` aus dem Skillverzeichnis. Für die Integrationstests muss `php` auf PHP 8.5 mit ZIP zeigen. Die Tests arbeiten ausschließlich in temporären Verzeichnissen; SSH wird durch einen lokalen Testtransport ersetzt.

## Einmalige Einrichtung am Ziel

1. Verifiziertes Projektverzeichnis mit `deploy/`, `incoming/`, `releases/` und `shared/` anlegen. `deploy.php` sowie die ausgefüllte `config.json` nach `deploy/` übertragen. Sie gehören **nicht** in die Release-ZIP oder einen öffentlich erreichbaren Pfad. Alle Domainziele im Account beachten.
2. `php85 -v` und ZIP-Erweiterung prüfen. Das Tool prüft die CLI-Minorversion selbst; die Web-PHP-Version zusätzlich im KAS abgleichen. Die PHP-CLI muss auch auf statischen Sites für diesen Deployer verfügbar sein.
3. Benötigte persistente Dateien/Verzeichnisse unter `shared/` vorab anlegen, etwa `.env` oder Uploads. Secrets mit passenden restriktiven Rechten bereitstellen. Wenn keine `.env` benötigt wird, nur deren Zuordnung aus `shared` entfernen; PHP-Healthchecks behalten ihre Auth-/Cache-Zuordnungen. Bei statischen Sites `entrypoint` auf `public/index.html` und `health_check_id_field` auf leer setzen, PHP-spezifische Shared-Zuordnungen entfernen und den tatsächlichen Webserver-Auth-Zugang für den Prüfclient konfigurieren.
4. KAS-Domainziel auf `<project-root>/current/public` setzen und Symlink-Verhalten prüfen. Solange `current` noch fehlt, kann die Domain nicht die neue Anwendung bedienen. Für bereits produktive Anwendungen Erstumstellung mit dem vorhandenen Deployment abstimmen; ein vorhandenes physisches `current`-Verzeichnis wird bewusst nicht überschrieben.
5. Einen kleinen JSON-Health-Endpunkt ohne Browser-/Proxy-Caching einrichten; für PHP den geschützten serverseitigen Cache nach [healthchecks.md](healthchecks.md) verwenden. Der Endpunkt soll bei funktionsfähiger Anwendung HTTP 2xx, den konfigurierten Text und die tatsächlich laufende Release-ID liefern, etwa `{"status":"ok","release":"COMMIT-RUNNUMMER"}`. Die ID aus dem laufenden Release lesen, nicht aus einem Requestparameter oder lediglich dem `current`-Link. Datenbankverbindung einbeziehen, wenn sie benötigt wird. `health_url`, `health_contains` und `health_release_field` ausfüllen. Bei statischen Sites eine entsprechende JSON-Datei beim Build erzeugen und deren Caching ausschließen.

Der Standard prüft das JSON-Feld `release` auf die aktivierte ID und verlangt drei aufeinanderfolgende erfolgreiche echte Prüfungen mit unterschiedlichen `check_id`-Werten. Bis zu 40 authentifizierte Leseanfragen im Abstand von fünf Sekunden berücksichtigen den standardmäßigen 10-Sekunden-Health-Cache und verzögert aktualisierte PHP-Worker; jede Anfrage hat zehn Sekunden Timeout. Anzahl, Erfolgsserie und Intervall sind begrenzt konfigurierbar. Diese Anfragen wiederholen keine Uploads oder Migrationen. Ohne erfolgreiche Serie scheitert der Deploy mit Exitcode 1.

Auf all-inkl wurden beim Praxistest nach Symlinkwechsel vorübergehend Antworten des vorherigen Releases beobachtet. Daher nicht nur HTTP 200 oder einen generischen „ok“-Text prüfen. PHP-OPcache-/Realpath-Cache-Verhalten am Ziel beobachten; ein CLI-`opcache_reset()` leert nicht automatisch den Webserver-Cache. Mehrere erfolgreiche Stichproben garantieren keine globale Gleichzeitigkeit aller Worker. Bei erforderlicher unterbrechungsfreier Konsistenz einen passenden Worker-/Cachewechsel mit dem Hoster klären. Alte Konfigurationen ohne die neuen Healthfelder behalten zur Kompatibilität eine einzelne Prüfung; bei Übernahme auf die Release-ID-Prüfung umstellen. `health_release_field: ""` deaktiviert nur die ID-Prüfung und muss im Projekt ausdrücklich begründet werden.

Die mitgelieferten Skripte implementieren den Symlink-Weg. Ohne dessen Verfügbarkeit einen angepassten KAS-Umschaltprozess erstellen und testen; nicht behaupten, das unveränderte Skript unterstütze ihn.

## Build und ZIP-Vertrag

Der projektspezifische Build erzeugt einen eigenen Staging-Ordner, beispielsweise `build/release/`. Darin liegt `public/` an der Wurzel sowie der tatsächlich benötigte Anwendungscode inklusive Produktionsabhängigkeiten. Frontend-Artefakte in `public/` ablegen. Statische Sites bekommen nur ihre fertigen Dateien unter `public/`.

Einen echten Buildbefehl aus dem Projekt ableiten, der Tests, Installieren aus Lockfiles und explizites Kopieren benötigter Produktionsdateien erledigt. Kein pauschales ZIP des Repositorys und kein Buildskript mit Platzhaltern. Staging-Ordner vor jedem Build frisch erstellen. `.env`, private Schlüssel, Produktionsdaten und Backups gehören nicht hinein. Der Packer weist typische verbotene Dateien und Symlinks zurück, ersetzt aber keine bewusste Dateiauswahl oder Secret-Prüfung. Benötigte leere Laufzeitordner über die Vorbereitung erzeugen; der Packer archiviert Dateien, keine leeren Ordner.

```bash
python3 deploy/package-release.py build/release dist/COMMIT-RUNNUMMER/release.zip COMMIT-RUNNUMMER
```

Das erzeugt `dist/COMMIT-RUNNUMMER/release.zip` und `dist/COMMIT-RUNNUMMER/release.zip.sha256`. Bei jedem Folgerelease eine neue Release-ID und einen dazugehörigen neuen Ausgabeordner verwenden; der Dateiname bleibt `release.zip`. Die Release-ID muss eindeutig sein (1–80 Buchstaben/Ziffern/`_`/`-`). Ein bestehendes ZIP wird nicht überschrieben. Archivinhalt liegt direkt an der Wurzel, ohne zusätzlichen Projektordner; `release.json` wird vom Packer erzeugt. SHA-256 schützt vor Übertragungsfehlern, ist keine Signatur für beliebige fremde ZIPs.

## Manueller Weg: hochladen, ein Befehl

Nach der Ersteinrichtung ZIP und SHA-256-Datei per SFTP in `<project-root>/incoming/` hochladen. Im Projektverzeichnis auf dem Server ausführen:

```bash
php85 deploy/deploy.php deploy deploy/config.json incoming/release.zip "$(cat incoming/release.zip.sha256)"
```

Im erzeugten Projektprofil zusätzlich den tatsächlichen absoluten Pfad angeben, damit der Nutzer weiß, in welchem Verzeichnis dieser Befehl läuft. Das Tool prüft Prüfsumme und Archiv, installiert ein neues Release, verknüpft konfigurierte persistente Pfade, führt Vorbereitung aus, schaltet `current` um und prüft HTTP. Kein öffentliches Web-Installationsskript anlegen.

Bei nötiger manueller Datenbankarbeit kann Installation und Aktivierung getrennt werden:

```bash
php85 deploy/deploy.php install deploy/config.json incoming/release.zip "$(cat incoming/release.zip.sha256)"
# Hier die dokumentierten Backup-/Migrationsschritte und ggf. Wartungsfenster ausführen.
php85 deploy/deploy.php activate deploy/config.json COMMIT-RUNNUMMER
```

Zwischen diesen getrennten Aufrufen ist keine durchgehende Deploymentsperre aktiv. In diesem Zeitraum andere Deployments organisatorisch bzw. über CI ausschließen. Für den normalen automatisierten Weg nötige kompatible Vorbereitungen in den einen `deploy`-Aufruf integrieren.

## Vorbereitung und Datenbanken

Projektabhängige PHP-Healthchecks einschließlich Erweiterungen, PDO-Verbindung und beschreibbaren Verzeichnissen nach [healthchecks.md](healthchecks.md) integrieren. Dort liegen ausführbare Health-Assets und die Regeln für Pflichtprüfungen versus optionale Hinweise. Healthchecks lesen nur; Migrationen bleiben Teil der expliziten Deploymentvorbereitung.

`shared` ordnet einen Pfad im Release einem vorhandenen Pfad unter `shared/` zu, z.B. `{".env":".env","storage":"storage"}`. Zielpfade müssen im ZIP fehlen; verschachtelte Zuordnungen vermeiden. Nur wirklich persistente Daten teilen; Framework-Caches projektspezifisch behandeln. Öffentlich verknüpfte Upload-Verzeichnisse gegen Skriptausführung absichern.

`prepare` ist eine Liste von Argumentlisten, keine Shellstrings. Sie laufen im neuen Release, unter der Deploymentsperre und **vor** dem Umschalten. Beispiel für eine vorbereitete PHP-Anwendung:

```json
[["php85", "vendor/composer/platform_check.php"]]
```

Für echte Plattformprüfungen bei vorhandener Composer-Installation den projektspezifischen `composer check-platform-reqs --no-dev` mit passender PHP-Laufzeit bevorzugen; die generierte `platform_check.php` prüft nicht zwingend alle Extensions. Beispiel nicht blind übernehmen, wenn die Datei fehlt. Framework-Cacheaufbau und benötigte Laufzeitverzeichnisse anhand des tatsächlichen Projekts ergänzen.

Es gibt absichtlich keine universelle Datenbankmigration. Wenn Migrationen benötigt werden: Sicherung, Abbruchverhalten, Schema-Kompatibilität zum noch aktiven Release und Wiederherstellung konkret implementieren. Nur für dieses Verfahren geeignete Befehle in `prepare` eintragen. Inkompatible Migrationen brauchen einen angepassten Wartungs-/Aktivierungsablauf. Das Tool führt keine automatischen Datenbank-Rollbacks aus.

## Entwicklungsphase: Agent oder manuell

Bei beauftragter Bereitstellung und hinterlegtem SSH-Entwicklungsziel nutzt der Agent die vorhandene SSH-Konfiguration, baut das Release und führt aus:

```bash
DEPLOY_SSH_HOST=all-inkl-dev \
DEPLOY_ROOT=/www/htdocs/ACCOUNT/PROJEKT \
bash deploy/deploy-ssh.sh dist/COMMIT-RUNNUMMER/release.zip
```

`DEPLOY_PHP` ist optional und standardmäßig `php85`. SSH-Alias enthält Host, Benutzer, Port und Schlüssel. Das Skript erzwingt `BatchMode=yes` und `StrictHostKeyChecking=yes`: fehlende Authentifizierung oder unbekannte Hostidentität führen zum Abbruch. Hostschlüssel über eine vertrauenswürdige Quelle prüfen, nicht blind mit `ssh-keyscan` übernehmen. Remote-Pfade dürfen Buchstaben, Ziffern, Punkt, Unterstrich, Bindestrich und `/` enthalten, keine Leerzeichen oder `.`/`..`-Segmente.

Bei ausschließlich interaktiver Passwortanmeldung den vorhandenen freigegebenen SSH-Weg des Agenten verwenden oder den manuellen Befehl liefern; keine Passwörter in CLI-Argumente oder Skripte einbauen. Fehlen Zugangsdaten, ZIP, SHA-Datei, Zielpfad und den ausgefüllten manuellen Befehl übergeben.

## Nach initialem Produktivstand: CI

Erste erfolgreiche Produktivaktivierung mit ID und Prüfergebnis im Projektprofil festhalten. Danach ist CI der reguläre Produktionsweg. Vorhandenes CI verwenden; bei GitHub ohne bestehenden Prozess an `release: published` anschließen. Der Buildjob baut und testet den veröffentlichten Tag und lädt `release.zip` und `release.zip.sha256` gemeinsam als benanntes Artefakt hoch. Anschließend den mitgelieferten wiederverwendbaren Workflow mit `needs: build` aufrufen. Kein Deployment aus ungeprüften PR-Artefakten.

Beispiel für den Aufruf im projektspezifischen Release-Workflow (der reale `build`-Job und dessen Artefaktname müssen dazu passen):

```yaml
on:
  release:
    types: [published]
jobs:
  # build: hier steht der tatsächlich implementierte, getestete Projektbuild
  deploy:
    needs: build
    uses: ./.github/workflows/all-inkl-deploy.yml
    with:
      artifact_name: production-release
      environment: production
      project_root: /www/htdocs/ACCOUNT/PROJEKT
      php_command: php85
    secrets: inherit
```

Das ist ein Integrationsbeispiel, kein eigenständig lauffähiger Buildworkflow. Bei der Anwendung des Skills den fehlenden Projektbuild wirklich implementieren und das YAML vollständig prüfen. Das Release-ZIP soll in allen Wegen dasselbe Format haben.

GitHub-Secrets für den Deployjob:

- `DEPLOY_SSH_KEY`: dedizierter privater Deployment-Schlüssel.
- `DEPLOY_KNOWN_HOSTS`: unabhängig verifizierte Hostschlüsselzeile(n).
- `DEPLOY_SSH_CONFIG`: SSH-Konfiguration mit Alias `all-inkl-ci`, echtem Host/Benutzer/Port und `IdentityFile ~/.ssh/all-inkl-deploy`, vorzugsweise `IdentitiesOnly yes`.

Die zugehörigen Schlüsselrechte und Zielpfade am Server einrichten. `production`-Environment und zulässige Release-Refs passend konfigurieren; vorhandene Freigaberegeln übernehmen. Der Job verwendet eine Concurrency-Gruppe; zusätzlich schützt die Serversperre auch gegen manuelle parallele Aufrufe. Kein `cancel-in-progress: true` für laufende Deployments. SSH-Konfiguration/Schlüssel werden auf dem kurzlebigen Runner geschrieben und abschließend entfernt. Beispiel nutzt GitHub-gehostete Runner; persistente eigene Runner benötigen isolierte SSH-Dateien statt Überschreiben vorhandener Nutzerkonfiguration.

Bei anderem CI dieselben Build-/ZIP-Schritte, geschützte Secrets, überprüfte SSH-Konfiguration und `deploy-ssh.sh` nutzen. CI-Integration erst als funktionierend melden, wenn ein beauftragter Lauf erfolgreich war; ohne Zugriff die lokalen Dateien fertigstellen und die fehlende Einrichtung genau benennen.

## Fehler und Rollback

- Vor Aktivierung bleibt `current` unverändert. Fehlgeschlagene Installationen bleiben zur Diagnose liegen und bekommen keine Fertigmarkierung; für einen neuen Versuch eine neue Release-ID bauen.
- Nach fehlgeschlagenem HTTP-Check bleibt das neue Release aktiv und das Tool liefert Exitcode 1. Ursache prüfen, nicht blind erneut installieren. Wenn das Schema kompatibel ist, gezielt zurückschalten:

  ```bash
  php85 deploy/deploy.php rollback deploy/config.json VORHERIGE-RELEASE-ID
  ```

- `previous` zeigt auf das zuvor aktive verwaltete Release. Rollback schaltet nur Code um und prüft HTTP; Konfiguration, Uploads und Datenbank bleiben unverändert. Wiederherstellung der Datenbank gesondert durchführen, falls nötig.
- Installations- und Aktivierungsbefehle geben bei Fehlern Exitcode 1 zurück. Sperre wird beim Prozessende freigegeben; `.deploy.lock` nicht während eines Deployments löschen.
- ZIPs, alte Releases und fehlgeschlagene Installationen werden nicht automatisch gelöscht. Eine konkrete Aufbewahrung im Projekt festlegen; `current`, `previous` und benötigte Wiederherstellungspunkte erhalten. Deploymentwerkzeuge bei Änderungen gezielt synchronisieren; normale ZIPs ersetzen sie nicht.

Offizielle Grundlagen: [PHP ZipArchive](https://www.php.net/manual/en/class.ziparchive.php), [GitHub Deployments](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments), [Download Artifact](https://github.com/actions/download-artifact). Actions-Versionen bei Übernahme anhand offizieller Releases prüfen und nach Projektstandard auf Commit-SHAs fixieren.
