# all-inkl Deployment-Skill für Codex

Ein wiederverwendbarer Skill für Projekte auf ALL-INKL.COM-Webhosting mit SSH.
Der installierbare Skill liegt vollständig im Verzeichnis [`all-inkl/`](all-inkl/SKILL.md).

## Enthalten

- Standardprofil: PHP 8.5 (`php85`), Framework nach Projektbedarf, MariaDB 10.11 bei Datenbankbedarf. Tatsächliche Hostingfähigkeiten werden je Account geprüft.
- Einheitliche Deploymentdokumentation und Release-ZIP mit SHA-256-Prüfsumme.
- SSH-Upload, Installation, Release-Aktivierung, persistente Shared-Dateien und Code-Rollback.
- Manuelles oder Agenten-Deployment während der Entwicklung; CI-Betrieb nach dem initialen Produktivstand, einschließlich GitHub-Actions-Vorlage.
- Projektabhängige Healthchecks für PHP, Erweiterungen, Datenbankverbindung und beschreibbare Verzeichnisse.
- Basic Auth über HTTPS, separate Health-Zugangsdaten, gemeinsamer Cache mit Sperre: standardmäßig zehn Sekunden, optional eine Sekunde.
- Deploymentprüfung anhand der aktiven Release-ID und unterschiedlicher tatsächlich ausgeführter Healthchecks.
- Automatisierte Tests für Deployment, Authentifizierung, Cache und Fehlerfälle.

Der Skill enthält keine Serverzugänge oder Zugangsdaten. Account, Domain, Projektpfad, Secrets und konkrete CI-Einrichtung werden im jeweiligen Projekt ergänzt. Die lokale Live-Testumgebung wird nicht versioniert.

## Auf einem anderen Rechner installieren

Der GitHub-Account des Rechners benötigt Zugriff auf dieses private Repository.

### Als verknüpfter Git-Checkout unter macOS/Linux

```bash
mkdir -p "$HOME/Projects/github/thirsch"
git clone git@github.com:thirsch/skill-all-inkl.git "$HOME/Projects/github/thirsch/skill-all-inkl"
mkdir -p "${CODEX_HOME:-$HOME/.codex}/skills"
ln -s "$HOME/Projects/github/thirsch/skill-all-inkl/all-inkl" "${CODEX_HOME:-$HOME/.codex}/skills/all-inkl"
```

Existiert das Installationsziel bereits, zuerst prüfen, ob es schon auf den gewünschten Checkout zeigt. Die Befehle überschreiben keine vorhandene Skill-Installation. Der Checkout muss für die Verknüpfung an seinem Ort bleiben.

Updates holen:

```bash
git -C "$HOME/Projects/github/thirsch/skill-all-inkl" pull --ff-only
```

### Über den Codex Skill-Installer

In Codex den mitgelieferten Skill-Installer beauftragen:

> Verwende skill-installer und installiere aus dem GitHub-Repository thirsch/skill-all-inkl den Skill im Unterordner all-inkl.

Der Installer benötigt Zugriff auf das private Repository und installiert eine Kopie. Diese Variante wird nicht durch ein späteres `git pull` eines separaten Checkouts aktualisiert; für Updates die installierte Kopie bewusst aktualisieren lassen. Ein vorhandenes Installationsziel wird vom Installer nicht überschrieben.

## Verwenden

Beispielauftrag in einem Projekt:

> Bereite dieses Projekt für all-inkl vor und richte das Deployment gemäß unserem Skill ein.

Alternativ den Skill ausdrücklich mit `$all-inkl` ansprechen. Bestehende Projektentscheidungen werden berücksichtigt; eine reine Planungsanfrage veröffentlicht keine Anwendung.

## Tests

Voraussetzungen: Python 3 sowie PHP 8.5 als `php` im PATH mit ZIP, PDO und PDO SQLite; macOS/Linux. Die Tests arbeiten in temporären Verzeichnissen und mit lokalen HTTP-Testservern, ohne Live-Deployment.

```bash
python3 -m unittest discover -s all-inkl/tests -v
```

Der Test für fremde Dateieigentümer wird außerhalb eines isolierten Root-Testcontainers übersprungen.
