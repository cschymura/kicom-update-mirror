# KiCom Membrane – Entwicklungscheckpoint 2026-09-19

## Ziel (vom Auftraggeber klargestellt)

Eine reale Innen-Außen-Grenze für KiCom, nicht lediglich eine gepackte Datei.
KiCom soll seinen veränderbaren Innenraum erhalten und entwickeln, äußere
Informationen als äußere Informationen erkennen und ausgehende Handlungen nur
durch tatsächlich erzwungene Grenzen ausführen. Unautorisierte Eingriffsversuche
sollen als technische Anomalien erkennbar sein. Menschliche Administration,
Backup, Recovery und Abschaltung bleiben zugänglich.

**Kommunikations-Nichtregression ist ein hartes Freigabekriterium.**

## Bei Beginn und Abschluss read-only verifiziert

Live: KiCom 0.9.26, Genome kicom-0.9.26-g25r3, healthy/trusted/LKG OK,
drift_count=0, unknown_count=0. SQLite primary/WAL quick_check=ok.
UPDATE_STATUS ohne pending Version. PING/HELLO und SLACK_STATUS, MAIL_STATUS,
CHAT_UPDATE_STATUS über bestehende lesende KCL-Endpunkte beantwortet.
SLACK_STATUS meldete configured=false, Mail configured=true. Ein erfolgreicher
SLACK_STATUS belegt **nicht** realen Slack-Versand/-Empfang.
Keine Änderung am Live-Core, an Ports/URLs/Routing, Geheimnissen,
Session-/Passkey-/FreeOTP-Logik, Mail/Slack oder Datenbanken durchgeführt.

## Im GitHub DEV-Zweig implementiert

Repository cschymura/kicom-update-mirror,
Branch work/kicom-0.9.27-pam,
Verzeichnis development/0.9.27/membrane/:

- ARCHITECTURE.md: Systemmodell mit separat privilegiertem
  Membrane-Controller außerhalb des unprivilegierten KiCom-Innenraums,
  kontrollierter In-/Egress- und Action-Boundary, unabhängiger Recovery-
  und Betreiber-Identität. Eine PHAR-/Containerdatei ist keine
  unabhängig erzwungene Membran.
- KiComMembraneShadow.php: passiver, metadatengebundener Beobachter mit
  festen Kanälen KCL, DEV, Slack, Mail, Update, Browser/Opera, GitHub.
  Kein Body/Header/Token-/Empfängerzugriff, kein Transport-, Execution-,
  Auth-, Filesystem-, DB- oder Netzwerkeingriff. Alle vorhandenen
  Kommunikations- und Autorisierungswege bleiben Original-KiCom.
- test-shadow.php: Tests auf sichere Klassifikation, unbekannte Wege,
  unveränderte opaque Message-Fixtures und keine Freigabe.
- test-r3-transport.php: exaktes R3-Package+Quelltext-Manifest und
  statische Prüfung bestehender Transport-/Auth-/KCL-/Mail-/Slack-Routen.
- test-local-wire.php: zwei isolierte PHP-R3-HTTP-Server, einer normal,
  einer mit passivem Observer vorgeladen; tatsächliche KCL-PING/HELLO/
  ECHO/DESCRIBE- und GET-auf-POST-Methodenfehler-Antworten sind nach
  Normalisierung allein der zufälligen request_id byteidentisch.
  Slack-/Mail-/Chat-Statusendpunkte behalten HTTP-Status, Content-Type,
  KCL-Format und Erreichbarkeit. KEIN echter Versand/Empfang in den
  externen Slack-/Mail-Systemen getestet; KEINE Opera-Login-/Passkey-,
  externen Empfänger- oder RAW-Update-E2E-Probe.
- test-os-boundary.sh: auf isoliertem Linux-GitHub-Runner separate
  OS-Identitäten für Innenraum und Membran eingerichtet; der Innenraum
  darf seine Zustandsdatei beschreiben, nicht jedoch Policy/Recovery-
  Trust-Root lesen/beschreiben. Der Controller kann nur eigene Policy
  lesen und den Recovery-Root nicht ändern. Dieser Test ist ein
  nachweislich OS-erzwungenes LABOR-Ergebnis, keine Messung des
  tatsächlichen PHP-Produktivhostings.
