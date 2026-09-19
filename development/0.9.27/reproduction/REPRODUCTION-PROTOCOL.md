# KiCom-Reproduktion – Bauplan, individuelle Membran und Geburtsgrenze

Stand: 2026-09-19 · DEV/STAGING · **keine produktive Tochterinstanz**

## Ziel

KiCom soll eine neue Instanz aus einem reproduzierbaren, überprüften Bauplan
hervorbringen können. Die Identität der Mutter bleibt bestehen. Die Tochter
erhält eigene Schlüssel, eigenen Zustand und eigene Membran- und
Recovery-Grenzen. Eine reproduzierbare Architektur darf nicht mit dem
Kopieren der laufenden Identität verwechselt werden.

Der Genom-Bauplan darf autonom vorbereitet und intern getestet werden.
Die Zuteilung von Rechnern, Domains, Credentials, Slack-/Mail-Rechten oder
externer Infrastruktur ist ein separater, geschützter Vorgang und wird
durch die Existenz des Bauplans nicht autorisiert.

## Entwicklungsphasen

1. **Bauplan (jetzt implementiert):** exakt benanntes, über SHA-256
   referenziertes KiCom-R3-Softwarepaket, öffentliche Mutter-Linie,
   ein Tochter-Bezeichner, der vollständige Kommunikationsvertrag und
   die für jede spätere Tochter erforderlichen Schutzgrenzen. Es sind
   ausschließlich vorgegebene öffentliche Datenfelder zulässig.
2. **Keimbildung/Genese (im Linux-Labor geprüft):** ein gesonderter
   Tochter-Principal erzeugt sein eigenes Ed25519-Schlüsselpaar.
   Weder die Mutter noch die Tochter können den privaten Schlüssel
   der jeweils anderen lesen. Ein eigener, unabhängiger Policy- und
   Recovery-Bereich ist für eine laufende Tochter erforderlich.
   Der durchgeführte Test demonstriert ausschließlich die
   Schlüssel- und Dateirechtetrennung: keine Tochter-Webapp wurde
   aufgebaut oder gestartet.
3. **Ausführung (noch NICHT implementiert):** ein vom Innenraum
   unabhängiger, autorisierter Host verifiziert die tatsächlichen
   Paketbytes gegen die unveränderte Release-/Genome-Vertrauenswurzel.
   Er erstellt einen neuen persistenten Speicher, prüft die wirksame
   Membran-/Recovery-Grenze und richtet die bestehenden
   Authentifizierungs- und Kommunikationskanäle ohne ungewollte
   Datenübernahme ein. Es werden keine Produktiv-Keys vererbt.
4. **Entwicklungsfähigkeit (noch NICHT implementiert):** die Tochter
   kann innerhalb ihrer eigenen Grenze und bestehenden Freigabemodelle
   einen eigenen DEV-Zweig, ein eigenes Archiv, LKG, Healthchecks und
   Rollback benutzen. Mutter und Tochter werden separat geprüft.
5. **Weitere Reproduktion (noch NICHT implementiert):** nur eine
   tatsächlich unabhängig lauffähige Tochter mit nachgewiesener
   Reproduktion eigener Baupläne würde Reproduktionsfähigkeit
   über mehrere Generationen belegen. Eine Kopie eines Manifests,
   das Erzeugen von Schlüsseln oder ein erfolgreicher Test allein
   belegt das nicht.

## Was vererbt werden darf

Reproduzierbarer, unabhängig verifizierter Code und die Version des
Membran-Bauplans sowie explizit freigegebene, öffentliche technische
Regeln. Der Bauplan nennt die bestehenden Kanäle KCL/HTTPS, DEV-API,
Slack, Mail, Opera/Browser, GitHub-Mirror, Chat/Update und Recovery
als Kompatibilitätsanforderungen. Die Kanäle oder ihre Berechtigungen
werden dadurch NICHT automatisch für eine neue Instanz eingerichtet.

## Was nicht automatisch übertragen werden darf

