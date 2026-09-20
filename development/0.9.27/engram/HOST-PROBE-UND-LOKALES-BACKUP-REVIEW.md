# KiCom Engram — Freigabevorlage: synthetischer Host-Test + eigenes Computer-Backup

Status 2026-09-20: **NUR REVIEW/DEV, KEIN INSTALLER, NICHT INSTALLIERT.**
Benutzerentscheidung: Vorbereiten ja; Installation/Serveränderung nur nach gesonderter, gebundener Freigabe. Unabhängige Sicherung soll auf eigenem Computer oder externem Datenträger liegen. Keine kostenpflichtigen Leistungen ohne Rückfrage.

## 0. Was die vorbereitete Archivdatei ist

Die CI-Artefaktdatei \`Engram-Hostprobe-REVIEW-DEV.zip\` enthält ausschließlich öffentlich prüfbaren Programmcode, synthetische Testprogramme und dieses Dokument. Sie ist ausdrücklich KEIN deploybares Update-ZIP und besitzt KEIN KiCom-Release-/Genome-/Installer-Manifest. **Nicht im KAS/WebFTP oder im KiCom-Updateportal hochladen**: ein geprüftes, genau gebundenes KiCom-Release muss später separat aus der verifizierten Live-Basis gebaut und über den bestehenden geschützten Updateweg vorbereitet werden.

Die enthaltenen synthetischen Daten sind erfunden, es wurden weder die produktive KiCom-Datenbank noch persönliche Erinnerungen, private All-inkl-Konfiguration oder echte Schlüssel kopiert. Das öffentliche GitHub-Repository ist Entwicklungs-Mirror, KEIN Backup privater Inhalte und KEIN Vertrauensanker.

## 1. Geplanter, noch NICHT ausgeführter All-inkl-Host-Test

Geplante Änderungen nach gesonderter humaner Freigabe und erfolgreichem Live-Baseline-/Verifier-Preflight:
- Kleiner, separat gebundener DEV-Endpunkt \`DEV_ENGRAM_PATH_PROBE\` in den bestehenden authentifizierten **POST**-DEV-Router aufnehmen. Der heutige GET-DEV-Bridge-Aufruf bleibt für diese Operation gesperrt. Die bestehende WebAuthn-/Passkey-Logik, Rollen, produktive Gedächtnisdatenbank, Auth-Admin, Recovery-Kernel, Secrets und normale Dienste werden nicht für Engram umgebaut.
- Die eng begrenzte Berechtigung \`engram.path.probe\` ausschließlich über die vorhandene KiCom-DEV-Sitzungsprüfung auswerten; keine öffentliche Diagnose-PHP-Seite, kein Shell-, SQL- oder Dateipfad-Proxy. Der Review-Code erzeugt aus exakt unveränderten Original-R3-Modulen isolierte Testkopien, **erzeugt selbst kein installierbares Release**.
- Ein vom Betreiber geprüftes, festes Server-Konfigurationsobjekt wird AUSSERHALB aller Webverzeichnisse eingerichtet. Es enthält ausschließlich die privaten Daten-/Backup-Verzeichnisse und die **vollständige, tatsächliche** Liste sämtlicher Webroots inklusive Default-/Provider-Hostname/Aliasse. Diese Pfade stammen NIE aus einer URL, Chat-Nachricht, GitHub-Testfixture oder einem DEV-Request. Wenn die Liste nicht vollständig verifiziert ist, findet kein Host-Test und keine Freigabe des Speicherorts statt.
- Auf dem Host mit der tatsächlichen KiCom-PHP-Identität wird in den beiden **bereits vorhandenen** privaten Verzeichnissen jeweils genau eine zufällig benannte Datei mit 32 künstlichen Bytes im Modus 0600 erstellt, gelesen und wieder gelöscht. Keine Engram-Datenbank wird angelegt oder verändert; die zuvor manuell angelegte harmlose Testdatei bleibt unverändert. Rückgabe: begrenzte Status-/Bool-Felder, Anzahl geprüfter konfigurierter Webroots und \`public_http_exposure_verified=false\`. Keine absoluten Pfade oder privaten Inhalte im API-Ergebnis.
- Getrennt dazu wird die tatsächliche Web-Erreichbarkeit eines harmlosen Canarys über alle relevanten VHosts/Default-Aliasse und die Isolierung gegenüber anderen PHP-Apps desselben All-inkl-Accounts geprüft. **Ein erfolgreicher Test des KiCom-PHP-Prozesses beweist NICHT, dass eine zweite PHP-App mit gleicher effektiver UID keine Leserechte hat.** Falls gleiche UID/fehlende Trennung bestätigt werden, ist der private Ordner allein KEINE ausreichende Sicherheitsgrenze für echte Erinnerungen.

## 2. Auswirkungen, Rückfall und Abbruchkriterien

Vor Installation: keine Server-, DNS-, Webroot-, Rechte-, Rollen-, Login-, Passkey-, Kernel-, Auth- oder produktive DB-Änderung. Nach späterer **gesondert freigegebener** Installation: nur der genau geprüfte KiCom-DEV-Modul-/Konfigurationsdiff, ein bekannter kurzer DEV-Test, synthetische und anschließend entfernte Testdateien. Echtinhalte und private Schlüssel werden nicht übertragen. Andere Endpunkte behalten ihre bisherigen Befugnisse.

Geschützter KiCom-Verifier, ursprünglicher Paket-/Genome-Hash, Zielversion und genaue Diff-/Dateiliste sind unmittelbar vor der Installation mit der dann tatsächlich installierten Live-Basis abzugleichen. Normale Release-Archivierung, Backup, Healthcheck und Rollback gelten unverändert. Ohne erfolgreichen Verifier, überprüfte vollständige Webroot-Liste, validierten Test-/Rückfallplan oder konkrete Benutzerfreigabe: **ABBRUCH statt Installation.** Keine pauschale Befugnis durch den öffentlichen Download des REVIEW-ZIP.

## 3. Lokaler Computer / externer Datenträger: private Schlüsselhoheit

**Empfohlene Architektur:** Der eigene Computer erzeugt seinen eigenen X25519/Curve25519-Decryption-Key lokal. Nur dessen öffentlicher Schlüssel wird nach Prüfung später an den geschützten KiCom-Konfigurationsweg gegeben; der private Schlüssel verbleibt auf dem Computer und wird niemals auf dem Hosting-Account, GitHub, Slack, Mail oder im Chat abgelegt. KiCom verschlüsselt eine konsistente Snapshot-Datei *vor* dem Transport an diesen öffentlichen Schlüssel. Der Computer prüft das empfangene Bündel offline. Das verschlüsselte Bündel kann anschließend auf einem externen Datenträger, vorzugsweise mit aktivierter Datenträgerverschlüsselung, redundant aufbewahrt werden. Die lokale Schlüsseldatei benötigt ihrerseits einen getrennten, geschützten Wiederherstellungsplan: Ohne sie sind die verschlüsselten Sicherungen nicht lesbar.

Die DEV-Vorbereitung enthält \`engram_local_offline.py\` (plattformübergreifend mit Python 3.11+ und der kostenlosen Bibliothek PyNaCl==1.5.0) und die strikt CLI-/synthetikgebundene \`KiComEngramSyntheticTransfer.php\`. Der nachgewiesene Test erzeugt ausschließlich eine neue synthetische SQLite mit zwei Testrevisionen, verschlüsselt sie an einen **im Test nur auf der Empfängerseite erzeugten** Public Key und prüft auf dem Computer ausschließlich im Arbeitsspeicher deren SHA-256, SQLite-Struktur und vollständige synthetische Revisionskette. Die SQLite-Klartextdatei wird beim Test auf dem Computer nicht auf die Platte geschrieben. Weder die Server-Seite noch das öffentliche Repository erhält den privaten PC-Schlüssel.

Nach späterer Einrichtung auf dem EIGENEN Computer und auf eigene ausdrückliche Anweisung, Beispielablauf:

\`\`\`sh
python3 -m pip install 'PyNaCl==1.5.0'  # kostenlose Bibliothek, auf dem eigenen Computer
python3 engram_local_offline.py generate-key --private-key /GESCHUETZTER-LOKALER-ORDNER/engram.key
# Ausgabe: public_key_hex, public_key_sha256; niemals den privaten Schlüssel versenden.
# Ein synthetisches verschlüsseltes Testbundle über einen später freigegebenen,
# authentifizierten Weg MANUELL auf den Computer übertragen.
python3 engram_local_offline.py inspect-synthetic \
  --private-key /GESCHUETZTER-LOKALER-ORDNER/engram.key \
  --bundle /GESCHUETZTER-LOKALER-ORDNER/engram-synthetic-sealed-....json \
  --expected-bundle-sha256 VORHER_UNABHAENGIG_NOTIERTER_SHA256
\`\`\`

Dieses Beispiel erzeugt KEIN echtes Backup. Kein zweiter kostenpflichtiger Hosting-Account und kein Cloud-Abo erforderlich. Das eigenständige Signieren des Integritätsmanifests, lokal geschützte monotone Revisionsnummern, eine zuverlässige zeitgesteuerte Abholung und der spätere reale, datenschutzgerechte verschlüsselte Export sind **noch nicht eingerichtet**. Reine Verschlüsselung beweist **nicht**, von welchem Server ein Bündel stammt; der separate Signatur- und Vertrauensanker muss vor echter Nutzung hinzugefügt werden.

## 4. Vor dem ersten realen Backup zwingend offen

- Reale Server-PHP-Identität / \`open_basedir\` / Cross-App-Same-UID-Test / Provider-Default-VHost und vollständige Aliasliste nachweisen.
- Auf dem eigenen Computer Schlüsselaufbewahrung samt Wiederherstellung und externe, tatsächlich unabhängig gelagerte verschlüsselte Kopie definieren; lokale geschützte Signatur-/Manifestverankerung und Anti-Rollback nachweisen.
- Korrekte tatsächliche Benutzeridentität, ausdrückliche Einwilligung, Klassifizierung und Provenienz für einzelne Erinnerungen nachweisen.
- Aufbewahrung und vertrauliche Löschung über SQLite-Historie, WAL/SHM, sämtliche Spiegelgenerationen, lokale verschlüsselte Datenträgerkopien und alte Archive implementieren und testen.
- Host-Probe mit künstlichen Daten und echte geschützte Paketinstallation jeweils **gesondert**, anhand des genauen Installationsumfangs und unveränderten KiCom-Freigabeverfahrens, genehmigen.

**Nächster menschlicher Schritt nach REVIEW-Übergabe:** Änderungen und betroffene KiCom-DEV-Module im Paket prüfen, die Server-Konfigurations-/Aliasliste bestätigen lassen und ausdrücklich erst dann die genaue staging/protected Release-Vorbereitung freigeben. Es ist NOCH KEINE Installation erfolgt.
