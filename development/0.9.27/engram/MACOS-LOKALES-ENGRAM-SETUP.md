# KiCom Engram — macOS: lokaler Schlüssel und verschlüsselte Sicherung (DEV-18)

**Stand 2026-09-20. Vorbereitung für den EIGENEN Mac; NICHT auf dem Mac ausgeführt. Kein Server-Upload/keine Installation und keine echte persönliche Speicherübernahme.** Kostenloser lokaler Schlüssel- und synthetischer Transferworkflow; keine Cloud, kein zweiter Hosting-Account.

## Architektur und Sicherheitsgrenze

- Der Mac erzeugt ausschließlich lokal den privaten X25519/Curve25519-Entschlüsselungsschlüssel. **Niemals dessen Datei oder Bytes in ChatGPT, GitHub, Slack, Mail, WebFTP, die KiCom-Webseite, iCloud Drive, öffentlichen oder geteilten Ordner legen.**
- Der passende PUBLIC Key darf später, nach Prüfung und kontrollierter Freigabe, über eine authentifizierte KiCom-Konfiguration an den Server. Er ist kein geheimer Wert. **Den öffentlichen Schlüssel nicht mit einer Installationsfreigabe verwechseln.**
- Nur ein auf dem Server bereits verschlüsseltes SQLite-Snapshot-Bundle darf später auf den Mac/externen Datenträger gelangen. Keine Klartext-Datenbank durch Browser/FTP herunterladen; kein produktiver Export ist derzeit implementiert. Die vorhandene PHP-Funktion erzeugt ausschließlich zwei künstliche Revisionen in einer neuen Testdatenbank.
- Der private Schlüssel ist für die spätere Entschlüsselung unerlässlich. Eine separat geschützte Wiederherstellungskopie des Schlüssels und ein prüfbarer Wiederherstellungsablauf sind vor echten Erinnerungen Pflicht. Eine verschlüsselte Sicherung ohne ihren privaten Schlüssel ist nicht lesbar.
- FileVault auf dem Mac aktivieren/prüfen: Systemeinstellungen -> Datenschutz & Sicherheit -> FileVault. Den FileVault-Wiederherstellungsschlüssel nicht im Chat oder Repository teilen. Eine separate externe verschlüsselte APFS-Festplatte für *verschlüsselte* Bundle-Kopien ist sinnvoll; **eine vorhandene Festplatte nicht formatieren/löschen**, ohne vorher gesondert ihre Daten zu sichern. Bei iCloud/Time Machine, externen Synchronisierungsprogrammen und Mac-Backups die private Schlüssellage und deren Verschlüsselung gesondert überprüfen.

## A. Voraussetzungen zunächst im Terminal prüfen — keine Schlüsselgenerierung

Terminal öffnen. Die folgenden Befehle geben nur Software-/Statusinformationen aus, keine privaten Schlüssel.

```sh
sw_vers -productVersion
python3 --version
fdesetup status
python3 -c 'import sys,sqlite3; print("Python >=3.11:", sys.version_info >= (3,11)); conn=sqlite3.connect(":memory:"); print("SQLite deserialize:", hasattr(conn,"deserialize")); conn.close()'
```

**Nur fortfahren**, wenn Python >= 3.11 und `SQLite deserialize: True` angezeigt werden, FileVault aktiv ist und der Mac ein privates, nicht gemeinsam genutztes Benutzerkonto verwendet. Falls `python3` fehlt oder zu alt ist: mit einer kostenlosen, vertrauenswürdigen Python-3.11+-Installation fortsetzen (z.B. python.org); nicht eine ungeprüfte fremde Installerdatei starten. Wenn FileVault aus ist, zuerst den Mac gemäß Apple-Anleitung absichern; das kann eine separate Benutzeraktion erfordern.

## B. Private lokale Umgebung einrichten

Den folgenden Codeblock im Terminal ausführen. Einzig die kostenlose Paketinstallation und der Download des **öffentlich bekannten, auf den ausführbar getesteten DEV-18-Commit fixierten** Python-Skripts erfordern Netzwerk. Alle späteren Schlüssel-/Entschlüsselungsaktionen laufen lokal. Bevor das heruntergeladene Skript gestartet wird, kann der Dateitext mit `less "$ENGRAM_MAC/engram_local_offline.py"` überprüft werden.

```sh
set -e
umask 077
ENGRAM_MAC="$HOME/Library/Application Support/KiCom/Engram"
mkdir -p "$ENGRAM_MAC/private" "$ENGRAM_MAC/inbox"
chmod 700 "$ENGRAM_MAC" "$ENGRAM_MAC/private" "$ENGRAM_MAC/inbox"
python3 -m venv "$ENGRAM_MAC/venv"
"$ENGRAM_MAC/venv/bin/python" -m pip install --disable-pip-version-check 'PyNaCl==1.5.0'
curl --fail --location --proto '=https' --tlsv1.2 \
  'https://raw.githubusercontent.com/cschymura/kicom-update-mirror/28507e69ca2e32b1b6d71383c43acd0fcaf6e3f9/development/0.9.27/engram/engram_local_offline.py' \
  --output "$ENGRAM_MAC/engram_local_offline.py"
"$ENGRAM_MAC/venv/bin/python" -c 'import sys,sqlite3,nacl; assert sys.version_info >= (3,11); c=sqlite3.connect(":memory:"); assert hasattr(c,"deserialize"); c.close(); print("ENGRAM_MAC_LOCAL_PREREQUISITES_OK")'
```

