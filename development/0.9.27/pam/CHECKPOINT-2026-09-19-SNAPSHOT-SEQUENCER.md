# KiCom 0.9.27 — Snapshot-Sequencer-Checkpoint (19.09.2026)

## Live-Lage, vor Änderungen erneut gelesen
- BOOTSTRAP/HELLO 0.9.26; GENOME_STATUS kicom-0.9.26-g25r3, healthy/trusted/LKG true, drift=0, unknown=0.
- SQLite operational memory primary, journal_mode=wal, quick_check=ok, natives schema_version=1; dies ist keine unabhängig durchgeführte Live-Vollprüfung.
- UPDATE_STATUS pending_version/pending_risk/pending_source leer; die alte 0.9.26-RED-Installationsaufgabe ist erledigt. Keine Installation erneut anstoßen.
- Kanonisches PROJECT_STATE enthält noch das historisch alte g25r2, NEXT noch Installationsprioritäten. Diese Dateien wurden in diesem Durchgang nicht ohne bestehende vertrauenswürdige DEV-/Memory-Autorisierung mutiert.

## Implementiert im GitHub-Zweig work/kicom-0.9.27-pam
- KiComPamSnapshotSequencer.php: Entwicklungsprototyp für einen gemeinsamen flock-geschützten nativen Snapshot-Writer. Bei erfolgreichem nativen Backup + SHA/Manifest/SQLite-Prüfung wird ein eigener sequentieller append-only Journal-Eintrag mit SHA-verketteter Vorgängeridentität angelegt.
- inspect(): prüft sämtliche Ledger-Einträge, alle zum Journal gehörenden nativen Backup-/Manifest-Bytes und lehnt verwaiste/außerhalb der Sequenz erstellte Dateien, Manipulation, Lücken und unklare Zustände ab. Liefert nur die ID/SHA des Kandidaten mit inspection_only=true und restore_permitted=false.
- test-sequencer.php: 12 isolierte Tests, darunter zwei unmittelbar aufeinanderfolgende Backups, Neustart, fehlgeschlagener Writer, simulierte Unterbrechung zwischen Snapshot und Journal, geänderte Metadaten und beschädigte Ledger-Einträge.
- test-r3-runtime.php: vier neue Kompatibilitätstests verwenden zwei echte, bereits im isolierten R3 erzeugte SQLite-Backup-Pakete, kopieren sie in eine unabhängige Fixture und ordnen sie dort über die Sequenz statt einen zufälligen Dateinamen. Nicht registrierte ältere Backup-Metadaten werden nicht stillschweigend eingegliedert.
- README.md dokumentiert Grenzen und die Voraussetzung, dass künftig alle nativen Snapshot-Pfade denselben Lock nutzen müssen. Dieses Modul ersetzt NICHT die produktive R3-Snapshot-/Recovery-Logik.

## Genau geprüfte Testergebnisse
- Isolierte Tests: 32 core + 22 KCL + 13 persistence + 11 legacy order + 12 sequence = **90 bestanden**.
  GitHub: development/0.9.27/pam/status/latest-ci.txt, CI_RESULT=success, RUN_ID=35438530263, TRIGGER_SHA=TESTED_SHA=0cd015db100d515a214ab209b5805ea9f130bf02. Ein früherer grüner Ablauf RUN_ID=35438451582 wurde ebenfalls separat gespeichert.
- R3-Integration: **51 bestanden**.
  GitHub: development/0.9.27/pam/status/r3-runtime-ci.txt, CI_RESULT=success, RUN_ID=35438535247, TRIGGER_SHA=TESTED_SHA=c3637fc250ff5dd4a3aaed4ce739657b0cb64ec4; R3_ZIP_SHA256=6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f.
- Summe **141 bestandene Checks**, sämtlich Entwicklungs-/CI-Prüfungen; keine produktive Anwendung oder Live-Backup-/Restore-Probe. Für später veränderten Code jeweils neue CI-Trigger-SHA prüfen.

## Zu klärende Sicherheits- und Integrationsgrenzen
1. Die bestehende native R3-Routine kann auch ohne Sequencer Backups schreiben. Vor dem produktiven Umbau müssen ALLE Erzeuger (Maintenance, Evolution, manuell, Wiederherstellung) denselben Lock durchlaufen. Sonst ist die neue Reihenfolge nicht vollständig; unbekannte/Legacy-Snapshots => vor Auswahl stop und abgleichen.
2. Die aktuelle Sequenz liegt in einem eigenen lokalen Verzeichnis, dessen Einträge nicht kryptografisch gegenüber einem unabhängigen Trust-Anker gegen vollständige Journal-Trunkierung abgesichert sind. Nicht als alleinige autoritative Recovery-Quelle bewerben; unabhängige LKG-/Archiv-/Genome-Vertrauensketten und durable high-water-Metadaten vor Integration planen.
3. Bei Snapshot nach erfolgreichem Native-Write, aber vor Ledger-Publikation bleibt die Datei als unsicherer Orphan liegen und darf nicht automatisch übernommen werden. Crash-Reconciliation mit nachweisbarer Integrität und expliziter konservativer Policy separat testen.
4. Der jetzige R3-Recover-Code ermittelt einen Kandidaten und quarantiniert anschließend Dateien; ein fail-closed Preflight muss vor jeglicher destruktiven Änderung sitzen und bei Ambiguität bewährte LKG-/Rollback-Daten bewahren. Der neue Sequencer hat KEINE Restore-Methode.
5. Legacy-Zeitstempel-ID mit Zufallsanhang ist innerhalb derselben Sekunde nicht chronologisch; keine unbelegte Migration der Altbestände in eine neue Reihenfolge.
6. Der eigentliche KiCom 0.9.27-Genome-/Manifest-Build, DEV-Session-/Passkey-E2E-Prüfung, Snapshot/Recovery-Promotion und realer Rollback sind noch offen. Kein Einsatz im Live-Core ohne unveränderten Updater-Verifier, SHA/Genome-Prüfung, Backup, Healthcheck und Rollback.
7. Nutzervereinbarung zu Unbekanntem/Recherche und Plugins: development/0.9.27/RESEARCH-AND-TOOLS-POLICY.md. Primärquellen SQLite https://www.sqlite.org/backup.html und https://www.sqlite.org/wal.html; Quellen begründen Backup-Konsistenz, nicht automatisch eine zeitliche Reihenfolge.

## Folgelauf
- Live-BOOTSTRAP und Projekt-/Runtime-Status neu lesen; kanonische g25r2-Nachlaufdaten nur via tatsächlich berechtigten, revisionsbewahrenden Memory-API-Pfad abgleichen.
- Vollständigen Aufrufergraphen der nativen R3-Snapshot-/Recover-Funktionen und externe Sperr-/Härtungsgrenzen inventarisieren; anschließend einen *isolierten* pre-quarantine Recovery-Safety-Test einschließlich legacy/orphan/parallel cases bauen.
- CI-Resultate und SHA an die neuen Änderungen binden, dauerhaften Checkpoint schreiben, keine internen FreeOTP-Codes oder unautorisierte PROD-Aktionen anfordern.
