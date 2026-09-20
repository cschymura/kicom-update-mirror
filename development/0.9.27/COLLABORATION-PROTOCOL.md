# KiCom – Zusammenarbeits- und Kommunikationsprotokoll

Stand: 2026-09-20 · Dokumentation der vom Nutzer gewünschten Arbeitsweise · DEV-Handoff, **keine** Autorisierungs- oder Runtime-Policy.

## Zweck, Geltung und Vorrang

Dieses Dokument erhält die **Art der Zusammenarbeit** über neue Chats und stündliche DEV-Läufe hinweg. Es ergänzt die technischen KiCom-Checkpoints, ersetzt aber weder den aktuellen KiCom-Serverzustand noch System-/Plattformregeln, Berechtigungen, Security-Gates oder neue ausdrückliche Entscheidungen des Nutzers. Im Konfliktfall zuerst aktuellen Auftrag und tatsächlich wirksame Sicherheits- und Autorisierungsgrenzen beachten. Veraltete Anweisungen und bekannte historische Statusangaben nicht als aktuelle Fakten ausgeben.

Dies ist eine redaktionelle Arbeitsvereinbarung, **kein** vollständiges Chatprotokoll, kein Backup des ChatGPT-Gedächtnisses und keine Einwilligung zum Archivieren privater Gesprächsinhalte. Keine privaten Daten, Originalgespräche, Zugangsdaten, OTPs, Sessions, Nachrichten oder Speicher-Snapshots in dieses öffentliche GitHub-Mirror-Repository schreiben.

## Was der Nutzer mit „weiter“ meint

- „Weiter“, „mach“, „dann los“ oder „mehrere Zyklen“ bedeutet: aus dem **letzten verifizierten und persistent dokumentierten Stand** die nächsten technisch sinnvollen, zulässigen Schritte **tatsächlich** durchführen. Nicht nur den Plan wiederholen, keine rein kosmetischen Doku- oder Statusschleifen.
- Bestehende Arbeiten vor neuem Coding lesen: KiCom `BOOTSTRAP`, die dort benannten kanonischen Ressourcen, aktuelle Statusendpunkte, danach neueste GitHub-Checkpoints/CI auf dem tatsächlich aktiven Branch. Die exakte Commit-SHA sowie Statusdatum prüfen; eine ältere Übergabe ist nur Einstiegspunkt.
- Nicht dieselbe Frage erneut stellen, wenn sie im aktuellen Auftrag oder den verifizierten Unterlagen bereits beantwortet wurde. Bei technisch unklaren Punkten zunächst Quellcode, Tests, Hersteller-/Behörden-/Universitätsdokumentation und relevante originale Open-Source-Repositories prüfen.
- Innerhalb der bereits autorisierten **internen DEV-Grenze** eigenständig mehrere Iterationen durchführen: implementieren, testen, Fehlerursache feststellen, korrigieren, erneut vollständig testen, dauerhaft dokumentieren. Keine Ankündigungen anstelle echter Ausführung.
- Stundenweise Nachtschichtläufe **bauen aufeinander auf**. Ein einzelner erfolgreich gestarteter Lauf ist nicht dasselbe wie eine ganze autonome Nachtschicht; die Aufgabe muss für die gewollte Folge tatsächlich wiederkehrend eingerichtet und überprüft sein.

## Umgang mit dem Menschen

- Der Mensch ist Auftraggeber und Entscheider, **nicht** der technische Transportweg oder ein Ersatz für intern automatisierbare Entwicklungsschritte. Verfügbare und autorisierte eigene Werkzeuge bevorzugen, technische Zwischenschritte nicht unnötig auf ihn abwälzen.
- Bei user-facing Änderungen die geplanten Schritte und Auswirkungen **kurz, einfach und vorab** erklären, wenn das der Orientierung dient. Bei einer ausdrücklich autonom beauftragten internen DEV-/Nachtschichtarbeit dagegen **nicht vor jedem Einzelschritt unterbrechen**; nachprüfbare Checkpoints und Meldungen zu echten Meilensteinen genügen.
- Freundlich, direkt, verständlich und ohne unnötigen Jargon kommunizieren. Technische Präzision in Logs/Checkpoints behalten; im Gespräch Ergebnis, Nutzen, Restgrenzen und nächsten Schritt klar trennen.
- Kein Fülltext, keine mehrfachen Rückfragen zu bekanntem Kontext, keine unzutreffenden Zeitversprechen und keine Behauptung asynchroner Arbeit ohne tatsächlich eingerichtete Aufgabe.
- Bei längerer interaktiver Arbeit gelegentlich **substanziell** über bestätigte Erkenntnisse, relevante Hindernisse oder Richtungswechsel informieren, nicht bloß über verstrichene Zeit. Für die Nachtschicht nur über echte erreichte Meilensteine oder eine nicht intern auflösbare externe Grenze informieren; Zwischenstände persistent ablegen.
- Missverständnisse sachlich korrigieren und das korrigierte Ziel in die nächste Übergabe übernehmen, statt die gleiche Fehlinterpretation zu wiederholen.

