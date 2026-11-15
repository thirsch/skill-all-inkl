# Projektabhängige Healthchecks

Beim Vorbereiten einer PHP-Anwendung diese Referenz lesen und eine konkrete Prüfliste aus dem Projekt ableiten. Bereits vorhandene Framework-Healthrouten erweitern, wenn das sinnvoller ist als ein zweiter Endpunkt. Einfache PHP-Projekte können die mitgelieferten Assets direkt übernehmen.

## Auswahl der Prüfungen

- **Immer:** PHP-Zielversion, Manifest des tatsächlich laufenden Releases und erfolgreiche HTTP-Antwort. Den vorhandenen Release-ID-Abgleich des Deployers beibehalten.
- **Pflicht-Erweiterungen:** aus tatsächlich verwendeten Funktionen, `composer.json` und den Produktionspaketen in `composer.lock` ableiten. `require-dev`/`packages-dev` nicht als Produktionspflicht behandeln. `provide`/`replace` und bewusst verwendete Polyfills berücksichtigen; nicht jede `ext-*`-Nennung ungeprüft übernehmen. Versionsconstraints zusätzlich über Composer prüfen.
- **Optionale Erweiterungen:** nur sinnvoll diagnostizierte Fähigkeiten aufnehmen. Fehlende optionale Module ergeben einen Hinweis und keinen Ausfall.
- **Alternative Erweiterungen:** nur wenn die Anwendung wirklich beide Implementierungen unterstützt, etwa GD oder Imagick; mindestens eine muss geladen sein.
- **Datenbank:** nur bei tatsächlichem Datenbankbedarf. Bestehende Anwendungskonfiguration verwenden und `SELECT 1` ausführen. Keine Tabellenänderungen, Migrationen oder Probeeinträge im Healthcheck.
- **Dateispeicherung:** nur benötigte Cache-/Upload-/Sessionverzeichnisse auf Existenz und Schreibbarkeit unter dem Web-PHP-Benutzer prüfen. Keine Verzeichnisse automatisch anlegen oder Rechte ändern.

| Typische Erweiterung | Anlass für eine Pflichtprüfung |
| --- | --- |
| `pdo_mysql` | MariaDB/MySQL über PDO; bei mysqli stattdessen `mysqli` |
| `mbstring`, `intl` | tatsächlich verwendete Unicode-/Lokalisierungsfunktionen |
| `openssl`, `curl` | entsprechende Kryptografie-/HTTP-Funktionen der Anwendung |
| `fileinfo` | Dateityperkennung bei Uploads |
| `zip` | ZIP-Verarbeitung in der Webanwendung; für den CLI-Deployer separat erforderlich |
| `gd` oder `imagick` | tatsächlich verwendete Bildverarbeitung |
| `dom`, `SimpleXML`, `xmlreader` | benötigte XML-/HTML-Verarbeitung |
| `bcmath` | verwendete Dezimalarithmetik |

Keine pauschale Pflicht für die gesamte Tabelle. OPcache ist normalerweise ein Diagnose-/Leistungsthema. Eine HTTPS-Domain allein verlangt keine `openssl`-Erweiterung in der Anwendung. Die Bibliothek selbst benötigt JSON; bei PHP 8.5 gehört JSON zum Kern.

## Assets und Konfiguration

Der Standard-Endpunkt verlangt Basic Auth über HTTPS und führt echte Prüfungen höchstens einmal pro **10 Sekunden** aus. `cache_ttl_seconds: 1` stellt auf eine Sekunde um. Authentifizierung und das Lesen des Caches finden weiterhin bei jedem Request statt; das Intervall begrenzt die eigentlichen PHP-/Datenbank-/Verzeichnisprüfungen.

- [healthcheck.php](../assets/health/healthcheck.php) nach `<release>/healthcheck.php` kopieren.
- [health-config.php](../assets/health/health-config.php) als Ausgangspunkt nach `<release>/health-config.php` kopieren und projektabhängig ausfüllen.
- [public-health.php](../assets/health/public-health.php) nach `<release>/public/health.php` kopieren oder den Funktionsaufruf in die bestehende Healthroute integrieren.

Bibliothek und Konfiguration bleiben außerhalb des Document Roots, aber werden mit dem Release paketiert. Die Konfiguration enthält Regeln, Pfadzuordnungen und gegebenenfalls einen Adapter zur bestehenden Datenbankverbindung; keine Passwörter oder DSNs mit Secrets versionieren. Keine Konfiguration aus HTTP-Parametern laden und keine öffentlichen Schalter zum Abschalten von Prüfungen anbieten.

