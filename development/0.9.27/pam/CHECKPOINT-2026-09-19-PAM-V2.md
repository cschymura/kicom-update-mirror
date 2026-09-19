# KiCom Entwicklungscheckpoint — 2026-09-19 — PAM v2

## Verifizierter Live-Stand (nur lesend)

BOOTSTRAP / HELLO: KiCom 0.9.25 (kanonischer Projektstand weiterhin auf dem Server).
GENOME_STATUS: kicom-0.9.25-g24; healthy=true, trusted=true, lkg_ok=true, drift_count=0, unknown_count=0.
SQLITE_STATUS: primary=true, WAL, quick_check=ok. Dies ist keine unabhängige Vollprüfung der Live-Datenbank.
UPDATE_STATUS: pending_version=0.9.26, pending_risk=red, pending_source=pull:mirror, last_code=STAGED_DECISION_REQUIRED. Die API liefert KEINEN pending-package SHA-256.
Der öffentliche Mirror-Feed verweist auf das separat veröffentlichte R3-ZIP mit SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f. Die Identität des bei KiCom tatsächlich vorgemerkten RED-Pakets ist damit weiterhin NICHT bestätigt. Die ältere 0.9.26-Datei hat eine andere Prüfsumme und bleibt archiviert.

## In diesem Entwicklungszyklus umgesetzt

- PAM-Quellcode in work/kicom-0.9.27-pam überarbeitet: Ein abgelaufener LEASED-Status wird atomar auf NEEDS_RECONCILIATION gesetzt, statt eine möglicherweise bereits ausgeführte Aktion automatisch erneut zu reservieren.
- Neues reconcileInternal verlangt eine SHA-256-referenzierte Abgleichbeobachtung und erlaubt nur READY/SUCCEEDED/FAILED/BLOCKED für eine vorher unsichere INTERNE Aktion. PAM verifiziert dabei die Quelle nicht selbst; der vertrauenswürdige Caller muss die tatsächliche Zielwirkung und jede echte Berechtigung unabhängig prüfen. Externe Aktionen werden durch PAM nicht entsperrt.
- Die alten, für diese neue Semantik inkorrekt gewordenen Tests wurden korrigiert und um Neustart-, Wiederaufnahme- und Reconciliation-Fälle ergänzt.
- Der KCL-Adapter weist falschen Erfolgsstatus, abgeschnittene Antworten ohne END und Daten hinter dem Terminator zurück.
- test-persistence.php verifiziert Neustart mit SQLite WAL, Erhalt unklarer Aktionen, SQLite integrity_check, VACUUM-INTO-Snapshot und Wiederherstellung einschließlich bestehender KiCom-Datentabelle. Keine Änderung der produktiven KiCom-Datenbank.
- PAM-Prototypschema v2; v1-Prototypdatenbanken werden ohne explizit geprüfte Migration zurückgewiesen. Dies ist NICHT die produktive KiCom-SQLite-Schemaversion.
- Isolierte GitHub-CI nach Korrektur des PHP-Testfixtures erfolgreich: RUN_ID 35436741499, getesteter Commit 922b14067c3f09c034b4c0a17060e4359dd850ae, 32 PAM-Kern + 15 KCL + 13 Persistenz = 60 Tests bestanden. Frühere fehlgeschlagene CI-Läufe beim Umstellen der Tests sind durch die Fehlerkorrektur überholt, bleiben in GitHub nachvollziehbar.
- Dokumentation: development/0.9.27/pam/README.md; Protokoll: development/0.9.27/pam/status/latest-ci.txt. Weitere README-/Checkpoint-Commits nach dem getesteten Code erfordern bei etwaigen späteren Quellcodeänderungen einen neuen passenden CI-Nachweis.

## Was ausdrücklich noch NICHT erfolgt ist

Keine Installation von 0.9.26 oder 0.9.27. Keine Aktivierung des PAM-Moduls in einem echten KiCom-Prozess. Kein neuer Genome-Trustanker, keine produktive Migration, keine RED/FreeOTP-Freigabe, keine Veränderung des bestehenden Updater-Verifiers. SQLite-Sicherungstest ist ein isoliertes Testdatenbank-Szenario, kein Backup des Live-Systems.

## Nächste fachliche Schritte

1. Im unveränderten KiCom-Updater die tatsächlich vorgemerkte ZIP-Prüfsumme mit der R3-SHA-256 abgleichen; version-only genügt nicht. Bei abweichendem/fehlendem Hash keine Installation behaupten oder freigeben.
2. Parallel die PAM-v2-Schemamigration/Kompatibilität und den bounded read-only Observer mit einer isolierten Kopie des echten 0.9.26-R3-Runtimes testen; wiederverwendbare, deterministische und SHA-gebundene Testergebnisse sichern.
3. Für die Nachtschicht: aktuelle Beobachtung erfassen, den letzten Checkpoint lesen, nächste interne Aktion atomar reservieren, bei Ablauf nur nach tatsächlich überprüfter Wirkung reconciliieren und danach Ergebnis mit Evidenz-Hash checkpointen. Echte Berechtigungen ausschließlich am Executor prüfen.
4. Produktionsinstallation erst nach exakt gebundener RED-Freigabe, vollständigem Backup, normalem Verifier, Healthchecks, Genome/LKG/Drift-Kontrolle und Rollback. Ohne erforderliche Freigabe interne Entwicklung fortsetzen, keine FreeOTP-Anforderung dafür.
