# KiCom Membrane – Kommunikationshärtung und Host-Grenze

Datum: 2026-09-19. Entwicklungszweig: work/kicom-0.9.27-pam.
Vorgänger: development/0.9.27/membrane/CHECKPOINT-2026-09-19.md.

## Unveränderte produktive Ist-Lage (read-only verifiziert)

KiCom 0.9.26 / kicom-0.9.26-g25r3, Genome healthy=true, trusted=true, LKG OK, drift_count=unknown_count=0. SQLITE_STATUS: primary, journal_mode=wal, quick_check=ok, Schema 1. UPDATE_STATUS ohne pending release. PING, HELLO, SLACK_STATUS, MAIL_STATUS und CHAT_UPDATE_STATUS lesend abrufbar. Slack configured=false, Mail configured=true; dies belegt keine realen Send-/Receive-Transaktionen. Keine Live-Veränderung an Auth, Core, SQLite, Genome, Netzwerk oder externen Nachrichten vorgenommen.

## Neu hinzugefügte DEV-Tests und Arbeitsartefakte

- test-post-wire.php: 9 sichere negative POST-Fixtures auf ZWEI isolierten nativen R3-PHP-Testservern, einmal ohne und einmal mit passiv vorgeladenem KiComMembraneShadow.php. Die POST-Statuscodes, Content-Types und vollständigen Fehlerantworten der geprüften unauthorisierten KCL-, JSON-DEV-, Batch-/Memory- und RAW-Update-Aufrufe bleiben nach alleiniger Normalisierung der volatilen KCL-request_id gleich. Sämtliche unberechtigten Anfragen bleiben zurückgewiesen, ohne einen Sitzungstoken zurückzugeben. 27 Tests, kein gültiger Sessiontoken, echter Empfänger oder echtes Updatepaket verwendet.
- KiComMembraneTransparentTap.php und test-transparent-tap.php: ausschließlich isolierte DEV-Vertragsprobe einer optionalen Metadatenbeobachtung. Ruft eine bereits existente Operation GENAU EINMAL auf, verändert Rückgabe/Binary-Body nicht, reißt weder Auth- noch Transportausnahmen an sich, wiederholt keine Requests und blockiert die Operation auch dann nicht, wenn die optionale Beobachtung scheitert. Die bestehende geschützte Aktions-/Authentifizierungsgrenze bleibt weiterhin Teil der originalen Operation; kein Forwarder oder echter Proxy wurde produktiv aktiviert. 16 Tests.
- HOST-BOUNDARY-GATE.md: echte, noch nicht belegte Voraussetzungen für voneinander getrennte Runtime-UIDs, separate Policy-/Recovery-Eigentümer, Sidechannel-kontrolliertes Action-Enforcement, unabhängige Recovery-Anker, bidirektionale E2E-Kommunikation sowie sichere Aktivierung/Rollback. Ergebnis **HOST-EIGNUNG UNBEKANNT; PRODUKTIVE MEMBRAN-ENFORCEMENT GESPERRT**. Bestehende KCL-/GitHub-Diagnostik zeigt keine Unix-UID/GID, Dateirechte, Webworker-Identität oder Host-Policy-Domäne. Keine produktiven Probe-Dateien, Privilegienversuche oder Host-Änderungen durchgeführt.
- Aktuelle Referenzen: https://www.php.net/manual/en/ini.core.php und https://www.php.net/security-note.php (open_basedir ist keine umfassende eigenständige Sicherheitsgrenze; bei kritischen Grenzen echte OS/Host-Isolation erforderlich), NIST SP 800-207 https://doi.org/10.6028/NIST.SP.800-207.

## Tatsächlich verifizierter CI-Lauf

GitHub Actions Workflow .github/workflows/test-0927-membrane.yml
RUN_ID=35443628475, JOB_ID=105898697911,
auslösender/testierter Code-Commit d44373fca0dc42dbf48f48a48f1e5532b3a04cd0.
Workflow conclusion=success; Quell-Manifest SHA-Prüfung, PHPlint, sämtliche Regressionen und Linux-UID-Test erfolgreich.

- 28 passive Shadow-Tests
- 24 Quell-/Kommunikationsvertrags-Tests gegen unveränderten, manifestgeprüften R3-Code
- 16 lokale GET-/HTTP-Kompatibilitätstests
- 27 zusätzliche lokale negative POST-Kompatibilitätstests
- 16 genau-einmalige Ausführung/Telemetrie-Ausfall-Vertragsprüfungen
- 6 unabhängige OS-Dateirechte-/Identitätsprüfungen im Linux-Labor

**117 Checks erfolgreich, isoliert.** Kein vollständiger produktiver Versand-/Empfangstest und kein Nachweis einer tatsächlich durchgesetzten Produktions-Membran. Die durchgeführten POST-Fixtures prüfen ausschließlich Fehler-/Ablehnungswege, NICHT erfolgreiche Versand- oder Tokenrotationswege. Das PING/HELLO/Status-Live-Reading bestätigt lediglich Erreichbarkeit und aktuell gemeldete Gesundheit.

## Wichtige offene Schritte

1. Welche OS-/PHP-FPM-/FTP-Laufzeitidentitäten und welche prozess-/dateisystemseitigen Grenzen sind auf dem tatsächlichen produktiven Hosting belegt? Bisher unbekannt. Nur bereits autorisierte read-only Host-Admin-Diagnostik verwenden; aus bloßen Web-/FTP-Unterordnern keine Unabhängigkeit folgern. Falls nicht möglich, separat administrierbare Staging-/Host-/Sidecar-Grenze planen.
2. Vor Enforcement einen Host-owned, vom Innenraum nicht modifizierbaren Controller unter separatem Principal testen; ausgehende erlaubte Kommunikation weiterhin durch Original-KiCom-Gates, keine neue Ermächtigung aus Daten/Telemetrie. Betriebszustand unter Controller-Ausfall und Betreiberzugriff auf Recovery prüfen.
3. Vollständige Transport-Parität in Staging nachweisen: GET/POST/Binary/Chunk, bestehende Passkey-/TOTP-/Session-Rotation, Slack-Inbound-/Outbound, Mail IMAP/SMTP, Opera, echte Update-Transfers, Timeout und Rollback. Erfolgreiche extern zugestellte Nachrichten nur an eigens genehmigte Testziele.
4. Produktion bleibt unangetastet, solange OS-Hostgrenze, Recovery-Unabhängigkeit, exakte Paket-/Genome-Hashes, Backups und echte Kommunikationstests fehlen; kein FreeOTP-Code für interne GitHub-Entwicklung.