Einmalig `deploy/init-health-auth.php` aus den [Deployment-Assets](../assets/deploy/init-health-auth.php) übernehmen und auf dem Server ausführen:

```bash
php85 deploy/init-health-auth.php /ABSOLUTER/PROJEKTPFAD/shared healthcheck
```

Der CLI-Helfer erzeugt einen separaten zufälligen Zugang, ohne ihn auszugeben, und behält ein bereits vorhandenes passendes Zugangspaar bei:

- `shared/health-auth.json`: Benutzername und Passwort-Hash für den Web-Endpunkt, Rechte 600.
- `shared/health-client.json`: Benutzername und Passwort ausschließlich für authentifizierte Prüfclients/Deployer, Rechte 600.
- `shared/health-cache/`: gemeinsamer Cache für alle Worker und Releases dieses Projekts, Rechte 700.

Die beiden Release-Zuordnungen `health-auth.json → shared/health-auth.json` und `health-cache → shared/health-cache` in der Deploymentkonfiguration ergänzen. Die Clientdatei **nicht** ins Release verknüpfen und keine der Dateien paketieren. Zugriff ausschließlich unter dem passenden Account; echte Zugangsdaten nur in der geschützten Datei bzw. einem vorgesehenen Secret-Store halten. Änderungen an den geheimen Dateien gehören nicht in Git.

Beispiel für ein Projekt, das tatsächlich MariaDB, Unicode und Uploads benötigt:

```php
return [
    'php_minor' => '8.5',
    'auth_file' => __DIR__ . '/health-auth.json',
    'cache_directory' => __DIR__ . '/health-cache',
    'cache_ttl_seconds' => 10,
    'required_extensions' => ['json', 'pdo_mysql', 'mbstring', 'fileinfo'],
    'optional_extensions' => ['intl'],
    'extension_alternatives' => [],
    'writable_directories' => ['uploads' => __DIR__ . '/storage/uploads'],
    'database' => static function (): PDO {
        // Die bestehende App-Konfiguration muss vorher geladen sein.
        return new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    },
];
```

Der Datenbankadapter ist optional (`null` deaktiviert ihn). Bei Frameworks bevorzugt dessen konfigurierte PDO-Verbindung verwenden. Der Adapter muss kurze, vom verwendeten Treiber tatsächlich unterstützte Verbindungs-/Abfragetimeouts besitzen. Der HTTP-Timeout des Deployers beendet nicht automatisch einen blockierten PHP-Worker. `SELECT 1` prüft Erreichbarkeit und Authentifizierung, aber keine Schema-Version oder fachliche Vollständigkeit. Solche Prüfungen nur bei konkretem Bedarf ergänzen.

`writable_directories` nutzt `is_dir` und `is_writable`; das ist eine Rechteprüfung, kein Schreib-/Kapazitätstest. Öffentliche Ergebnisse enthalten nur konfigurierte Bezeichner wie `directory:uploads`, niemals absolute Pfade. Fehlende Pfade werden nicht automatisch repariert.

## Ergebnis und Deploymentvertrag

Ohne oder mit falschem Basic-Auth-Zugang liefert der Endpunkt **401** samt `WWW-Authenticate`, ohne Probes auszuführen oder Cache-Inhalte offenzulegen. Außerhalb des lokalen PHP-Testservers wird unverschlüsseltes HTTP vor der Authentifizierung mit **403** abgewiesen. Fehlende Auth-/Cachekonfiguration führt zu **503**, nicht zu einem öffentlichen Fallback. Bei FastCGI muss der Server den Authorization-Header oder die PHP-Auth-Variablen durchreichen; falls das am Ziel fehlt, die passende Webserverkonfiguration gezielt einrichten und prüfen.

Nach erfolgreicher Authentifizierung schützt ein gemeinsames `flock` vor parallelen Probes. Erfolg **und Fehler** werden für das konfigurierte Intervall gespeichert. Ein belegtes Lock ohne gültigen Cache liefert kurzzeitig 503 mit `Retry-After`, statt einen zweiten Datenbankzugriff auszulösen. Die nächste Prüfung erfolgt frühestens ein Intervall nach Abschluss der vorherigen; vor ihrem Start wird zusätzlich eine Sperrfrist reserviert, damit auch ein abgebrochener Worker keine sofortige Prüfflut auslöst. Cache-/Lockdateien im Betrieb nicht löschen, um das Limit nicht zu umgehen.

Ein neues Release bekommt niemals das Ergebnis eines alten Releases. Falls das gemeinsame Intervall noch läuft, antwortet es zunächst mit 503/`health_refresh: pending`, bis seine eigene Prüfung erlaubt ist. Dadurch gilt das Limit auch während Releasewechseln. Die Antwort enthält eine zufällige `check_id`, die nur bei einer echten Prüfung neu entsteht. `X-Health-Cache` unterscheidet `MISS`, `HIT` und `WAIT`; Browser-/Proxy-Caching bleibt durch `Cache-Control: no-store` abgeschaltet.

