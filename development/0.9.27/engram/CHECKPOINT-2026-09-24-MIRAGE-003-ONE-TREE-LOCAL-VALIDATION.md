# MIRAGE 003 — Wiederherstellung des nativen KiCom-Gesamtquellbaums (24.09.2026)

**Nur isolierte Entwicklungsprüfung. Kein Release, kein produktiver Schreibzugriff und keine Änderung des laufenden KiCom.**

## Nachweis der Quelle und der einmaligen Gesamtzusammenführung

Die bisher in anderen kurzlebigen Laufzeiten als fehlend gemeldete Originaldatei wurde in dieser Instanz tatsächlich als Library-Datei unter dem Namen `KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip` gefunden und in einen isolierten Arbeitscontainer materialisiert. Die Datei ist KEIN Bestandteil des öffentlichen GitHub-Repositories. Die aktuelle lokale SHA-256-Prüfung ergab exakt `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7`. **Der Library-Fund gilt nicht automatisch für spätere Instanzen:** Dateizugriff und SHA dann erneut prüfen.

Öffentliche DEV-Quellen wurden über den bestehenden, erfolgreichen GitHub-Actions-Quellartefakt-Export aus Run `35936528573`, Artifact `10783122857` (Head `9dd8dd471c7683b173e51924d2ee04835bfc702f`), frisch geladen. Der zum Prüfzeitpunkt aktive Entwicklungsbranch `work/kicom-engram-dev100-one-tree-validation` hatte Head `7f8d1cbcfeed89622fb2f3856d3b382ea145840a`; die danach hinzugekommenen Commits zu DEV-102 betreffen nur Checkpoint-/Nachtbericht, nicht neue Runtime-Quellen.

Mit `dev97/stage_native_one_tree.py` wurde die vollständige ORIGINAL-0.9.37-ZIP erneut ausschließlich lokal aufgebaut: **11/11 native Patches mit GNU patch --fuzz=0 erfolgreich**, 13 zusätzliche native Module, **74/74 PHP-Syntaxprüfungen erfolgreich**. `dev97/test_integration_inputs.py` meldete `DEV97_INTEGRATION_INPUTS_VALID=25`. Der vollständige, nichtinstallierbare native Baum wurde nicht zu einem Release umbenannt.

## Lokal tatsächlich durchgeführte Prüfungen

- `KICOM_STAGE_ROOT=<isolierter nativer Baum> php dev100/test_full_staged_native_protocol.php`: **6/6** synthetische Protokoll-Assertions bestanden: unverändertes reines READ-Angebot ohne vom Server injizierten Writer; genau vier Tools mit explizitem Writer; read-only verweigert write; synthetische Schreib-Callback-Durchleitung; Client-Owner-Override abgewiesen; inaktiver Host verschweigt Tools. Die PDO-Verbindung dieses Tests ist ein Testdouble, **keine** SQLite-Echtverbindung.
- `dev100/prepare_nonproduction_genome.py`: nur im isolierten Staging wurden 95 Manifest-Einträge, 88 Genome-Komponenten und 65 Moduldeklarationen erstellt, Kennung `kicom-0.9.38-g37-mirage-mcp-write-staged`. Bestehende neue Module bleiben in der Registry standardmäßig inaktiv.
- `dev96/verify_native_release.py --parent <SHA-verifizierte ZIP> --candidate <Staging>`: `ok:true`, `mode:candidate-preflight`, **`candidate_ready:false`** (nur Abstammung/Metadaten).
- Isolierter localhost-HTTP-Smoke: `POST /api.php?q=ENGRAM_MCP` ohne Betreiber-Host-Konfiguration => **HTTP 404, 0 Antwortbytes**. Der native PHP-CLI-Webserver unterstützt kein Apache-.htaccess-Rewrite; dessen direkte `/.well-known/oauth-authorization-server`-Antwort `HTTP 500 STORAGE_UNAVAILABLE` ist kein erfolgreicher OAuth-Test und kein belastbarer Nachweis einer Produktivstörung.

## Aktuelle konkrete Blocker

Diese Container-PHP-CLI ist PHP 8.4.23, aber `PDO::getAvailableDrivers()` ist leer; insbesondere **fehlt ext-pdo_sqlite**. Der neue, im aktuellen Quellartefakt vorliegende `dev100/test_native_sqlite_full_tree_gate.php` (einschließlich DEV-102-Korrektur für erhaltene Altbackups) wurde deshalb **nicht** als positiver nativer PDO-Test ausgegeben. Ohne realen SQLite-PDO-Test bleiben private aktive DB-Migration/Backups/Read-Kontinuität unbestätigt. Es fehlen darüber hinaus die echte First-Party-Admin-/Passkey-/CSRF- und HTTP/MCP-OAuth-/Refresh-/Revoke-/Write-/Replay-Integration sowie der unveränderte Original-Updater-/Rollback-/Guardian-Gate auf dem vollständigen Kandidaten. Kein vollständig installierbares ZIP erstellt.

## Nächster gezielter Schritt

Den jetzt nachweislich verfügbaren SHA-pinnen Originalanhang mit dem aktuellen öffentlichen DEV-Quellbaum in **einer** Umgebung mit echtem PHP `ext-pdo_sqlite` zusammenführen; DEV-102-Whole-tree-SQLite-Gate unverändert mit synthetischen Privatdaten ausführen und Ausgaben/Fehler dokumentieren. Nur nach dessen Erfolg auf demselben nativen Baum OAuth/WebAuthn, vier MCP-Operatoren, Update/Restore/Rollback prüfen. **Keine Teilinstallation, keine Live-DB- oder Rechteänderung ohne gesonderte Betreiberfreigabe.** Keinen privaten Originalanhang oder reale Daten in öffentliche GitHub-Quellen hochladen.