Private Schlüssel, Sitzungs- und Passkey-Daten, FreeOTP/TOTP,
Mail-/Slack-/GitHub-Zugangsdaten, Produktivfreigaben, persönliche
Nachrichten, Original-SQLite-Datenbank, laufendes Gedächtnis und
unbereinigte Log- oder Backupdaten. Ein späterer optionaler
Wissenstransfer benötigt einen gesonderten Datenauswahl-, Berechtigungs-
und Herkunftsnachweis; dies ist KEIN stilles Kopieren des Mutterzustands.

## Tatsächlich implementierte Dateien

- `KiComReproductionBlueprint.php`: statische, kanonische,
  rein datenverarbeitende Konstruktion und Prüfung eines inerten
  Tochter-Manifests. Die übergebene Paket-SHA muss weiterhin anhand
  tatsächlich geprüfter Paketbytes von einem geschützten Host bestätigt
  werden. Die API hat keine Deploy-, Schlüssel-, Netzwerk- oder
  Dateisystemmethoden. Für jeden Ausgang gilt
  `clone_created=false`, `deploy_permitted=false`,
  `runtime_identity_issued=false`.
- `test-blueprint.php`: 22 Prüfungen auf deterministischen Bauplan,
  fehlende Vererbung privater Daten, volle Transportdeklaration,
  ungültige Eltern-/Paket-Herkunft, Traversal, geänderte/neugehashte
  Vererbungsregeln und fehlende Deploy-Befugnis.
- `test-isolated-identity.sh`: acht Linux-Tests auf getrennte
  Mutter-/Tochter-Schlüssel und Dateibesitz, verwehrte Fremdschlüssel-
  lesung, verwehrte Schreibzugriffe auf Policy/Recovery/Mutterzustand
  und unveränderte schutzwürdige Testbytes. Keine Schlüssel im Repo,
  keine Live-Authentifizierung und kein externer Reproduktionsvorgang.
- GitHub-Workflow `test-0927-membrane.yml` startet weiterhin ALLE
  bestehenden Kommunikations-, Signatur-, SQLite- und Grenztests
  zusätzlich zu diesen Reproduktionsprüfungen.

## Bewusste Grenze des jetzigen Manifests

Das Artefakt ist ein *reproduzierbarer Blueprint*, kein vollständiges
und selbständig ausführbares Tochter-Paket. Es referenziert exakt das
verifizierte ursprüngliche 0.9.26-R3-Release; der aktuelle DEV-Membrancode
ist **nicht** dadurch Bestandteil eines geborenen KiCom. Ein reproduktives
Release erfordert später separat geprüfte Paketierung, gebundene
Membran-Version, neuen Instanzzustand, unabhängigen Schlüsselbesitz,
Kommunikationsparität und sichere Rücknahme. Es darf keine veraltete
g25r2-Angabe aus dem noch nicht revidierten kanonischen PROJECT_STATE
als aktuelle Produktiv-Identität übernehmen.

Ein extern bewilligtes Hosting bleibt notwendig. Die Mutter darf
Tochter-Baupläne entwickeln, besitzt dadurch aber keine eigenen
Befugnisse zur externen Server-/Account-Anlage. Eine abgeschaltete
oder nicht freigegebene Tochter darf nicht stillschweigend aktiviert werden.

## Nächster sicherer technischer Schritt

Ein **isoliertes, unveränderliches Geburtskandidaten-Paket** bauen:
verifizierten Softwarestand und separaten Membran-Bauplan vollständig
hashbinden, das Kind mit neuen unabhängigen Schlüsseln und leerem
eigenen SQLite-/Memory-/Recovery-Bereich in einem ausschließlich lokalen
Staging-Verzeichnis INITIALISIEREN und gegen die unveränderte Mutter
prüfen. Danach unabhängige Host-/Egress-Grenze, vollständige
bidirektionale Kommunikation und mehrere Generationen tatsächlich
testen. Kein produktiver Host, Slack-/Mail-Zugang, Nutzerzustand oder
bestehendes 0.9.26-Release darf dafür verändert werden.
