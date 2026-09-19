# KiCom — PAM Entwicklung, Release-Identitätsprüfung und robuste CI — Checkpoint 2026-09-19

## Kanonischer produktiver Zustand (abschließend read-only geprüft)
- BOOTSTRAP: live KiCom 0.9.25 / genome kicom-0.9.25-g24. GENOME_STATUS: healthy=true, trusted=true, lkg_ok=true, drift_count=0, unknown_count=0.
- SQLITE_STATUS: primary, WAL, quick_check=ok, native schema_version=1. Das ist kein unabhängiger vollständiger Live-Integritätscheck.
- UPDATE_STATUS: pending_version=0.9.26, pending_risk=red, pending_source=pull:mirror, last_code=STAGED_DECISION_REQUIRED. **Der bestehende öffentliche Status enthält weiterhin keine exakte Pending-ZIP-Prüfsumme.**
- Öffentlich verifiziertes R3-Paket auf GitHub: releases/0.9.26/KiCom-0.9.26-R3.zip; SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f. Ein früheres Paket mit derselben Version besitzt eine andere Prüfsumme. Der Hash des auf dem Server *tatsächlich vorgemerkten* Pakets wurde nicht abgefragt und darf nicht aus der Versionsnummer abgeleitet werden.

## Tatsächlich umgesetzte Entwicklungsarbeiten
1. KiComPamReleaseProof.php: rein lesende Diagnose über bestehende vertrauenswürdige KiCom-Pending-/Package-Path-Helfer; kontrolliert to_version, RED, zip_sha256, sha-gebundene Datei unter dem Managed-Package-Verzeichnis und deren tatsächliche SHA-256. Verweigert manipulierte Datei, abweichende Version-/SHA-Identität, inkonsistente Metadaten sowie Symlinks. Keine Installation, Freigabe, externen URLs oder freien Dateipfade.
2. KiComPamReadOnlyCycle.php: konkreter, intern begrenzter PAM-Zyklus; ingestiert ausschließlich vier durch einen vertrauenswürdigen Caller bereitgestellte feste KCL-Leseendpunkte, kontrolliert Genome/SQLite-Health, reserviert einen SHA-gebundenen *internen Leseprüfauftrag*, prüft die Pending-Datei nach Reservierung erneut, speichert Ergebnis mit Evidenz-Hash und verweigert jede Produktionsautorisierung. Abgelaufene Leases bleiben NEEDS_RECONCILIATION; kein Blind-Retry.
3. test-r3-runtime.php erweitert: getrennte R3- und früheres 0.9.26-ZIP SHA-geprüft; Identität, Manipulation, Symlink-Verweigerung, Gesundheitsgrenzen, idempotente Folgezyklen, geänderte Pakete und native SQLite-Backups einschließlich abgeschlossenem Zyklus geprüft.
4. Auffälligkeit im bestehenden KiCom-Code: kicomSqliteSnapshotMetaFiles sortiert Snapshot-Dateinamen lexikografisch, Snapshot-IDs enthalten Sekundenzeitstempel + Zufallsanteil. Mehrere Sicherungen derselben Sekunde sind daher nicht zuverlässig nach tatsächlicher Erstellungsreihenfolge sortiert. Der PAM-Test prüft gezielt die *konkrete* Snapshot-ID, Manifest-SHA, Dateihash und SQLite-Integrität. Eine Änderung des produktiven Recovery-Kerns wurde bewusst NICHT vorgenommen. Für eine künftige, einzeln geprüfte 0.9.27-Härtung wäre eine monotone/hochauflösende Erstellungsreihenfolge samt Legacy-Fallback und gesonderten Recovery-Tests zu implementieren.
5. GitHub-CI-Race beseitigt: Beide PAM-Workflows checken den auslösenden Commit unveränderlich aus, führen Tests aus und speichern pro Run einen eigenen nicht überschriebenen Bericht. Die Fortschrittsanzeige wird nur aktualisiert, wenn die Run-ID nicht älter ist. Beim parallelen Push-Konflikt wird ausschließlich Fast-Forward mit begrenzter erneuter Fetch-/Checkout-/Commit-/Push-Folge verwendet; kein force push.