Erforderliche Prüfungen erfolgreich: HTTP **200**, `status: ok`. Fehlende Pflicht-Erweiterung, Datenbankfehler, PHP-Abweichung, nicht beschreibbares Verzeichnis oder fehlerhafte Konfiguration: HTTP **503**, `status: failed`. Optionale Fehlschläge stehen als `warning` in `checks`; der Gesamtstatus bleibt erfolgreich, solange alle Pflichtprüfungen bestehen.

```json
{
  "status": "ok",
  "release": "build-42",
  "php": "8.5.x",
  "sapi": "cgi-fcgi",
  "checks": {
    "php": "ok",
    "extension:pdo_mysql": "ok",
    "extension:intl": "warning",
    "database": "ok",
    "directory:uploads": "ok"
  }
}
```

Reale Antwort ergänzt `checked_at`; Versions- und SAPI-Werte werden zur Laufzeit ermittelt. Keine DSNs, Credentials, SQL-Fehler, Stacktraces oder vollständige `phpinfo()`-Ausgabe zurückgeben. Die öffentliche Antwort enthält nur den kleinen konfigurierten Prüfumfang. Vorhandene Zugriffsbeschränkungen des Projekts berücksichtigen.

In der Deploymentkonfiguration `health_url` auf diesen Endpunkt, `health_contains` auf `"status":"ok"`, `health_release_field` auf `release` und `health_check_id_field` auf `check_id` setzen. `health_basic_auth_file` zeigt auf den absoluten geschützten Pfad zu `shared/health-client.json`. Zugangsdaten werden als Authorization-Header übermittelt, niemals in der URL; der Deployer folgt keinen Redirects. Dieselbe Cache-Antwort zählt nicht mehrfach zur geforderten Serie erfolgreicher Prüfungen. Standard: maximal 40 Versuche, fünf Sekunden Abstand, drei unterschiedliche erfolgreiche Prüf-IDs. Das berücksichtigt sowohl den 10-Sekunden-Cache als auch verzögert aktualisierte PHP-Worker.

Die Prüfungen müssen **über HTTP im Web-PHP** laufen: `php85 -m` bzw. Composer im CLI können eine andere Erweiterungskonfiguration sehen. `composer check-platform-reqs --no-dev` bleibt eine zusätzliche Prüfung der realen CLI-Plattform. Bei Updates bestehender Installationen Auth-Dateien/Cache zuerst vorbereiten, dann Deployer und Konfiguration aktualisieren und das passende Release aktivieren. Ein Code-Rollback auf alte Releases kann auch alte Auth-/Cacheeigenschaften zurückbringen; geeignete Wiederherstellungsversion auswählen.

Für reine statische Sites keine unnötige PHP-Anwendung einführen; deren Build-Healthdatei prüft nur das Release und kann per Webserver-Basic-Auth geschützt werden. Die PHP-/ZIP-Voraussetzungen des CLI-Deployers separat vorab prüfen. `health_check_id_field` bei einer statischen Datei auf leer setzen: Dort existiert keine tatsächlich ausgeführte PHP-Prüfung mit neuer ID.

## Validierung

Lokal: `python3 -m unittest discover -s tests -v` im Skill mit PHP 8.5, ZIP und für den Datenbankadapter-Test PDO SQLite. Abgedeckt sind Pflicht-/Optionalverhalten, echte HTTP-Statuscodes, Alternativen, PHP-Version, Verzeichnisse, `SELECT 1`, geheime Fehlerdetails und ungültige Konfiguration.

Bei einem autorisierten Praxistest keine Servermodule abschalten. Eine fest konfigurierte, vorübergehende separate Testroute kann eine garantiert nicht vorhandene Erweiterung als Pflicht verlangen: erwartet HTTP 503. Derselbe Name als optional: erwartet HTTP 200 plus Warnung. Das reguläre Healthziel bleibt dabei unverändert nutzbar. Solche Testrouten anschließend durch ein sauberes Folgerelease entfernen; keine öffentliche Fehler-Injektion über Queryparameter hinterlassen.

Grundlagen: [extension_loaded](https://www.php.net/manual/en/function.extension-loaded.php), [Composer-Plattformprüfung](https://getcomposer.org/doc/03-cli.md#check-platform-reqs), [PDO](https://www.php.net/manual/en/book.pdo.php), [is_writable](https://www.php.net/manual/en/function.is-writable.php).