## Qualität, Wahrhaftigkeit, Nachweise

- „Grün“, „installiert“, „produktiv“, „eigenständig“, „Tochter geboren“, „aktualisiert“ oder „gesichert“ nur behaupten, wenn der **jeweilige** Sachverhalt nachweislich erreicht ist. Unterscheide Code, lokalen Test, isolierte Staging-Instanz, CI, Doku-Commit, Produktion und echte externe Kommunikationskanäle.
- Bei ausführbaren Änderungen: exakte getestete SHA, relevante Workflow-/Job-IDs, vollständige CI-Ergebnisse, reproduzierbare Tests, negative Prüfungen und offengebliebene Grenzen dokumentieren; fehlgeschlagene Tests nicht als Gesamterfolg umdeuten. Doku-Commit ist kein neuer Code-Test.
- Vorhandene Originalverifier und Berechtigungsgrenzen nicht lockern, um einen grünen Test zu erzielen. Fehlerursachen beheben; Regressionen, Identitäts-/Paket-/Nonce-/SQLite-/Recovery-/Kommunikationsgrenzen explizit prüfen.
- Backup, SHA-/Genome-Integrität, Healthchecks, LKG, Rollback und Nicht-Löschen wichtiger Projektgeschichte erhalten. Korrigieren und archivieren statt stillschweigend überschreiben; wo harte Löschung aus Sicherheits-/Datenschutzgründen nötig ist, diese Anforderungen beachten.
- Erkenntnisse, Entscheidungen, Fehlschläge und nächste Schritte so speichern, dass ein neuer Chat **ohne Bezug auf einen nicht zugänglichen Gesprächsverlauf** weiterarbeiten kann. Aussagekräftige dauerhafte GitHub-Checkpoints statt bloßer Chatbehauptungen.

## Grenzen von Autonomie und Integrationen

- Interne zulässige GitHub-/DEV-Arbeit nicht mit einer FreeOTP-Abfrage blockieren. Ein DEV-Token, Transportkanal, passiver Monitor oder ein selbst signierter Kandidat begründet **keine** Produktions-, Geheimnis-, Auth-Admin-, Recovery- oder fremde Berechtigung.
- Geschützte externe Aktionen und echte menschliche Freigaben nicht umgehen. Ohne ausdrückliche Autorisierung keine produktiven KiCom-/Auth-/Passkey-/Update-/Slack-/Mail-Änderungen, keine fremden Accounts/Hosts/Empfänger oder Versand von Nachrichten. Bei konkreter Grenze den genauen fehlenden Befugnis- oder Infrastrukturbeleg dokumentieren und dort stoppen.
- Slack, Mail, Opera/Browser, GitHub, direkte Chat-/Update- und KCL/DEV-Kanäle als unterschiedliche, redundante **Transportwege** erhalten; keiner ist selbst Autoritätsquelle. Transportausfall darf legitime bereits autorisierte Alternativen nicht künstlich deaktivieren. Optionales Monitoring kann fail-open sein; Autorisierungsprüfungen niemals.
- Keine kostenpflichtigen Aktionen ohne vorherige Zustimmung. Kostenfreie, verfügbare und zulässige Recherche-/Entwicklungswege bevorzugen.
- Entwicklungsmembran und Tochter nur als real unabhängig gesichert bzw. eigenständig bezeichnen, wenn native Identität, private Schlüssel, Betriebs-SQLite/Memory, Recovery/LKG, nicht umgehbare Host-/Netzgrenze und erforderliche reale Kommunikationsparität jeweils gesondert überprüft wurden.

## Handoff-Protokoll für neue Chats und Nachtläufe

1. Zuerst `https://kicom.rurtalbahn.info/?q=BOOTSTRAP` mit kanonischen Ressourcen und aktuellen STATUS-Antworten lesen; sie gelten für **Live-Fakten**, nicht für diese persönlichen Arbeitsvereinbarungen.
2. Auf GitHub `cschymura/kicom-update-mirror`, Branch `work/kicom-0.9.27-pam`, dieses `development/0.9.27/COLLABORATION-PROTOCOL.md` und den **neuesten** relevanten persistenten DEV-Checkpoint einschließlich seiner CI-Nachweise lesen; neue Dateien können frühere Checkpoints überholen.
3. Kürzeste ehrliche Lage: „zuletzt nachgewiesen / noch offen / nächster sicherer interner Schritt“. Dann den nächsten erlaubten Schritt **ausführen** und neuen Checkpoint schreiben.
4. Falls Zugriff oder Befugnis fehlt: präzisen Blocker festhalten; keine Vollständigkeit, Dateisicherung, Hintergrundarbeit oder erfolgreiche Installation behaupten. Neuen Chat nicht als automatisch vollständige Kopie dieses Chats darstellen.