## Verifizierte Testergebnisse
- Isolierte Kern-/KCL-/Persistenztests: 32 + 15 + 13 = **60 bestanden**. GitHub-Report: development/0.9.27/pam/status/unit-run-35437502666.txt; CI_RESULT=success; TRIGGER_SHA und TESTED_SHA identisch: 6f8b2a8f8ac02e7bf47042d90c83971efa33d06e.
- R3-Laufzeit-/Release-Identität-/PAM-Zyklus-/Snapshot-Integration: **38 bestanden**. Report: development/0.9.27/pam/status/r3-run-35437491448.txt; CI_RESULT=success, PREFLIGHT_RESULT=success, TEST_STEP_RESULT=success; TRIGGER_SHA und TESTED_SHA identisch: ef513586d4ddb18fcf06c1bfdac78227e156dd25.
- Der ältere Testlauf 35437333170 hatte bereits 38/38 erfolgreiche Tests, sein Ergebnis-Push scheiterte aber an einem zeitgleichen Commit. Er ist ausdrücklich als Test-Erfolg/Workflow-Fehlschlag in status/r3-run-35437333170.txt archiviert, nicht als vollständig grüner Workflow umgedeutet. Die neuen getrennten Reports wurden danach tatsächlich erfolgreich geschrieben.

## Noch NICHT erledigt / geschützte Grenzen
- Weder 0.9.26 R3 noch 0.9.27 wurden installiert. PAM liegt allein im GitHub-Entwicklungsbranch work/kicom-0.9.27-pam und ist noch nicht durch den produktiven Genome-/Modul-Manifest-Verifier aufgenommen worden.
- Exakte Identität des auf dem produktiven KiCom-Server vorgemerkten ZIPs noch offen; das neue Diagnosemodul ist nicht live. Ohne einen bereits autorisierten internen lesenden Diagnosepfad oder reguläre, protokollierte Re-Staging-Prüfung darf der Hash nicht behauptet werden.
- Vor jeder geschützten Produktionsinstallation: exakter Paket-/Manifest-/Genome-Hash, bestehende transaction-bound Freigabe, Backup, unveränderter Verifier, Healthchecks, LKG- und Integritätsprüfung sowie Rollback. Keine FreeOTP-Anforderung für interne Entwicklungsarbeit; niemals externe Freigabe umgehen.

## Nächster sicherer Schritt für Folgeläufe
1. BOOTSTRAP + kanonische Dokumente und frische Live-Statuswerte lesen. Aktuelle CI-/Run-Reports über ihre tatsächlichen TESTED_SHA/CI_RESULT verifizieren.
2. PAM-Runtime-Integration als künftigen, intern vertrauenswürdig gehashte Module eingebundenen 0.9.27-Kandidaten vorbereiten; mögliche v1-Prototypdaten nur mit explizit getesteter reversibler Migration behandeln. Kein Deployment vor geprüftem Elternrelease 0.9.26 R3.
3. Snapshot-Ordering für gleiche Sekunden als separate 0.9.27-Recovery-Härtung entwerfen und auf isolierter Runtime inkl. Legacy-Beständen und Rollback testen.
4. Nur über bestehenden, erlaubten Diagnose-/Updaterpfad die genaue Pending-Paketidentität ermitteln; bei unverändertem RED-Status interne DEV-Aufgaben fortsetzen und keine unautorisierte Installation versuchen.
5. Neue Ergebnisse wiederum als nachvollziehbaren persistenten Checkpoint mit Commit und exaktem Testergebnis sichern. Keinen Erfolg behaupten, bevor das Ergebnis gelesen wurde.