Der Mac-Speicherort unter `~/Library/Application Support/KiCom/Engram/` ist bewusst lokal gewählt, statt im Desktop/Dokumente/iCloud Drive zu liegen. **Ein ausdrücklich aktiviertes iCloud-/Backup-Produkt könnte dennoch auch andere Benutzerordner sichern**; das muss der Betreiber prüfen. `chmod 700/600` schützt nicht gegen Programme, die unter derselben Mac-Benutzerkennung laufen.

## C. Privaten Schlüssel ERST auf dem Mac erzeugen

```sh
ENGRAM_MAC="$HOME/Library/Application Support/KiCom/Engram"
"$ENGRAM_MAC/venv/bin/python" "$ENGRAM_MAC/engram_local_offline.py" generate-key \
  --private-key "$ENGRAM_MAC/private/engram.key"
stat -f '%Sp %N' "$ENGRAM_MAC/private/engram.key"
```

Es muss eine **neue 32-Byte-Schlüsseldatei, die das Programm nicht überschreibt** entstehen, mit Zugriffsrechten `-rw-------`. Der Befehl zeigt zusätzlich `public_key_hex` und `public_key_sha256`. Diese Metadaten dürfen bei einem späteren kontrollierten Konfigurationsschritt verwendet werden; der **Inhalt von `engram.key` und sämtliche Hex-Dumps daraus dürfen NIEMALS weitergeleitet werden**. Keinen Screenshot mit Dateiinhalten teilen. Ein erneutes `generate-key` auf dieselbe Datei muss verweigert werden. Verliere die Schlüsseldatei nicht: sie kann aus dem öffentlichen Schlüssel nicht rekonstruiert werden.

Die private Schlüsseldatei nicht ohne Konzept in Time Machine/iCloud aufnehmen. Vor echtem Einsatz eine **separate, verschlüsselte und offline verwahrte Wiederherstellungskopie** des privaten Schlüssels festlegen und deren Wiederherstellung prüfen; niemals den einzigen Schlüssel auf dem gleichen externen Datenträger wie die einzige Sicherung belassen.

## D. Synthetisches verschlüsseltes Bundle prüfen — erst wenn eines autorisiert bereitliegt

Das aktuelle Server-Review-ZIP ist **kein** verschlüsseltes Bundle, und die KiCom-Installation enthält derzeit **keinen** produktiven Erinnerungs-Exporter. Dieser Schritt bleibt bis zu einem separat überprüften künstlichen Bundle aus einem kontrollierten Test ausgesetzt.

Kopiere später ausschließlich die freigegebene, bereits verschlüsselte Datei `engram-synthetic-sealed-....json` in `$ENGRAM_MAC/inbox`. Halte ihren SHA-256 **unabhängig von der Datei und über einen verifizierten Transportweg** fest. Die Hashprüfung allein bestätigt nicht die Identität des Senders; die separat entwickelte Signatur-/Anti-Rollback-Prüfung ist noch nicht produktiv verdrahtet.

```sh
ENGRAM_MAC="$HOME/Library/Application Support/KiCom/Engram"
"$ENGRAM_MAC/venv/bin/python" "$ENGRAM_MAC/engram_local_offline.py" inspect-synthetic \
  --private-key "$ENGRAM_MAC/private/engram.key" \
  --bundle "$ENGRAM_MAC/inbox/engram-synthetic-sealed-....json" \
  --expected-bundle-sha256 HIER_DEN_UNABHAENGIG_BESTAETIGTEN_64_STELLIGEN_SHA256_EINTRAGEN
```

Bei Erfolg bestätigt der Client **nur künstliche Testdaten** (2 geprüfte Revisionen), gibt keine Erinnerungsinhalte aus und legt kein entschlüsseltes SQLite-Backup auf dem Mac ab. **Dies ist noch kein Restore-Drill mit echten Erinnerungen und keine automatisierte Sicherung.**

## E. Externes Laufwerk: noch keine Schreibaktion

Für einen späteren zweiten Speicherort ein vorhandenes Laufwerk mit bereits aktiver, überprüfter Verschlüsselung wählen. Lediglich das verschlüsselte Bundle dorthin kopieren, nicht die einzige verfügbare Schlüsseldatei. Externe Datenträger mit vorhandenen Daten keinesfalls durch einen pauschalen Formatierungsbefehl behandeln. Aufbewahrungsfristen, Rotation und vertrauliche Löschung aller älteren Kopien müssen vor dem ersten echten Erinnerungsbackup entschieden und erprobt werden.

## Grenzen / nächsten überprüfbaren Zustand

1. In CI wurde die PHP->Python-Übertragung mit zwei künstlichen SQLite-Revisionen geprüft (DEV-18, run 35507938960); die oben angegebenen Schritte wurden **noch nicht auf dem echten Mac ausgeführt** und sind kein macOS-spezifischer CI-Nachweis.
2. Der private Mac-Schlüssel ist ein Entschlüsselungsschlüssel, **kein** Ed25519-Signaturschlüssel. Letzterer sowie signierte vertrauenswürdige Bestandsverzeichnisse/Anti-Rollback müssen getrennt eingerichtet werden.
3. Keine realen Erinnerungen importieren/exportieren, bevor Benutzeridentität/Einwilligung, erprobte Löschung über ALLE Spiegel-/Offline-Backups, All-inkl-Host-Isolation und eine bestätigte Release-/Restore-Prozedur vorhanden sind.
4. Nach Abschluss von B/C bitte nur den Status zurückmelden (`ENGRAM_MAC_LOCAL_PREREQUISITES_OK`, `-rw-------`, erstellt oder Fehlermeldung **ohne Schlüsselmaterial**). Den privaten Schlüssel niemals anfordern oder über den Chat verteilen.
