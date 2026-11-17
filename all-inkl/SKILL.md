---
name: all-inkl
description: Beschreibe, bereite und deploye Projekte bei ALL-INKL.COM (all-inkl, all inkl) mit einem einheitlichen Stack- und Release-ZIP-Verfahren. Verwende den Skill bei all-inkl als Hostingziel, für manuelle SSH-Deployments, Agenten-Deployments in der Entwicklung und den anschließenden CI-Betrieb. Nicht für reine Domain-, E-Mail- oder Vertragsverwaltung.
---

# all-inkl Deployment vorbereiten

Erzeuge einen konkreten Deploymentvertrag im Projekt. Standardziel ist **all-inkl Webhosting mit SSH**. Neue dynamische Anwendungen verwenden **PHP 8.5** (CLI: **`php85`**), ein Framework nach Projektbedarf und bei relationalem Datenbedarf **MariaDB 10.11**. Diese Versionen sind vom Nutzer gewählte Zielvorgaben; ihre Verfügbarkeit am konkreten Account bleibt zu prüfen. Rein statische Projekte benötigen weder PHP-Anwendung noch Datenbank. Frontend-Builds laufen lokal oder in CI.

## Arbeitsweise

1. Lies Projektanweisungen, bestehende Deploymentdokumentation, relevante Manifeste, Lockfiles und Build-Konfiguration. Übernimm explizite Projektentscheidungen. Migriere vorhandene Anwendungen nicht allein wegen dieses Standards und führe keine ungefragten Versions-Upgrades durch.
2. Lies [references/profile.md](references/profile.md) für Konventionen und die Prüfung der Hostingfähigkeiten. Trenne bestätigte Fakten, gewählte Standards und offene Accountangaben. Bereits bekannte Angaben nicht erneut erfragen.
3. Erstelle oder aktualisiere `docs/deployment/all-inkl.md` nach [assets/deployment.template.md](assets/deployment.template.md). Behalte die Abschnittsreihenfolge bei; nicht benötigte Bereiche mit „entfällt“ und kurzer Begründung markieren. Gleichwertige bestehende Projektdokumentation an ihrem etablierten Ort aktualisieren statt eine zweite Wahrheit zu erzeugen. Abweichungen vom Profil ausdrücklich dokumentieren.
4. Bei „beschreiben/planen“ erstelle die Spezifikation. Bei „vorbereiten/einrichten/deployen“ lies [references/release-deployment.md](references/release-deployment.md). Übernimm die dort verlinkten ausführbaren Assets nach `deploy/`, passe die Konfiguration an und ergänze den tatsächlichen Projektbuild. Für PHP-Anwendungen lies [references/healthchecks.md](references/healthchecks.md) und leite erforderliche Erweiterungen, optionale Diagnosen, Datenbank- und Verzeichnisprüfungen aus dem Projekt ab. Verwende dasselbe Release-ZIP und dieselbe Aktivierung für manuelle, Agenten- und CI-Deployments. Keine funktionslosen Buildskripte oder ein neues App-Gerüst nur zur Vervollständigung der Vorlage anlegen.
5. Prüfe erzeugte Konfigurationen mit passenden vorhandenen Build-/Validierungsbefehlen. Gleiche Versionen, Pfade, Artefaktinhalt und Kommandos mit dem Projekt ab. Führe Deployment-, Migrations- oder CI-Trigger nicht als lokalen Test aus. Berichte Dateien, Prüfungsergebnisse und noch fehlende Accountangaben knapp.

## Deployment je Projektphase

- **Entwicklung:** Ist eine Bereitstellung Teil des Auftrags und sind SSH-Zugang sowie Entwicklungsziel hinterlegt, führt der Agent Build, Upload und Aktivierung selbst durch. Fehlen ihm die SSH-Daten, liefert er ZIP, SHA-256-Datei und einen konkret ausgefüllten SSH-Befehl für den manuellen Upload und Deploy durch den Nutzer. Bereits erteilte Deploymentautorisierung nicht erneut erfragen.
- **Initialer Produktivstand:** Ersteinrichtung und Erstveröffentlichung dürfen über denselben manuellen oder Agenten-Weg erfolgen. Erfolg mit Release-ID und Healthcheck dokumentieren; anschließend die Projektphase auf CI-Betrieb setzen.
- **Nach Erstveröffentlichung:** Weitere Produktionsdeployments erfolgen über GitHub Actions oder das bereits verwendete CI-System. Der Agent stößt bei beauftragtem Deployment den vorgesehenen CI-Weg an und verfolgt das Ergebnis. Manuellen Weg als ausdrücklich angeforderten Wiederherstellungsweg behalten. Den CI-Release-Trigger, Secrets und Zielumgebung im Projekt konkret einrichten; allein eine unverbundene Workflow-Vorlage ist keine abgeschlossene CI-Einrichtung.

## Entscheidungen und Grenzen

- Nutze die Standards ohne wiederholte Grundsatzfragen. Kläre nur Entscheidungen, die das Ergebnis blockieren und nicht aus dem Projekt hervorgehen. Fehlender Serverzugriff blockiert die lokale Vorbereitung nicht.
- Versionsfelder enthalten konkrete bestätigte oder ausdrücklich vorgeschlagene Versionen, Herkunft und Prüfdatum. „Aktuell“, „latest“ oder erfundene all-inkl-Versionen sind kein fertiges Profil. Bei unbekanntem Serverstand die Standard-Zielversion beibehalten und nur den tatsächlichen Stand als offen markieren; erforderlichen Nachweis nennen.
- PHP-Healthchecks standardmäßig mit Basic Auth über HTTPS und gemeinsamem 10-Sekunden-Cache betreiben; eine Sekunde ist konfigurierbar. Die Felder `release`, `version` und `deployed_at` für das Deployment-Dashboard liefern: Anwendungsversion aus dem Build, UTC-Zeitpunkt aus der Serveraktivierung, unbekannte Werte als `null`. Parallelität, Fehlercache, Aktivierungswechsel und unterschiedliche echte Prüf-IDs nach [references/healthchecks.md](references/healthchecks.md) berücksichtigen. Für den Deployer einen eigenen geschützten Zugang nutzen.
- Bei unpassenden bestehenden Laufzeiten konkrete Lücke und mögliche Wege benennen. Ein vorhandenes SSR-Backend nicht durch einen angeblich gleichwertigen statischen Export ersetzen. Keine ungefragte Portierung oder zusätzliche externe Infrastruktur.
- Reine Planung bzw. Skill-Erstellung veröffentlicht keine Anwendung. Für echte Bereitstellungen den beauftragten Umfang und das bekannte Ziel verwenden; SSH-Daten für Produktion nicht als Entwicklungsziel interpretieren. KAS-/DNS-Änderungen und Datenbankmigrationen nur im beauftragten Umfang ausführen.
- Keine Zugangsdaten im Profil, Repository oder Artefakt speichern. Variablennamen und Bezugsorte dokumentieren. Bei gescheiterter Veröffentlichung Ursache feststellen und dokumentierten Abbruch-/Rollbackpfad verwenden statt Uploads oder Migrationen blind zu wiederholen.
