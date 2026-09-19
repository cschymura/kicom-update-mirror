# KiCom Membrane — Positive Local Transport und Hostgrenze

Datum: 2026-09-19; Repository: cschymura/kicom-update-mirror; Branch: work/kicom-0.9.27-pam.

## Kanonischer Produktivstand (nur lesend geprüft)

BOOTSTRAP und die sechs kanonischen Dokumente geprüft. Live KiCom 0.9.26, Genome kicom-0.9.26-g25r3, healthy/trusted/LKG OK, drift_count=unknown_count=0, SQLite primary und journal_mode=wal, quick_check=ok; UPDATE_STATUS ohne vorgemerkten Update. SLACK_STATUS configured=false; MAIL_STATUS configured=true; CHAT_UPDATE_STATUS available=true. Keine produktiven Dateien, Sitzungen, Nachrichten, Backups, Berechtigungen, Endpunkte oder Routingregeln geändert. Kanonischer PROJECT_STATE nennt weiterhin historisches g25r2 und NEXT ältere Prioritäten; nicht ohne tatsächlich autorisierten revisionsbewahrenden Memory-Pfad geändert.

Vorgänger: development/0.9.27/membrane/CHECKPOINT-2026-09-19-COMMUNICATION-HOST-GATE.md.

## Tatsächliche Codeänderungen

1. development/0.9.27/membrane/fixture-loopback.php: auf Port 18729/18730 und Loopback beschränkte synthetische Testgegenstelle. GET-/POST-Inhalte werden bytegleich zurückgesendet. Sie protokolliert ausschließlich synthetische Serienkennung und Hash eines Requests, keine Originalinhalte oder Zugangsdaten. Die Gegenstelle ist KEIN KiCom-, Mail-, Slack-, Passkey- oder Update-Endpunkt.
2. test-loopback-wire.php: sechs positive lokale Übertragungs-Fixtures für GET-Lesezugriff, synthetisches Slack-/SMTP-/IMAP-/DEV-Format sowie einen Binärblock mit ZIP-Magic, ohne externe Kommunikation. Vergleicht den normalen lokalen PHP-Testserver mit demselben Server bei ausschließlich passiv geladenem Membranbeobachter. Prüft Response-Status 200/201, Header, Body-Bytes, SHA-Receipt und genau einen Request-Receipt pro Instanz. 20 erfolgreiche Checks; der Header und lokale Receipt beweisen nur die synthetische Gegenstelle und die isolierte Datenebene, NICHT die Semantik echter externen Dienste.
3. .github/workflows/test-0927-membrane.yml: zwei weitere isolierte HTTP-Testserver mit Port-/Pfadbindung und eigener nur lokaler Receipt-Datei, Test der 20 positiven Checks zusätzlich zu bisherigen 117 Prüfungen.
4. HOST-BOUNDARY-GATE.md: gezielte Herstellerrecherche aufgenommen. ALL-INKL beschreibt für den dort dokumentierten CGI/FPM-Betrieb eine Ausführung unter dem jeweiligen FTP-Benutzer: https://all-inkl.com/wichtig/anleitungen/kas/domain/edit-settings/settings-overview_503.html. Das ist keine Kontodiagnose des tatsächlichen KiCom-Hosts. Identische PHP-/FTP-UID und gegenseitig beschreibbare Verzeichnisse würden eine unabhängige Policy-Grenze ausschließen; bloße Subdomains oder Ordner sind kein Isolationstest.

## Nachgewiesener aktueller CI-Stand

- GitHub Actions Run 35445907244 (Job 105904665132), exakter getesteter Checkout/Trigger cdc54b2659a6de53b38bdd7697c8fbbe4acee282, conclusion=success. Gleiches erfolgreiches ausführbares Setup im Run 35445832455 (Commit bf059bad8e0ee837ec412395d871c4b58889a24d).
- 28 Shadow-Checks + 24 unveränderte R3-Kommunikationsrouten + 16 lokale native R3 GET/Status-Prüfungen + 27 ungültige R3 POST-/DEV-/RAW-Ablehnungsprüfungen + 16 Exactly-once-/Telemetrie-Ausfall-Checks + 20 positive synthetische Loopback-Übertragungsprüfungen + 6 isolierte Linux-OS-Principal-Grenzprüfungen = **137 bestandene isolierte Tests**.
- Die 20 positiven synthetischen Fixtures dürfen NICHT als erfolgreiche echte Slack-/SMTP-/IMAP-/DEV-/RAW-Update-End-to-End-Transaktionen bezeichnet werden. Authentifizierte Kommunikation, Tokenrotation, tatsächliche Zustellung, IMAP-State, Opera-/Passkey-Login und der Betrieb unter einer durchsetzenden, produktiven Membran bleiben ungeprüft.
- Die native R3-Quelle wurde von den Tests gegen den ursprünglichen Paket-SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f und ihr eigenes MANIFEST.sha256 geprüft. Der Membrancode ist NUR im Entwicklungszweig; unveränderte produktive 0.9.26 ist keine automatische Genehmigung für 0.9.27.

## Konkretes nächstes Ziel und Produktivsperre

1. Tatsächliche Hosting-PHP-Worker-UID/GID, Schreibrechte von FTP/KiCom/Policy/Recovery, separat laufenden Controller und unabhängigen Trust-Root nur über zulässige, lesende Host-Diagnostik belegen. Bei gemeinsamem Hosting-Principal einen unabhängig administrierten Sidecar-/Host-Bereich als Voraussetzung festhalten statt PHP-Unterordner als reale Membran zu bezeichnen.
2. In einer isolierten, wirklich separat berechtigten Staging-Instanz mit eigenem Test-Trust-Root die echten erfolgs- und fehlerbehafteten Kommunikationswege testen; echte Slack-/Mail-Sendungen nur an vorher freigegebene Testziele. Originalrouter/-auth/-updateverifier nicht ändern, bis sichere Parität, Backup und Rollback bestehen.
3. Bestehende protected production/external Action-Boundaries, LKG/Genome, SQLite-Integrität und menschliche Betreiberkontrolle beibehalten. Telemetrie darf fail-open, geschützte Autorisierung niemals fail-open werden. Kein FreeOTP-Code für interne GitHub-Entwicklung.