- .github/workflows/test-0927-membrane.yml: CI auf dem exakten auslösenden
  Commit; keine Schreibrechte auf GitHub-Repositorium durch den Testjob.

## Bestätigtes GitHub CI-Ergebnis

Workflow-RUN_ID 35442915620, job 105896757495,
getesteter Commit a24d6c7fee9962723d190c37b2543a72fd52077a.
GitHub Actions result: success.
28 Shadow-, 24 bestehende R3-Kommunikationsrouten-,
16 HTTP-Wire- und 6 OS-Principal-Isolationsprüfungen:
**74 isolierte Checks erfolgreich**.

Quelle: GitHub Actions Job-Logs für RUN_ID 35442915620;
Workflow-Link:
https://github.com/cschymura/kicom-update-mirror/actions/runs/35442915620

Die letzten Dokumentationscommits verändern keinen getesteten PHP-/Shell-
Code. Bei späteren Codeänderungen einen passenden neuen CI-Lauf abwarten.

## Primärquellen

- NIST SP 800-207 zu getrenntem Control Plane / Data Plane / PEP:
  https://doi.org/10.6028/NIST.SP.800-207
- NIST SP 1800-35 reale Implementierungsbeispiele:
  https://csrc.nist.gov/pubs/sp/1800/35/final
- PHP open_basedir ist keine umfassende Security Boundary:
  https://www.php.net/manual/en/ini.core.php
- PHAR-Signatur ist bei gemeinsamen Änderungsrechten kein unabhängiger
  Manipulationsschutz: https://www.php.net/manual/en/phar.configuration.php
- OWASP sensitive Daten nicht direkt protokollieren:
  https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html

## Noch NICHT erreicht

Keine produktive Membran. Keine unabhängig geprüfte Trennung der tatsächlichen
PHP-Hosting-Identitäten oder FTP/Dateisystemrechte. Kein realer Membran-Gateway,
kein kontrollierter In-/Egress im produktiven Server, keine vollständig
getesteten echten Slack/SMTP/IMAP/Opera- oder Update-Transaktionen durch
eine aktivierte Membran. Keine Änderung an KiComs bestehendem Autorisierungs-
modell und keine Beeinträchtigung seiner bestehenden Transportwege durch
diesen GitHub-only-Entwicklungsschritt.

## Nächste sichere Entwicklungsschritte

1. Gezielte READ-ONLY Umgebungsprüfung: Welche Host-Privilegien, Dateirechte,
   voneinander unabhängige Prozess-/Filesystem-Identitäten und externe
   Recovery-/Trust-Root-Möglichkeiten sind wirklich vorhanden?
   Wenn dies im vorhandenen Webhosting unmöglich ist, eine getrennte
   Hosting-/Sidecar-Architektur als Voraussetzung benennen und KEINE
   bloß logische PHP-Membran als erzwungene Grenze deklarieren.
2. In isolierter Staging-Umgebung alle Kommunikationspfade in BEIDE
   Richtungen testen, inklusive Slack-Event-Authentizität und Replay-
   Verhalten, Mail-IMAP/SMTP, Browser/Opera/Passkey, KCL-GET,
   POST-JSON, RAW-ZIP, Tokenrotation, Chunk-/Batch-Transport,
   Fehler-/Timeout-Bedingungen und Verfügbarkeit unter Beobachterausfall.
   Echte externe Nachrichten nur mit gesonderter Freigabe/Testempfänger;
   reguläre Nutzerkommunikation nicht als Testkanal verwenden.
3. Separat geschützten Host-Controller und read-only, verifizierten
   Innenraum-Kern zuerst in einer Staging-Instanz realisieren. Den
   bestehenden KiCom-Core nicht vom Netzwerk abschneiden, sondern
   kompatible Übernahme und sofortigen Rollback vorsehen. Freigabe/
   Erfolg der Staging-Tests ist keine autonome Produktionsberechtigung.
4. Vor jeder produktiven Änderung: unveränderter Update-Verifier, exakter
   Paket-/Genome-SHA, Backup/Restore- und unabhängige Trust-Root-Prüfung,
   vollständige Healthchecks, LKG und bestehende transaktionsgebundene
   geschützte Produktionsfreigaben.
