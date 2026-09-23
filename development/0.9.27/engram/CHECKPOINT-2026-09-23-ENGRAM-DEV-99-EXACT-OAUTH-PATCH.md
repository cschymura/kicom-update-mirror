# MIRAGE DEV-99 — Real native OAuth-Patchfehler beseitigt

Stand: 23.09.2026. **Entwicklung, keine produktive Änderung und kein installierbares Gesamtpaket.**

## Konkreter Fehler im bereits geplanten Original-Paketbau

Der tatsächliche, SHA-256-geprüfte ursprüngliche KiCom-0.9.37-Code `modules/engram/KiComEngramOAuthTransactions.php` enthält im OAuth-Code-Austausch KEINEN Zweig `if($requested===self::COMBINED_SCOPE)`. Der bis DEV98 geplante historische DEV93-Patch markierte exakt diesen NEUEN Zweig im Austausch-Hunk fälschlich als unveränderte Quellcode-Kontextzeile (Präfix Leerzeichen anstelle von `+`). Ein normales `patch --fuzz=0` konnte diesen Hunk auf der tatsächlichen unveränderten Originaldatei daher nicht finden, unabhängig davon, ob die rein syntaktischen Hunk-Zähler zuvor korrigiert waren.

Auf `work/kicom-engram-dev99-oauth-exact-hunk` wurde `dev93/NATIVE-original-oauth-write-consent.patch` tatsächlich korrigiert: die kombinierte Schreib-Scope-Abzweigung ist jetzt eine hinzugefügte Zeile und die dazu passende Hunk-Deklaration lautet `@@ -256,9 +345,16 @@` statt `@@ -256,10 +345,16 @@`. Der Original-READ-Austausch bleibt selbst unverändert, und kein alter DEV82-Austauschpatch darf über DEV94 aufgetragen werden.

Der neue committed Prüfer `dev99/test_dev93_exchange_diff.py` prüft die korrekte Klassifikation der neuen Zeile, sämtliche 8 DEV93-Unified-Diff-Hunks und kann optional mit `--original` die **lokal wirklich vorhandene** 0.9.37-ZIP gegen die bekannte SHA prüfen und den ORIGINAL-Kontext abgleichen. Die aktuelle Arbeitsumgebung enthält den Originalanhang unter `/mnt/data/KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip`; dessen SHA ist nachgewiesen `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7`. Eine direkte lokale Quellcodeprüfung bestätigte, dass die neu eingefügte `if`-Zeile im Original fehlt. In einer späteren Laufzeit ist die ZIP-Verfügbarkeit unabhängig erneut zu prüfen.

## Tatsächlich bestätigte GitHub-Prüfungen

- Erster DEV99 CI-Lauf 35923463257 FAILED aufgrund eines falsch geschriebenen Test-Ankers bei der Suche nach der PHP-`return ['access_token'`-Zeile. Der Testanker wurde anschließend korrigiert; es war kein vorgetäuschter Erfolg.
- GitHub Actions 35923523661, Job 107393004747: SUCCESS. `DEV99_EXACT_OAUTH_HUNKS=8; PARENT_TESTED=False; INSTALLABLE=false`.
- Abschließender GitHub Actions Lauf **35923582702**, Job **107393203789**, SUCCESS; der Workflow umfasst nun zusätzlich `dev97/test_integration_inputs.py` und dessen vollzählige 11 native Patch-/Modul-Eingabekontrolle. Original-ZIP liegt NICHT auf dem GitHub Runner; der Abschlusslauf ist eine Quellcode-/Hunkprüfung, kein tatsächlich ausgeführter vollständiger Original-Quellbaum-Installer.

https://github.com/cschymura/kicom-update-mirror/actions/runs/35923582702

## Bereinigung einer früheren Begründung

DEV98 hat den neuen Passkey-Konstruktor der aktiven DB-Administrationsroute an den bereits vorhandenen First-Party-Konstruktor in `admin.php` angeglichen. Das ist korrekt. Die pauschale frühere Aussage, die ursprüngliche `kicomEngramServerRuntime()` könne grundsätzlich kein Feld `passkey_store` bereitstellen, war dagegen zu weitgehend: `lib.php` liest die genehmigte private Konfiguration, und der ursprüngliche SetupWizard schreibt `passkey_store` als Konfigurationsfeld. Aus dem Vorhandensein eines alternativen neuen Pfadzugriffs allein darf deshalb keine zwangsläufige produktive OAuth-Störung behauptet werden.

## Nächste notwendige tatsächliche Arbeit

Genau EIN SHA-geprüfter Originalquellbaum muss jetzt mit der reparierten DEV93-Datei sowie allen DEV90–DEV98-Quellen über `dev97/stage_native_one_tree.py` mit echten `patch --fuzz=0`-Anwendungen aufgebaut werden. Fehlende Patches dürfen nicht mit höheren Fuzz-Werten übergangen werden. Danach Original PHP+PDO SQLite/WebAuthn/HTTP/MCP, bisherige READ-Kontinuität, separate write consent, Refresh, private Backups, MANIFEST/Genome/Updater/Guardian/Rollback prüfen und EIN vollständiges Paket erstellen. Keine isolierten Zwischeninstallationen, keine Live-Datenbankänderungen ohne gesonderte Freigabe. Nach EINER genehmigten Gesamtinstallation GENAU EIN gezielter neuer Chat-/iPhone-OAuth-Test; scheitert er, keine weiteren automatischen Auth-Reparaturen, stattdessen gemeinsame Belegprüfung KiCom vs. ChatGPT.
