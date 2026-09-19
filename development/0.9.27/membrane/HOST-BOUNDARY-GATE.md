# KiCom Membrane – Host-Eignung und Integrationssperre

Stand 2026-09-19 · Status: **PRODUKTION NICHT NACHGEWIESEN – KEIN MEMBRAN-ENFORCEMENT AKTIVIEREN**

## Read-only geprüfter Ist-Zustand

Der produktive KiCom-Status bestätigt 0.9.26/g25r3 und listet KCL/HTTPS,
POST-API, DEV, Slack, Mail, Browser/Opera, Update und Recovery als
Anwendungsschnittstellen. Daraus folgt **keine** Aussage über die effektive
Unix-UID des PHP-Webworkers, FTP-Accounts, Benutzergruppen, chroot/Jail,
Netzwerk-Namespace, den Besitz von Dateien oder die Privilegien des
Recovery-Kerns. Diese Informationen wurden nicht aus einem freigegebenen,
lesenden Host-/Admin-Diagnosepfad bestätigt. Es wurden weder Zugangsdaten
ausprobiert noch ein PHP-Informationsskript im produktiven Webroot angelegt.

Die GitHub-Actions-Tests mit getrennten Linux-Benutzern sind ein Labor-
Nachweis, keine Messung des Produktivhosts. Das KiCom-Archiv, die lokale
Recovery-Funktion und das GitHub-Repository sind NICHT von sich aus ein
physisch unabhängiger Vertrauensanker.

## Unverzichtbare Host-Nachweise VOR Aktivierung

| Anforderung | Ausreichender Nachweis | Aktuell |
| --- | --- | --- |
| Innenraum ist eine unprivilegierte Laufzeitidentität | Prozess-/Hosting-Diagnose des tatsächlichen PHP-Pools/Workers, UID/GID und Schreibrechte pro Zielverzeichnis | UNBEKANNT |
| Membran/Policy außerhalb des Innenraum-Schreibbereichs | anderer vom Host geschützter Principal bzw. Verwaltungsbereich; negativer Schreib-/Leseversuch des Innen-Prozesses an isolierter Testdatei | UNBEKANNT |
| Recovery-Trust-Root unabhängig von beiden Laufzeitrollen | vom Innenraum und dem Membranprozess nicht beschreibbar, getrennte Credentials und Backup-/Rollback-Domäne | UNBEKANNT |
| Nicht umgehbare Aktionsgrenze | sämtliche externen/mutierenden Endpunkte und Side-Channels inventarisiert und gegen direkte Umgehung geprüft | NICHT NACHGEWIESEN |
| Kommunikation bleibt intakt | vollständige bidirektionale Tests KCL, DEV-Passkey, Slack, Mail, Opera, Chat-Update, Roh-ZIP, Chunk/Batch, Rückgabecodes, Header, Zeitüberschreitungen und Störungen | TEILWEISE IM LABOR |
| Upgrade und Rückkehr | Release-Verifier, exakte Paket-Hashes, unabhängige Datensicherung, kontrollierter Healthcheck und tatsächlicher Rollback im Staging | NICHT NACHGEWIESEN |

Es genügt NICHT, neue FTP-Konten oder Unterordner anzulegen, wenn alle
betroffenen PHP-Prozesse unter derselben UID laufen oder KiCom auch über
einen zweiten Schreibweg an die Membran-Dateien gelangen kann.

## Read-only Evidenzgewinnung – kein vorausgesetzter Shell-Zugang

1. Hosting-Administrationsinformationen und vorhandene *bereits
   autorisierte* Diagnosewege zu PHP-Ausführungsmodus/UID, FTP-/
   Verzeichnisrechten, Webroot und Recovery-Backups untersuchen.
2. Nur in einer separaten Testinstanz einen nicht-sensitiven
   Negativtest zwischen zwei tatsächlich getrennten Laufzeit-Principals
   ausführen. Nie bestehende produktive Dateien, Secrets oder Backup-
   Anker als Testziel verwenden.
3. Wenn der aktuelle Tarif oder Host keine unabhängigen Laufzeitrollen
   und keine nicht umgehbare Host-Grenze ermöglicht, ist die reale
   Membran eine notwendige gesonderte Host-/Sidecar-Architektur.
   Die bisherige passive Klassifikation darf nicht als Ersatz
   deklariert werden.
4. Vor einem möglichen Umzug einen vollständigen bidirektionalen
   Kommunikations-Kompatibilitätsplan ohne produktives Test-Senden
   an echte Slack-/Mail-Empfänger ausführen.

## Ausfallverhalten

- Passiver Telemetrieausfall: bestehende Kommunikation läuft unverändert,
  ohne Wiederholung einer bereits gestarteten Aktion.
- Ausfall des künftig erzwingenden Policy-Dienstes: geschützte Aktionen
  dürfen NICHT fail-open; normale read-only Betriebs- und Diagnosekanäle
  benötigen einen vorab getesteten, klar getrennten Weiterbetrieb.
- Darf der Host die Trennung nicht belegen, ist der einzig zulässige
  automatische Übergang: **STAGING_ONLY / NO_PRODUCTION_PROMOTION**.

## Primärquellen

PHP dokumentiert ausdrücklich, dass `open_basedir` keine vollständige
Sicherheitsgrenze darstellt:
https://www.php.net/manual/en/ini.core.php

PHP erläutert, dass für kritische Sicherheitsaufgaben getrennte
OS-UIDs/isolierte Ausführungsumgebungen erforderlich sein können:
https://www.php.net/security-note.php

NIST SP 800-207 trennt logische Policy-Entscheidung und tatsächlichen
Enforcement-Punkt:
https://doi.org/10.6028/NIST.SP.800-207

Der bisherige exakte Entwicklungscode und seine Grenzen stehen in
ARCHITECTURE.md sowie CHECKPOINT-2026-09-19.md.