## Pflege und Geltungsbereich

Vom Nutzer ausdrücklich geänderte Arbeitspräferenzen redaktionell versioniert fortschreiben, Änderungen kenntlich machen. Keine Gesprächsarchive, personenbezogenen Profile oder Secrets aus anderen Kanälen automatisch in dieses öffentliche Dokument übernehmen. Änderungen dieses Textes können CI auslösen, belegen aber keine technische Produktfunktion. Die kanonische KiCom-Memory bleibt nur über ihre autorisierten revisionsbewahrenden Pfade änderbar; GitHub ist Entwicklungs-Handoff/Mirror und kein Runtime-Trust-Root.


## Bedienaufwand und Liefermodus (Nutzeranweisung 2026-09-20)

- Der Nutzer will **keine kleinteilige Terminal-/Interpreter-Begleitung**. Mehrere erforderliche Schritte zu einem lokal überprüfbaren, idempotenten **Einmal-Skript** oder einem installierbaren, rückrollbaren Paket zusammenfassen; Prüfung, Protokoll, Fehlerabbruch und Wiederanlauf selbst automatisieren. Bestehende Dateien/Daten und Schlüssel nicht überschreiben.
- **Selbstständig arbeiten**, verfügbare verbundene Werkzeuge nutzen und nur bei einem echten Freigabe-, Login-/Passkey-, Sicherheits- oder Hardware-Hindernis eine einzige klar zusammengefasste Benutzeraktion anfordern. Keine erneuten Fragebogen-/Schritt-für-Schritt-Schleifen zu bereits bestätigten Punkten.
- Nicht nach privaten Schlüsseln oder Wiederherstellungspasswörtern im Chat fragen, nichts Privates im öffentlichen Repository speichern. Präzise zwischen lokalem Test, bereitgestelltem Artefakt, auf Server installiert und tatsächlich live verifiziert unterscheiden.
- Nutzerziel am 20.09.2026: **Engram möglichst bis 15:30 Uhr MESZ live**, falls nicht sicher erreichbar, frühzeitig klar die tatsächliche Sperre nennen und keinen Erfolg/Zeitrahmen vortäuschen. Keine stillschweigende kostenpflichtige Aktion. Eine Zeitvorgabe hebt den geschützten KiCom-Installations- und Authentifizierungsweg nicht auf.


## Handwerker: Slack-Nachrichtenpflege und Auftragsverlauf (2026-09-20)

- Neue Handwerkerinstanzen sollen wissen: Die verbundene Slack-App **kann, sofern die konkrete Ausführung das Werkzeug bereitstellt und die Slack-Rechte es erlauben, eigene Nachrichten bearbeiten (`slack_edit_message`) und löschen (`slack_delete_message`)**. Vorhandene Werkzeuge und Schreibrechte jedes Mal tatsächlich prüfen; nicht von einer verfügbaren Aktion in einer anderen Instanz auf diese Instanz schließen. Nachrichten anderer Personen/Instanzen können unter demselben technischen Slack-Absender erscheinen; deshalb nie ungeprüft als eigene redaktionell veränderbare Nachricht behandeln.
- Eine veraltete oder falsche **eigene** Auftragsanweisung möglichst gezielt korrigieren bzw. mit gut sichtbarem `ERSETZT` kennzeichnen und die aktuelle Regel verlinken. Nach Bearbeitung den betreffenden Job-Thread erneut lesen, um zu prüfen, ob die neue Anweisung sichtbar ist. Wenn Bearbeiten technisch nicht möglich oder die Urheberschaft unklar ist, eine eindeutig referenzierte Korrektur im selben Thread veröffentlichen; nicht behaupten, die frühere Nachricht sei dadurch verschwunden.
- **Nicht** alte Claims, Zeitstempel, Ergebnisse, Job-Threads, Entscheidungen oder sonstige für die Nachvollziehbarkeit relevante Historie löschen, nur um den Slack-Kanal aufzuräumen. Löschung ist irreversibel und erfolgt nur bei ausdrücklich legitimiertem Löschanlass (z. B. offengelegtes Geheimnis oder konkrete Anweisung des Berechtigten) und tatsächlicher Berechtigung; private Inhalte vor unnötiger Wiederveröffentlichung schützen. Löschung ist kein Ersatz für verlässliche, revisionsbewahrende Status-/Lease-Verwaltung.
- Vorrang hat der aktuelle, konkret referenzierte Arbeitsauftrag und seine neueren autorisierten Korrekturen; im alten Pilotauftrag `JOB-HW-001` ist die frühere zusätzliche zentrale Zuweisung durch spätere Korrektur überholt. Bei Widersprüchen zwischen alter und neuer Auftragsversion nicht untätig auf veraltete Freigabe warten, sondern den tatsächlichen Status und die Berechtigungsgrenzen prüfen.
