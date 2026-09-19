# KiCom – Entwicklungscheckpoint: High-Water-Recovery und Crash-Härtung

Datum: 2026-09-19
Repository: cschymura/kicom-update-mirror
Entwicklungszweig: work/kicom-0.9.27-pam
Wichtig: Dies ist ein DEV-Checkpoint, keine Meldung über ein produktives 0.9.27-Update.

## Kanonischer Ausgangszustand

BOOTSTRAP/HELLO: produktiv 0.9.26. GENOME_STATUS: kicom-0.9.26-g25r3, healthy=true, trusted=true, lkg_ok=true, drift=0, unknown=0. SQLITE_STATUS: primary=true, WAL, quick_check=ok, Schema 1. UPDATE_STATUS: kein vorgemerktes Update. In PROJECT_STATE steht teilweise noch das ältere g25r2; NEXT enthält veraltete Installationsprioritäten. Live-Verifikation geht den alten Textangaben vor. Kanonische Daten wurden mangels nachgewiesener interner Schreibautorisation in diesem Durchgang NICHT verändert.

## Getestete neue DEV-Arbeit

1. KiComPamHighWaterVerifier.php: vergleicht die lokale fortlaufende Backup-Kette und die zugehörigen echten Snapshot-/Manifest-Dateien mit einem extern angelieferten High-Water-Anspruch. Fehlende, veraltete, anders formatierte oder widersprüchliche Angaben sowie ein nicht mehr passender letzter Ledger-Hash und zusätzliche nicht registrierte Snapshot-Bytes werden zurückgewiesen. Das Modul verifiziert NICHT selbst die Herkunft oder Unveränderlichkeit des angelieferten Anspruchs. Ein normales PHP-Array oder eine zweite Datei im selben Verzeichnis ist KEIN unabhängiger Vertrauensanker. Die API liefert daher trotz Übereinstimmung immer independent_anchor_authenticated_here=false und restore_permitted=false.
2. test-high-water.php: 13 Tests an isolierten SQLite-Testdaten einschließlich eines absichtlich verkürzten, danach lokal wieder in sich konsistenten Journals. Eine zuvor außerhalb aufbewahrte High-Water-Angabe erkennt den Rollback. Tests auf ungültige, fehlende und veraltete Angaben, Zusatzdateien ohne Manifest und partielle Ledger-Einträge.
3. test-r3-runtime.php: fünf zusätzliche High-Water-/Rollback-Prüfungen mit tatsächlich vom unveränderten R3-Code erzeugten Snapshots, einschließlich vollständiger lokaler Journal-Trunkierung bei erhaltener alter High-Water-Angabe. Eine weitere Prüfung vergleicht Original-DB- und WAL-Prüfsummen unmittelbar vor/nach einem gesperrten, ausschließlich lesenden Recovery-Vorcheck.
4. KiComPamSnapshotSequencer.php verschärft: verifiziert vorhandene Legacy-/Orphan-Bestände BEVOR die vertrauenswürdige Snapshot-Schreibfunktion aufgerufen wird; prüft VOR und NACH dem Schreiben die Anzahl nativer Manifeste und Backup-Dateien, bereits registrierte Bestände vor jeder neuen Aktion, fsync() des neuen Journal-Eintrags und Ablehnung eines symbolischen Links als Lock-Datei. inspect() prüft nun auch nicht registrierte .sqlite-Dateien ohne JSON-Metadaten.
5. test-sequencer.php: 17 Tests nach Erweiterung um frühzeitige Verweigerung bei Orphans und Legacy-Daten, Erhalt der bestehenden Backup-Bytes und zusätzliche .sqlite-Dateien ohne Manifest.
6. Erster nativer Integrationstest der neuen DB/WAL-Prüfsumme fiel fehl, weil sein Ausgangshash VOR einem legitimen nativen Backup mit WAL-Checkpoint ermittelt worden war. Der Prüfhorizont wurde auf unmittelbar vor/nach dem rein lesenden Recovery-Vorcheck verschoben; der anschließende CI-Lauf bestand. Ein zweiter Testlauf wurde an die stärkere Legacy-Fehlersemantik angepasst; fehlgeschlagene Runs bleiben in den immutablen CI-Berichten erhalten.

## Verifizierte CI-Ergebnisse

- Isolierte PHP/PDO-SQLite-Tests: 32 Kern + 22 KCL + 13 Persistenz + 11 Legacy-Snapshot-Auswahl + 17 Sequenzierung + 13 High-Water = **108 bestanden**. development/0.9.27/pam/status/latest-ci.txt, CI_RESULT=success, RUN_ID=35439908370, TRIGGER_SHA=TESTED_SHA=076a90981fd72cc872023f1e2ae423ae53c49d6c.
- R3-Quellcode- und Laufzeit-CI: **10 geprüfte Aufrufwege + 61 Integrationstests = 71 bestanden**. development/0.9.27/pam/status/r3-runtime-ci.txt, CI_RESULT=success, RUN_ID=35439777780, TRIGGER_SHA=TESTED_SHA=6b8b3a863e00cf970a0a76a2b69732d4e8c78337. Das R3-Paket wird vorab exakt SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f und anhand MANIFEST.sha256 verifiziert.
- Gesamt: **179 erfolgreiche DEV-Checks** über diese beiden isolierten Testreihen. Für spätere ausführbare Änderungen die CI-Daten mit exakt neuem TRIGGER_SHA/TESTED_SHA erneut prüfen. Dokumentationscommits sind keine Ersatzevidenz für Tests.

## Recherche und technische Einschränkungen

Relevante Originaldokumentation:
- SQLite Online Backup API: https://www.sqlite.org/backup.html
- SQLite WAL und persistenter Zustand: https://www.sqlite.org/wal.html
- SQLite VACUUM INTO einschließlich Unterbrechung/Powerloss: https://www.sqlite.org/lang_vacuum.html
- PHP flock (advisory): https://www.php.net/manual/en/function.flock.php
- PHP fsync (PHP 8.1+): https://www.php.net/manual/en/function.fsync.php
- Linux fsync und Verzeichnis-Eintrag: https://man7.org/linux/man-pages/man2/fsync.2.html

Die neue fsync()-Prüfung des Ledger-FILES garantiert nicht, dass die Erstellung des Directory-Eintrags im plötzlichen Stromausfall persistiert. Eine dauerhafte unabhängige High-Water-Verankerung und eine vertrauenswürdige, vor Rücksetzung geschützte Quellenprüfung sind noch NICHT implementiert. Die getestete PHP-CLI-Umgebung war PHP 8.2; tatsächliche Produktions-PHP-Version, Verzeichnis-Sync, Dateisystem- und Backup-Medienbedingungen müssen vor Integration ermittelt werden.

## Unverändert offene tatsächliche Systemgrenzen

- Produktiv läuft 0.9.26 mit unveränderter nativer Recovery. Die DEV-Module sind nicht installiert, nicht im produktiven Genome freigegeben und können dort keinen Recovery-Aufruf stoppen.
- Die R3-Funktion kicomSqliteRecover() wählt derzeit kicomSqliteLatestSnapshot(), bevor sie DB/WAL/SHM in Quarantäne verschiebt und beim Fehlen eines verwertbaren Snapshots unter Umständen aus Legacy-Mirror-Daten neu aufbaut. Diese Funktion ist NICHT durch den neuen Preflight geschützt. Ein produktiver Recovery-Sicherheitsnachweis wäre daher unzutreffend.
- Ein externer High-Water-Anspruch ist bisher nur ein Test-Fixpunkt, kein unabhängiger authentifizierter Server-Trust-Root. Das im Test verwendete Entfernen und Wiederherstellen von Dateien betrifft ausschließlich disposable CI-Verzeichnisse, keine produktiven Backups oder Nutzerhistorie.
- Advisory flock erfordert, dass ausnahmslos alle Backup-Writer denselben Lock benutzen. Unangemeldete Legacy-/Orphan-Dateien müssen konservativ behandelt werden; keine automatische Überschreibung, Löschung oder Ersetzung.
- Vor künftiger 0.9.27-Promotion: echter unabhängig geschützter High-Water-Publisher/Verifier, atomare und crash-sichere Publikation inklusive Directory-Metadaten, ausführbare Runtime-Integration an sämtlichen Schreib-/Recovery-Grenzen, sichere Altbestand-Migration, vollständige DB-/WAL-/Backup- und reale Rollback-Prüfungen, Manifest-/Genome-SHA und bestehende transaktionsgebundene Produktionsfreigaben.

## Nächste tatsächlich sichere interne Aufgabe

1. Quellen/KiCom-Schnittstellen für einen von Recovery-DB, Snapshot-Verzeichnis und DEV-Workspace unabhängigen, revisionsgesicherten High-Water-Vertrauensanker inventarisieren. Keine normale GitHub-/Mail-/Slack-Nachricht oder zweite lokale Datei automatisch zur Autoritätsquelle erklären.
2. In isoliertem R3-Klon einen nichtdestruktiven Recovery-Ablauf aufbauen, der vor jeglicher Quarantäne den exakt identifizierten, authentifizierten Kandidaten sowie eine getrennt geprüfte Sicherung der Original-DB/WAL/SHM voraussetzt. Tests für abgebrochene Veröffentlichung, fehlendes Backup, abweichenden Trust-Anker, beschädigte Quelle, parallele Writer und Erhalt der Originaldateien bei Abbruch.
3. Erst nach separat überprüftem Code und einem zur tatsächlichen Live-Umgebung passenden Install-/Rollback-Konzept 0.9.27-Kandidat mit Hash/Genome manifestieren. Keine geschützte Produktionsaktion oder FreeOTP-Anforderung für interne Entwicklung; externe Berechtigungsgrenzen bestehen unverändert.
