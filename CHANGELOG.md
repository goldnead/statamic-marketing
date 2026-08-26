# Changelog

## 2.18.0 — 2026-08-26

### Added — Versandfenster in der Zeit der Empfängerin

Ein Newsletter, der um 03:40 ankommt, liest sich wie eine Maschine. Einer, der um 03:40 **Ortszeit**
ankommt, liest sich wie eine Maschine, die nicht weiß, wo man ist — und „um neun" von einem
deutschen Server ist neun Uhr morgens für die meisten und mitten in der Nacht für die eine in
Vancouver.

`sending.window.from` / `.to` als ganze Stunden, gelesen in der Zeitzone der Empfängerin:
`marketing_subscriptions.timezone`, dann die der Konfiguration, dann die der Anwendung. **Nie
geraten** — eine falsche Zeitzone scheitert nicht, sie stellt zur falschen Stunde zu, und danach
sagt nichts mehr, welche der drei Antworten benutzt wurde.

**Ab Werk aus.** Beide Werte leer heißt: jede Stunde ist erlaubt, also genau das, was jede
Installation heute tut. Ein Fenster, das niemand eingestellt hat, darf keine Post zurückhalten.

Ein Fenster über Mitternacht (22 bis 6) wird verstanden. Die naive Prüfung `>= von && < bis` liest
das als „nie" — eine stille Art, allen Versand anzuhalten.

Zurückgestellt wird auf **den Moment, an dem das Fenster öffnet**, nicht stündlich erneut versucht:
ein Wiederholungslauf würde das Aufschub-Budget des Frequenzdeckels aufbrauchen und die Nachricht
verwerfen, bevor der Morgen der Empfängerin je da war. Und er zahlt nicht auf den Deckel ein — „zu
viel Post diese Woche" und „die falsche Stunde" sind verschiedene Fragen.

**Auf `sync` wird das Fenster übergangen.** Dort gibt es keinen Arbeiter, der später wiederkommt.
Der erste Bau hatte den Zweig an der falschen Stelle: die Nachricht wurde weder zurückgestellt noch
gesendet, sie verschwand einfach — auf genau der Installation, auf der es niemandem auffällt.
Gefunden vom Test, der für den umgekehrten Fall geschrieben war.

## 2.17.0 — 2026-08-26

### Fixed

- **One recipient could receive the same campaign mail twice.** `SendMessageJob` opened with
  `if ($message->status !== 'pending') return;` — a read, then a send, then a write. Two workers
  read before either wrote, both passed, both sent. Reproduced before it was fixed: one message
  row, two mails at the same address, and the row afterwards reads `sent` exactly once, so nothing
  in the data says it happened.

  Two ordinary situations produced it. `marketing:send-scheduled` runs every minute and had no
  overlap protection, so a run lasting longer than a minute stood beside its successor; and any
  worker killed between the send and the status write left the row `pending` for the retry to send
  again. The second needs no concurrency at all — one worker and bad timing.

  The guard is now a claim: `pending → sending` as a single conditional update, and only the winner
  sends.

### Added

- **`sending`**, the state a claimed message holds until it is resolved. It counts as outstanding
  in `scopePending()`, which is load-bearing rather than cosmetic: `maybeFinalize()` marks a
  campaign sent once nothing is pending, and a message in flight that stopped counting would let a
  campaign report itself complete while somebody was still waiting for it. It also has its own
  figure in the report, its own filter and its own label — a state the interface cannot show is a
  state nobody can find.

- **`marketing:release-stale-sends`**, scheduled every five minutes. A claim that cannot be given
  back trades a duplicate mail for a missing one, which is the worse half of the bug: a duplicate
  gets complained about, a missing newsletter does not. Anything held past
  `marketing.sending.claim_lease_minutes` (15) comes back.

  It distinguishes two ways a worker dies, because they need opposite answers. `sent_at` is stamped
  immediately *before* the handover to the transport. A stuck row without it never reached the mail
  server and is delivered again; a stuck row with it did, and is closed without a second copy —
  loudly, because whether it arrived cannot be known from here. A second copy is certain harm, a
  missed mail is possible harm and can be looked into.

- `SendMessageJob::failed()` hands the claim back. Between the claim and the first status write sit
  four lookups that can throw outside any try; a throw there used to strand the row where neither
  the retry nor a fresh campaign run could reach it.

### Changed

- `withoutOverlapping(5)` on the minute schedule and `withoutOverlapping(10)` on the sweeper, with
  the expiry spelled out. Laravel's default is a full day and the lock is released on SIGTERM and
  SIGINT but not on SIGKILL, an OOM kill or a hard reboot — a `schedule:run` killed that way would
  otherwise silence both commands for twenty-four hours without printing a line, the sweeper
  included.

## 2.16.0 — 2026-08-25

### Fixed

- **The Control Panel printed a translation key at a reader.** `ContactSubscriptionsPanel` built its
  status label as `__('marketing::leadhub.status_'.$status)`, and `__()` hands back the key it cannot
  find. A subscription row carrying a status this addon does not know — from an older schema, an
  import, another system — showed the literal string `marketing::leadhub.status_confirmed` in the
  panel. It now falls back to the raw value as a headline: not pretty, but a word rather than a bug
  report addressed to the user.

- **The fallback layout said "Unsubscribe" in English on every site.** The word was hard-coded in
  `EmailTemplate::fallback()`, and in this addon every campaign without its own template renders
  through exactly that layout — so on a German installation it was every campaign. It uses
  `marketing::mail.footer_unsubscribe` now, which was already translated.

## 2.15.0 — 2026-08-24

### Added — die Kampagnen-Vorschau ändert sich beim Tippen

Bisher zeigte sie die **gespeicherte** Fassung, und die Oberfläche sagte es auch
dazu: „Save your changes first." Damit war sie drei Schritte vom Bearbeiteten
entfernt — schreiben, speichern, ansehen.

Jetzt dasselbe Muster wie bei den Vorlagen: ein POST auf
`marketing.campaigns.live-preview` rendert, was gerade im Formular steht.
Gerendert wird durch **denselben `CampaignRenderer`**, den der echte Versand
nimmt; ein zweiter Renderer wäre ein zweites Ding, das man in Gleichschritt
halten muss, und die erste Abweichung fände jemand in seinem Posteingang.

**Nichts wird dabei gespeichert.** Die Kampagne wird aus den geschickten Werten
gebaut und nach dem Rendern weggeworfen — ein Test hält das fest.

Der Rahmen behält `sandbox=""` und wechselt nur von `src` auf `srcdoc`: ein
srcdoc-Rahmen ohne Tokens ist derselbe undurchsichtige Ursprung ohne Skripte.
Der Link „Open in new tab" zeigt weiterhin auf die gespeicherte Fassung.


## 2.14.0 — 2026-08-24

### Fixed — der Testversand behauptete vier Tore und hatte eines

`CampaignSender::sendTest()` prüfte die Sperrliste und sonst nichts, während
sein Docblock „gated like the real thing" sagte. Das ist die gefährlichere
Hälfte: wer den Satz liest, hört auf zu prüfen.

Neu geprüft wird **`do_not_contact` am Kontakt** — die zweite „nie"-Fahne. Die
Sperrliste hält, was ein Anbieter gemeldet hat; diese Fahne hält, was ein
Mensch entschieden hat (Abmeldung mit globalem Opt-out, oder eine Hand im CRM).
Keins folgt aus dem anderen. Der Fall, der bisher durchging, ist der
naheliegende: „nur mal kurz zum Ansehen" an eine Kundin, die sich abgemeldet
hat.

**Bewusst nicht geprüft:** Abo-Status, Einwilligung und Frequenz-Deckel. Ein
Testversand geht an eine Adresse, die der Absender eintippt — meist die eigene,
die per Definition nicht auf der Liste steht. Ein Abo zu verlangen würde den
Knopf für genau das kaputt machen, wofür er da ist. Der Docblock sagt das jetzt
im Wortlaut, statt das Gegenteil zu behaupten.


## 2.13.0 — 2026-08-24

### Changed — die Anbieterkennzeichnung wird aufgeloest, nicht gelesen

2.12.0 las `marketing.footer.postal_line` direkt. Das ist fuer eine Marke
richtig und fuer sechs in einem Prozess falsch: alle sechs bekaemen dieselbe
Anschrift — genau die Fehlerklasse, die die Absenderidentitaet schon hatte.

Neu: `Contracts\PostalLineResolver`. Die mitgelieferte Fassung
(`Support\ConfiguredPostalLine`) liest weiterhin die Config, ein
Mehrmarken-Host bindet seine eigene und liefert die Zeile der aktuellen Marke.
**Fuer Ein-Marken-Installationen aendert sich nichts.**

## 2.12.0 — 2026-08-24

### Fixed — Werbepost konnte still ohne Anbieterkennzeichnung rausgehen

`ensureSelfServiceFooter()` haengt seinen Fuss nur an, wenn die Vorlage **gar
keinen** Selbstbedienungs-Weg enthaelt. Das mitgelieferte Ersatzlayout hat
einen Abmeldelink — und **keine Anschrift**. Genau diese Kombination rutschte
durch: Ausweg ja, Pflichtangabe nein.

Es braucht dafuer nicht einmal eine Loeschung. Ein `et_templates`-Eintrag mit
demselben Slug gewinnt gegen die Marketing-Vorlage und tauscht das Layout
lautlos aus.

Zwei Aenderungen:

1. **Ein zweites Netz.** Ist `marketing.footer.postal_line` gesetzt und die
   gerenderte Mail enthaelt sie nicht, haengt der Renderer sie an. Getrennt von
   der Abmelde-Pruefung, weil eine Vorlage den Link haben kann und die
   Anschrift nicht.
2. **Der Rueckfall sagt es.** Loest ein Vorlagen-Handle nicht auf, steht das
   jetzt im Log. Vorher war „umbenannt" von „geloescht" nicht zu unterscheiden
   — und beides von „alles in Ordnung" auch nicht.

**Leer ausgeliefert.** Ein Addon kann die Anschrift seines Betreibers nicht
erfinden, und eine erfundene waere schlimmer als keine. Auf einem Host mit
mehreren Marken gehoert der Wert je Marke gesetzt.

Die `text/plain`-Fassung war nie betroffen — dort kommt die Zeile aus der
Mailable. Betroffen war die Darstellung, die fast jeder sieht.


## 2.11.0 — 2026-08-22

### Fixed — keine Werbemail mehr ohne sichtbaren Ausweg

`{{ unsubscribe_url }}` steht jeder Vorlage zur Verfügung, aber eine Vorlage
kann es vergessen. Genau das ist passiert: die fünfteilige Willkommensstrecke
von adriangoldner.com ging monatelang ohne sichtbaren Abmelde-Link raus. Der
`List-Unsubscribe`-Kopfeintrag war da, doch den zeigt nicht jedes
Mailprogramm — und wer ihn nicht sieht, hat keinen Weg hinaus.

Der Renderer hängt jetzt einen Fuß an, wenn die fertig gerenderte Mail auf
keinen Selbstbedienungs-Weg zeigt. Eine Vorlage, die den Link selbst setzt,
bleibt unangetastet.

**Warum das so lange niemandem auffiel:** die mitgelieferte Ersatzvorlage trägt
selbst einen Abmelde-Link. Die Lücke entstand nur dort, wo ein Host seinen
eigenen Rahmen mitbringt — und dort schaut niemand mehr in die
Addon-Vorlage. Deshalb liegt die Zusicherung jetzt im Renderer statt in einer
Vorlage: sie gilt für jeden eigenen Rahmen, auch für künftige.

### Added — Ausstieg aus einer einzelnen Serie

`sequence_unsubscribe_url` steht bereit, wenn die Mail aus einer Automation
kommt. Den Weg dorthin kennt `goldnead/statamic-automations`, nicht dieses
Addon; fehlt das Paket, bleibt der Wert leer und der Fuß trägt nur die
vollständige Abmeldung.

**Im Fuß steht der Serien-Ausstieg vor der vollständigen Abmeldung.** Das ist
keine Kosmetik: wer eine Willkommensstrecke loswerden will, will selten den
Newsletter los. Stünde die vollständige Abmeldung vorn, klickt sie jemand, weil
sie die erste ist — und ist dann ganz weg.

### Changed

`SingleSend::send()` und `sendTemplate()` nehmen ein optionales
`$sequenceUuid`. Es steht am Ende der Signatur, damit bestehende Aufrufe
unverändert bleiben.

## 2.10.0 — 2026-08-15

### Added — drei Diagramme, jedes für eine Frage

**Wann wird gelesen?** Der Kampagnen-Bericht bekommt auf der Übersicht eine
Kurve der Öffnungen und Klicks über die Zeit seit dem Versand, stündlich
gerastert und ab etwa drei Tagen Lesedauer täglich.

**Wird es besser oder schlechter?** Das Marketing-Dashboard zeigt Öffnungs- und
Klickrate der letzten zwölf versendeten Kampagnen in Versandreihenfolge. Die
Raten kommen unverändert aus `CampaignStats` — dieselbe Zahl, die auch auf der
Kampagnenseite steht.

**Wächst die Liste?** Anmeldungen gegen Abmeldungen je Woche über zwölf Wochen.
Eine stille Woche ist eine Null im Verlauf, keine fehlende Woche.

Alles von Hand gezeichnet, keine neue Abhängigkeit; der CSP im Control Panel
lässt ohnehin kein Skript von einem CDN zu. Jedes Diagramm trägt eine
textliche Entsprechung für alle, die es nicht sehen können.

### Added — das Dashboard fragt nicht mehr je Kampagne einzeln

`CampaignStats::forCampaigns()` liefert die Zahlen für beliebig viele
Kampagnen in **zwei** Abfragen. Vorher lief eine Schleife mit rund sechs
Abfragen je Kampagne: für die fünf Zeilen der Übersicht bereits 55, für die
zwölf des neuen Verlaufs wären es 132 gewesen. Gemessen und festgenagelt: die
Dashboard-Seite bleibt bei derselben Abfragenzahl, ob sie zwei, zwölf oder
fünfzig Kampagnen zeigt.

Beide Wege bauen ihr Ergebnis über dieselbe Methode, damit „Öffnungsrate" im
Addon genau einmal definiert ist. Ein Test hält beide gegeneinander.

### Fixed — was die Kurve zuerst behauptet hat

Alles aus der Kritiker-Runde, und alles derselbe Fehler in verschiedenen
Kleidern: das Diagramm sagte etwas anderes, als es meinte.

- **Der Vorlade-Block erschlug den Maßstab.** Apples Proxy holt das Zählpixel
  für rund die Hälfte aller zugestellten Nachrichten in **einer** Stunde,
  während sich das Lesen über zehn und mehr verteilt. Auf einer geteilten
  Achse landete die höchste menschliche Stunde bei einem Zehntel, typische bei
  zwei bis drei Prozent — drei bis fünf Pixel, und der Unterschied zwischen
  Stunde 4 und Stunde 7 war einer. Die Achse misst jetzt am menschlichen
  Maximum, der Vorlade-Balken schlägt an der Decke an, und **genau das ist die
  Aussage**; sein wahrer Wert steht daneben. Ehrlich war die alte Darstellung
  auch, nur unlesbar, und die Lesbarkeit war die Frage.
- **Zwei Bedeutungen von „Öffnung" auf einer Tafel.** Die Kachel zählt
  Nachrichten mit mindestens einer Öffnung, die Balken zählen die Vorgänge.
  Wer eine Mail fünfmal öffnet, stand als 1 und als 5 da, hundert Pixel
  auseinander, unkommentiert. Die Kacheln bleiben, wie sie sind; das Diagramm
  sagt jetzt, was es zählt.
- **Altkampagnen wurden als reine Menschen gezeichnet.** Die Spalte `machine`
  hat Default `false`, also ist jede vor ihrer Einführung aufgezeichnete
  Öffnung „ein Mensch" — die Zustellwand jeder archivierten Kampagne stand grün
  im Diagramm, dessen ganzer Zweck die Trennung ist. Ein Satz sagt das jetzt,
  aber nur, wenn für diese Kampagne tatsächlich nie eine Vorladung verzeichnet
  wurde: eine kurz vor der Migration versendete Kampagne sammelt danach
  weiter Öffnungen ein, und die tragen die Kennzeichnung. Eine Warnung über
  einem orangen Balken wäre schlechter als keine.
- **Ein einzelner Nachzügler kippte die Kurve dauerhaft aufs Tagesraster.** Die
  Einheit kam aus dem letzten Ereignis überhaupt; ein Klick ein halbes Jahr
  später machte aus drei Tagen Lesen drei von 90 Balken. Erneutes Öffnen nach
  Wochen ist Alltag, `n = 1` nahm der Kurve also ihre Auflösung. Jetzt
  entscheidet die Masse der Ereignisse, nicht der Ausreißer.
- Ein Balken unter etwa 0,7 Prozent der Achse rendert subpixel und war damit
  unsichtbar, während die Zusage lautete, ein nicht-leerer Zeitraum sei als
  solcher erkennbar. Nicht-Null hat jetzt eine Mindesthöhe.
- Der jüngste Balken des Engagement-Verlaufs ist systematisch zu niedrig, weil
  eine gestern versendete Kampagne ihre Öffnungen noch nicht eingesammelt hat.
  Ein Satz sagt das, wenn die jüngste keine 48 Stunden alt ist.
- Beide Zähldiagramme nennen jetzt die Höhe ihrer Achse in Worten. Ohne das
  macht ein einziger Import von fünfhundert Adressen ein Jahr organisches
  Wachstum zu Härchen, ohne dass man es merkt.

### Fixed — Wächter, die nicht bewachten

- Der Übersetzungs-Wächter sah nur Schlüssel, bei denen direkt nach `__(` ein
  Anführungszeichen steht. Vier Schlüssel kamen aus einem Ternär und wurden nie
  geprüft — ausgerechnet die Achsenbeschriftungen. Er liest jetzt den ganzen
  Aufruf.
- Ein Test versprach „identische Arrays" und verglich mit `==`. Jetzt `toBe`.
- Ein Test hieß „die ganze Seite in einem festen Budget", maß aber mit einer
  einzigen Liste, während das Dashboard weiterhin je Liste fragt. Er heißt
  jetzt, was er tut.

### Docs — Sommerzeit ist hier kein Problem, und das steht jetzt fest

Im Herbst fallen beide realen Zwei-Uhr-Stunden in denselben Balken und werden
einmal ausgegeben; nichts geht verloren, nichts zählt doppelt. Ein Test auf
`Europe/Berlin` hält das fest, damit es harmlos bleibt.

## 2.9.0 — 2026-08-15

### Added — der Kampagnen-Bericht

Die Kampagnenseite zeigte eine Handvoll Kacheln und eine flache Empfängerliste.
Was mit einer Kampagne passiert ist und **bei wem**, stand nirgends: die einzige
Leseabfrage auf `marketing_message_events` im ganzen Addon war die
Abmelde-Zählung.

Jetzt fünf Reiter, jeder mit den Personen dahinter:

- **Übersicht** — Kennzahlen, A/B-Varianten und eine Zeitleiste von geplant bis
  zur letzten Aktivität. Eine Station, die nicht stattgefunden hat, fehlt, statt
  leer dazustehen.
- **Zustellbarkeit** — filterbar nach Status. Bei einem Fehlversand steht jetzt
  der Grund dabei; die Spalte lag seit je in der Datenbank und wurde nie
  gezeigt.
- **Öffnungen** — wer, wann zuerst, wie oft, und wie viel davon Maschine war.
- **Klicks** — wer, wann, welcher Link, dazu die Aufschlüsselung nach Link.
- **Abmeldungen** — wer und wann.

Jede Zeile verlinkt auf den LeadHub-Kontakt, wenn es einen gibt, und legt
niemals einen an. Je Reiter ein CSV-Export derselben Auswahl, gestreamt und an
`manage marketing campaigns` gebunden.

**Ein Klick zählt als Mensch**, und das musste er: unter Apples Mail Privacy
Protection holt der Proxy das Zählpixel für jede zugestellte Nachricht, die
einzige verzeichnete Öffnung ist also die der Maschine. Nur Öffnungen zu zählen
meldete „von niemandem gelesen" für eine Kampagne, durch die jemand geklickt hat
— während der Hinweis direkt darunter sagt, dass genau der Klick einen Menschen
beweist. Aus demselben Grund listet der Öffnungen-Reiter jetzt nach
`first_opened_at` statt nach dem Zähler: wer Bilder blockiert und klickt, hat
`opens = 0` und fehlte in einem Reiter, dessen erste Spalte dieser Zeitstempel
ist.

Die alten Zahlen aus `CampaignStats` bleiben unverändert, damit jeder Vergleich
mit einer früheren Kampagne gültig bleibt. Die neuen stehen daneben, mit eigenem
Namen.

### Fixed — die Kampagnenseite antwortete auf jeder Standardinstallation 500

`marketing.archive.show` wird nur registriert, wenn das Archiv angeschaltet ist,
und ausgeliefert ist es **aus**. Die Seite baute den Link trotzdem unbedingt:
`RouteNotFoundException`, also 500 statt fehlendem Link — seit das Archiv
existiert.

Unsichtbar geblieben, weil der Testaufbau das Archiv für die **gesamte** Suite
anschaltet. Kein Test hat je die ausgelieferte Konfiguration betreten. Dafür
gibt es jetzt eine eigene Suite `ShippedDefaults`; ohne den Fix fallen dort
sechs von sieben Tests um.

### Fixed — Kleinigkeiten, die einen Leser in die Irre führen

- Die Zeitleiste konnte sich selbst widersprechen. „Versand gestartet" ist die
  einzige abgeleitete Station (nichts zeichnet den Sendebeginn auf), und bei
  nachträglich geschriebenen Nachrichten stand „Versendet 12. August, Versand
  gestartet 15. August". Die Station entfällt jetzt, statt eine unmögliche
  Reihenfolge zu drucken.
- Der CSV-Export neutralisiert eine führende Formel. Die Namensfelder kommen aus
  dem öffentlichen Anmeldeformular, ein Fremder wählt ihren Inhalt also selbst,
  und Excel führt eine Zelle mit `=` beim Öffnen aus — bei der Person, die das
  Recht auf den Export hat.
- Der Hinweis zu Maschinen-Öffnungen steht auf jedem Reiter, der eine
  Öffnungszahl zeigt, und auf keinem leeren.
- Leerer Reiter: eine Meldung statt zwei. Und eine Kampagne ohne Datum trägt
  keinen einsamen Trennpunkt hinter dem Betreff mehr.

## 2.8.0 — 2026-08-15

### Added — jede Mail steht jetzt am Kontakt

Die Kontaktseite beantwortet „wer ist das und was läuft mit dieser Person".
Was diese Person von uns bekommen hat, stand bisher nicht darin: die Fakten
lagen vollständig in `marketing_messages`, nach Nachricht sortiert, also genau
dort, wo niemand nachsieht, der einen Menschen ansieht.

Versendet, geöffnet, geklickt, Bounce, Beschwerde: jedes davon schreibt jetzt
einen Eintrag auf die LeadHub-Zeitleiste des Empfängers, mit Betreff, Kampagne
und Liste als lesbare Zeilen statt als Payload-Dump.

Zwei Eigenschaften tragen das Ganze, und beide betreffen nicht den guten Fall:

- **Ein Tracking-Pixel darf keinen CRM-Datensatz anlegen.** Zu einer Adresse
  ohne Kontakt wird nichts geschrieben, auch nicht angelegt.
- **Nichts auf diesem Weg darf aus einer zugestellten Mail einen Fehler
  machen.** Der Weg hängt am Sendepfad und an zwei öffentlichen
  Tracking-Endpunkten; er fängt alles und protokolliert gedrosselt.

Abschaltbar über `marketing.timeline.enabled`, einschränkbar auf einzelne Arten
über `marketing.timeline.types` — fünfzigtausend Empfänger und pro Öffnung eine
Zeile am Kontakt ist ein legitimer Wunsch, das nicht zu wollen.

### Added — Öffnungen, die eine Maschine gemacht hat, heißen jetzt so

Apple Mail lädt das Zählpixel für jede zugestellte Nachricht vor, ob gelesen
oder nicht; Sicherheits-Gateways wie Mimecast oder Proofpoint ebenso. Als
Lektüre verbucht sagt das aus, jemand habe eine Mail angesehen, die niemand
geöffnet hat.

Exakt unterscheiden lässt sich das nicht — Apples Mail Privacy Protection ist
gebaut, um ununterscheidbar zu sein. `Support\MachineOpen` ist deshalb eine
Heuristik, und die Richtung ihres Zweifels ist die Entscheidung: ein
unbekannter Client zählt als Mensch. Einen echten Leser als Maschine zu führen
ist der Fehler, der das Ganze schlechter macht als gar nichts.

Bewusst nicht als Maschine gezählt: Gmails Bildproxy. Der lädt beim
tatsächlichen Öffnen, seine Anfrage *ist* eine Lektüre.

- **Die Zähler bleiben, wie sie waren.** `opens` zählt weiter alles. Der
  Bericht vergleicht diese Kampagne mit jeder früheren; „Öffnungen" hier still
  neu zu definieren sähe aus wie ein Einbruch, den es nie gab.
- **Gespeichert wird die Antwort, nicht das Material.** Kein User-Agent, keine
  IP — die Spalte `machine` auf `marketing_message_events` und sonst nichts.
- Neues Ereignis `MessageOpenedByHuman`: hinter einem Scanner-Postfach ist die
  erste Öffnung praktisch immer die der Maschine, `MessageOpened` hat dann
  gefeuert und feuert nicht wieder. Ohne das zweite Ereignis stünde am Kontakt
  für immer „vorgeladen" und nie einmal, dass die Person gelesen hat.

### Fixed

- Die Vorschau im Vorlagen-Editor benutzt jetzt ein benanntes Token statt
  `bg-white`, damit der Rahmen im dunklen CP nicht weiß aufblitzt.

## 2.7.2 — 2026-08-14

### Docs — `| default:` rettet eine Anrede ohne Vornamen nicht

2.7.1 behauptete im Kommentar an der Willkommensserie, ein `default:` mit
Anführungszeichen fange einen fehlenden Vornamen ab. Gemessen am laufenden
Renderer: `{{ leer | default:'du' }}` ergibt leer, mit und ohne
Anführungszeichen, während `{{ vorhanden | default:'x' }}` den Wert liefert —
der Modifier läuft also, er behandelt einen leeren String nur nicht als
fehlend.

Was hält: `{{ first_name or "du" }}` oder ein `{{ if first_name }}`-Block. Der
Hinweis steht jetzt auch im README, weil er jede Kampagne betrifft und nicht nur
die Vorlage: auf dem Sendeweg ist `first_name` leer, und das neutrale Wort aus
`archive.neutral_name` gilt ausschließlich für die Archivseite.

## 2.7.1 — 2026-08-14

### Fixed — die mitgelieferte Willkommensserie baute den Defekt, vor dem dieses Addon warnt

Die Katalogvorlage `marketing_welcome_series` hängte zwei Werbemails an den
domänenneutralen `send_email` aus `statamic-automations`: keine Einwilligung,
keine Sperrliste, kein Opt-out, kein Frequenz-Deckel, kein Abmeldelink, keine
Anbieterkennzeichnung. Zwei Verzeichnisse weiter, in `docs/sequences.md`, steht
seit 1.12.0, dass genau das nicht geht.

Eine Vorlage ist der Weg, den jemand als Erstes geht. Wer sie nahm, baute den
Fehler mit gutem Gewissen, weil er die Vorlage des Herstellers benutzt hat —
zweimal ist er so entstanden.

Jetzt `marketing.send_email` im **Kampagnenmodus**, Kampagne leer: der Katalog
kann keine Kampagne benennen, die es im Zielsystem noch nicht gibt, also wählt
sie die Site. Die Automation kommt ohnehin ausgeschaltet an, und ein Knoten ohne
Kampagne sagt es beim Drücken von „Test", statt still ungeprüft zu senden.
Vorlagenmodus wäre der kürzere Weg und ist gemessen der schlechtere: dort
bekommt der Renderer eine Kampagne mit leerem `content`, und der `text/plain`-
Teil entsteht aus genau diesem Feld.

Dazu Wiedereintritt `ignore` (es gibt nur ein Willkommen) und Beschriftungen an
den beiden Mail-Knoten. Die englischen Platzhaltertexte sind mit den Knoten weg;
eine Kampagne bringt ihren eigenen Text mit.

Der Abmelde-Alarm und die „Kampagne verschickt"-Nachricht bleiben auf dem
neutralen Knoten und an einer Admin-Adresse. Das ist, wofür er da ist — und seit
`statamic-automations` 2.4.0 verweigert er den anderen Fall von sich aus.

## 2.7.0 — 2026-08-14

Aus Adrians Frage, warum es zweierlei „Templates" gibt und was die
FamilyStack-Mails im Template-Feld einer Kampagne zu suchen haben.

### Fixed — eine Kampagne konnte ihren eigenen Text stillschweigend wegwerfen

Das Feld hieß „Template" und die Liste darunter enthielt zweierlei: die
**Umschläge**, in denen eine Kampagne verschickt wird, und die **fertigen
Mails** mit eigenem Betreff und Text aus dem email-templates-Addon.

Wer das Zweite wählte, machte es zum *Layout* der Kampagne. Eine fertige Mail
hat aber kein `{{ content }}`-Loch — also wurde der Kampagnentext nicht
eingesetzt, sondern fiel weg. Geschrieben, versendet, und in keinem Postfach
angekommen. Kein Fehler, keine Meldung.

Das sind zwei Fragen, also sind es jetzt zwei Bedienelemente: **„Was diese
Kampagne verschickt"** (eigener Text / eine fertige Mail) und darunter je nach
Antwort ein **Layout** oder eine **Email-Vorlage**. Gespeichert wird weiterhin
dasselbe Feld — der Versandweg, die API und jede bestehende Kampagne sind
unberührt.

Dazu sagt der Editor jetzt, **bevor** etwas rausgeht, wenn das gewählte Layout
den Text nirgends ausgibt.

### Changed — „Templates" heißt im Marketing jetzt „Layouts"

Ein Wort für zwei Dinge war die Hälfte der Verwirrung. Was unter Marketing liegt,
ist der Umschlag: Kopf, Fuß, Farben, das Loch für den Text. Die fertige Mail mit
eigenem Betreff heißt weiterhin Email-Vorlage und hat ihren eigenen Menüpunkt.

### Added — der Kampagnentext wird geschrieben, nicht getippt

Der Inhalt lag in einer Textarea voller `<p>`-Tags. Das ist kein Schreiben, und
es ist nicht das, was dieses Control Panel sonst verlangt: eine Email-Vorlage
wird in Bard bearbeitet, und eine Kampagne ist dieselbe Art Text von derselben
Person. Jetzt derselbe Editor, mit denselben Knöpfen.

**Die Spalte ändert sich nicht.** `save_html` ist an, das Feld gibt einen
HTML-String heraus und nimmt einen entgegen; `campaigns.content` enthält
weiterhin genau das, was es vorher enthielt. Eine Kampagne aus der API, aus
einem Import oder aus der Zeit vor diesem Release öffnet sich im Editor und
speichert wieder heraus — ohne Migration und ohne einen selbstgebauten
Konverter. Ein Test hält den Rundlauf fest, samt der Antlers-Platzhalter: eine
kaputte Anrede geht an den ganzen Verteiler.


## 2.6.1 — 2026-08-14

### Fixed — die Vorschau kannte eine andere Platzhalter-Liste als der Versand

2.6.0 lieferte der Vorschau eine von Hand geschriebene Liste von Variablen. Sie
war am Tag ihrer Entstehung in beide Richtungen falsch: sie bot `list_name` an,
das kein Versand je geliefert hat, und ihr fehlten `preheader`, `campaign.*` und
`list.*`, die jeder Versand liefert.

Aufgefallen an Adrians FamilyStack-Layout: dessen versteckte Vorschauzeile
benutzt `{{ preheader }}` und blieb in der Vorschau leer, obwohl sie in
Produktion gefüllt ist. Die andere Richtung ist die teurere — ein Platzhalter,
der in der Vorschau gut aussieht und im Postfach als Lücke ankommt.

Die Vorschau fragt jetzt `CampaignRenderer::archiveVariables()`, also die
entpersonalisierte Variablenliste des Renderers selbst. Damit können die beiden
nicht mehr auseinanderlaufen, und ein Test vergleicht sie Schlüssel für
Schlüssel.

### Added — die Liste der Platzhalter steht im Editor, und Tippfehler fallen auf

Unter dem Code steht aufklappbar, welche Platzhalter es gibt. Dazu ein dritter
Befund: **ein Platzhalter, den niemand füllt**, wird als Warnung genannt.
Antlers löst eine unbekannte Variable zur leeren Zeichenkette auf — `{{ list_name }}`
bleibt still leer, in der Vorschau wie im Postfach, und das einzige Symptom ist
eine Lücke, wo ein Wort stehen sollte.

Bewusst zurückhaltend: alles, was kein schlichtes `{{ name }}` oder
`{{ name.unter }}` ist, bleibt unangetastet. Antlers-Bedingungen, Tags und
`noparse` wohnen in denselben Klammern, und eine Warnung, die auf korrektem
Markup feuert, ist der Weg, auf dem Warnungen aufhören, gelesen zu werden.

## 2.6.0 — 2026-08-14

Beides aus Adrians Fragen beim Durchgang durch den Hub.

### Added — die Verteiler einer Person stehen auf ihrer LeadHub-Kontaktseite

Die beiden Addons waren unter der Oberfläche längst verheiratet: eine Anmeldung
löst auf einen LeadHub-Kontakt auf, das Publikum einer Kampagne kommt aus
LeadHub-Segmenten, und LeadHubs `do_not_contact` ist das, was eine Abmeldung
setzt. Zu sehen war davon nichts. Die Kontaktseite zeigte Tags, Aufgaben und
eine Chronik und sagte kein Wort über den Newsletter, den die Person seit einem
Jahr bekommt.

Beigesteuert von dieser Seite über LeadHubs neue Panel-Registry (leadhub 2.2.0),
nicht von dort gelesen: marketing hängt von leadhub ab, leadhub von niemandem,
und das für ein Panel umzudrehen hätte ein optionales Geschwister zur harten
Abhängigkeit des CRM gemacht. Auf einem älteren leadhub fehlt das Panel und
sonst nichts — geprüft wird mit `method_exists`, nicht mit einer
Versionsangabe.

Gesucht wird über die normalisierte Adresse und nicht über `contact_uuid`: die
UUID steht erst da, wenn eine Anmeldung bestätigt und synchronisiert ist, und
eine unbestätigte Anmeldung ist genau das, wofür man diese Seite aufmacht.

### Added — der Vorlagen-Editor zeigt, was er baut

Eine Vorlage ist der Umschlag, nicht der Brief: Kopf, Fuß, Farben und das Loch,
in das der Inhalt kommt. Bearbeitet wurde sie als HTML-Wand in einem Textfeld,
und der einzige Weg, das Ergebnis zu sehen, war speichern, eine Kampagne
schreiben und sich selbst einen Test schicken — drei Schritte entfernt von dem,
was man gerade ändert.

Jetzt: Code links mit Syntaxhervorhebung (`CodeEditor`), gerenderte Vorschau
rechts, umschaltbar zwischen Desktop- und Handybreite. Gerendert wird durch
denselben Antlers-Parser wie der echte Versand (`Services\TemplatePreview`) —
eine Vorschau durch eine zweite Engine wäre eine zweite Implementierung, die man
im Gleichschritt halten muss, und die erste Abweichung stünde in irgendjemandes
Posteingang.

Dazu zwei Befunde, während getippt wird:

- **Diese Vorlage gibt `{{ content }}` nirgends aus.** Fehler. Eine Kampagne
  damit kommt leer an: geschrieben, versendet, und jeder Empfänger bekommt den
  Rahmen um nichts. Das ist nichts, was man aus der Antwort einer Abonnentin
  erfahren sollte.
- **Diese Vorlage hat keinen Abmeldelink.** Warnung, kein Fehler: dieselbe
  Vorlage ist für transaktionale Mail legitim, wo es nichts abzumelden gibt.

Erkannt wird `{{ name }}` mit beliebigem Abstand, nicht das bloße Wort — sonst
bekäme jemand für eine korrekte Vorlage gesagt, sie sei kaputt, und das ist der
Weg, auf dem eine Warnung aufhört, gelesen zu werden.

Die Vorschau liegt in einem `<iframe sandbox="">`, das nichts erlaubt. Eine
Vorlage ist beliebiges HTML, das jemand eingefügt hat; ein `<script>` darin tut
in einem Mailprogramm nichts und liefe hier im Control Panel mit der Sitzung der
bearbeitenden Person. Dieselbe Regel wie bei der Kampagnen-Vorschau, und
`tests/js/preview-sandbox.test.js` hält jetzt beide daran fest.


## 2.5.1 — 2026-08-14
### Security

- **2.5.0 closed the membership oracle for repeated attempts and left it open for a single
  one.** The already-subscribed path charged the recipient throttle and stopped there, so it
  never reached the suppression gate, the brand's sender identity or the transport. The moment
  the installation could not send, an ordinary address answered `unavailable` while a
  subscribed one still answered `sent` — one request, deterministic, no timing needed, and in
  exactly the failure state `unavailable` exists for. Found by a critic run against the built
  code a few hours after 2.5.0, measured rather than argued.

  `coverForSilentPath()` now reaches the same verdict the real send would reach, by every route
  that does not require putting a message on the wire: it charges the throttle, asks the
  suppression gate (discarding the answer, keeping only whether the gate replied at all), and
  resolves the brand's sender identity. `ConfirmationThrottleTest` runs the comparison with each
  of those broken, with the subscribed address probed FIRST — the order that helps an attacker
  most — instead of only in the good case.

- **New `Sending\TransportHealth`.** Whether a relay will take a message cannot be asked
  without giving it one, and the silent path has none, so a failed send is remembered for sixty
  seconds and the silent path reads it. It changes only what the cover path answers, never what
  a real sign-up is told, and it expires on its own so that a relay refusing one recipient does
  not declare the installation broken for long.

### Known limits

Both of these close with the same change — queueing the confirmation send, so that no response
knows a delivery outcome at all — and both are stated rather than papered over.

- **Timing still distinguishes a real send from a withheld one.** The mailable is built and
  handed to SMTP inline, so a request that sent one pays a round trip and a request that did
  not does not.

- **A dead relay is mirrored with a one-minute memory, not perfectly.** Once the installation
  has hit the failure once, both paths answer alike. The first request after a quiet window
  still slips: probe a subscribed address before any real send has failed, then a control
  address, and the pair separates them. The other three reasons for `unavailable` — no usable
  counter, an unreachable suppression gate, a half-declared sender identity — are mirrored
  unconditionally, because all three can be asked without sending anything.


## 2.5.0 — 2026-08-14
### Changed — BREAKING for anything that read the sign-up response

- **A sign-up is told whether a confirmation mail is actually coming.** 2.4.0 made a withheld
  mail byte-identical to a sent one and called that a feature, because a difference could be
  used to ask whether an address is on a list. The reasoning was right about the danger and
  wrong about the price: on 13.08.2026 a real sign-up on gldnr.studio got "check your inbox"
  while the recipient throttle held the mail back. The person is still `pending`, never got
  anything, and the only trace was one line in a log nobody watches. The likeliest next move
  for somebody in that position is to sign up again — which trips the same throttle. The state
  is self-reinforcing and invisible from the outside.

  `SubscriptionService::subscribe()` now hands back a `Sending\ConfirmationResult` on the
  returned model (`$subscription->confirmation`, a declared property, never an attribute and
  never persisted), and both the public form endpoint and any host API report it:

  | value | meaning |
  | --- | --- |
  | `sent` | a confirmation is on its way — and the cover for the two silent cases below |
  | `throttled` | none right now, this MAILBOX was asked recently; `retry_after_minutes` says how long the window is |
  | `unavailable` | none right now, this INSTALLATION could not send one |

  The line between them is one question: would knowing it tell a stranger something about the
  PERSON? "Already subscribed" and "suppressed" would, so both keep answering `sent`.
  "Somebody asked for this mailbox in the last hour" would not — it is a statement about a
  moment in time, and the person who most often triggers it is the visitor themselves.

- **The recipient budget is charged before the suppression gate, and on the already-subscribed
  path too.** Both of those paths send nothing and say nothing, and until now both cost
  nothing either — so two submissions separated them from an ordinary address, which answers
  `sent` and then `throttled`. Making the cover story cost the same is what keeps it a cover
  story. It also closes a smaller hole on its own: a suppressed address could be pushed
  through the endpoint without limit.


- **`status` is gone from the sign-up response**, in the JSON envelope (`data.status`) and in
  the session flash. It was `subscribed` against `pending` — the membership question, answered
  for free at an endpoint anybody may post to, in a field nobody had asked for. The envelope
  now carries `data.confirmation` and optionally `data.retry_after_minutes`. A host that
  rendered `session('marketing.subscribed')` still gets a string; it now reads `sent`,
  `throttled` or `unavailable`.

### Known limit

- **Timing still distinguishes a real send from a withheld one.** The mailable is built and
  handed to SMTP inline, so a request that sent one pays a round trip and a request that did
  not does not. That channel predates this release and is unchanged by it; closing it means
  queueing the confirmation, which changes how every install delivers this mail. Stated here
  rather than papered over.


## 2.4.1 — 2026-08-13
### Fixed

- **A relay that refuses the recipient answered the sign-up form with a 500.** `BrandMailer` reports
  a broken brand identity by returning `false`, and `sendConfirmationMail()` was written around that
  — but the transport does not return, it THROWS. An address that passes `email:rfc,filter` and
  every check this addon makes can still be rejected at RCPT time (`501 5.1.3 … not a valid
  RFC-5322 address`), and Symfony's SMTP client raised that straight out through the public
  endpoint, after the pending row had already been written.

  Found by pointing the live endpoint at `x@example.invalid`, not in review. The send is wrapped
  and reported now, which is what the comment above it already promised: somebody who typed their
  address into a form gets an answer, and the delivery problem goes to whoever reads the logs.


## 2.4.0 — 2026-08-13
### Security

- **The sign-up endpoint could be used to send mail at somebody else.** Nothing in the stack
  limited confirmation mail per RECIPIENT. A host throttles per client IP and an API throttles per
  brand or per token; none of them can see that every one of those requests names the same victim.
  With a public form in front and a verified sending domain behind, the effective ceiling on one
  mailbox was however fast the endpoint would answer — from an address whose reputation belongs to
  the sender, not the attacker.

  `SubscriptionService::sendConfirmationMail()` now charges every confirmation against the
  recipient's own budget: one per list per hour, five per mailbox per day, both configurable under
  `subscriptions.confirmation_throttle`. It is the only limit here keyed on the recipient, and it
  sits at the send rather than at an endpoint, so every route into a confirmation mail passes it.

  The identity it counts by is new (`Support\DeliveryIdentity`) and deliberately more aggressive
  than `EmailNormalizer`: it folds case, subaddress tags (`opfer+1@`), dots on Gmail, the
  `googlemail.com` alias and NFKC compatibility forms, because all of those reach one inbox and a
  limit keyed on the address as typed is bypassed by a two-character edit. The consent identity is
  unchanged — merging addresses there would merge people's decisions.

  A withheld mail is silent: same status, same body, same redirect as a sent one, so the limit
  cannot be turned into a way of asking whether an address is on a list. The pending subscription
  is kept, so a real subscriber is delayed rather than lost. If the cache cannot count, nothing is
  sent — a rate limiter on a store that persists nothing counts to zero forever and permits
  everything.

- **The double-opt-in token came back to life after an unsubscribe, and never rotated.** One
  `token` column served confirm, unsubscribe and the preference centre alike. It was written once
  and never changed, and `confirmByToken()` refused a row that was already subscribed while saying
  nothing about one that had unsubscribed. A confirmation link from any time in the past — still in
  a mailbox, a backup, or a link scanner's history — therefore put a person who had left back onto
  the list, without any act of theirs and with no new consent record to point at. The same value is
  printed in the footer of every campaign, so it is also the most widely copied string the system
  owns.

  The confirmation link now has a column of its own (`confirmation_token`, NOT NULL and unique),
  rotated with every confirmation mail and spent on first use through a conditional UPDATE, so two
  simultaneous clicks confirm once. It expires after `subscriptions.confirmation_ttl_hours` (7 days
  by default, 0 to disable), and it is refused for any row that is not pending. `token` keeps its
  old meaning and its old value, so unsubscribe and preference links in campaigns already sent are
  untouched.

- **Opening the confirmation link is no longer the same as agreeing to it.** Mail gateways, virus
  scanners and messenger previews fetch every URL in an incoming message, and each of those fetches
  used to grant the consent — producing subscriptions nobody had agreed to, stamped with a time and
  an address that look exactly like a real confirmation. `GET /confirm/{token}` now renders a page
  with a button and changes nothing; `POST` performs it. Set
  `subscriptions.confirm_requires_post=false` to restore the one-click flow, and the problem with
  it.

### Also fixed, found reviewing the above

- **The limit refuses to run on a cache store that cannot count.** A rate limiter is only a limit
  where `increment()` is one atomic step. The file driver — Laravel's own default — reads, adds and
  writes back in three, so parallel sign-ups all read the same number and all pass, and it reports
  success whether or not the write landed. `subscriptions.confirmation_throttle.store` names the
  store; anything outside the known-atomic set withholds every confirmation mail and says so in the
  log, rather than being trusted on the assumption that production is on Redis.

- **A cache that is unreachable throws rather than answering zero**, which made an outage a 500 on
  the public endpoint. Caught, reported, mail withheld.

- **Re-arming a dead link through the sign-up form.** `confirmByToken()` refuses a link whose row is
  not pending — and `status` is writable by anyone who can type an address into a public form.
  Posting the address of a row that had unsubscribed flipped it back to `pending` while the
  withheld mail returned before the token would have been rotated, so the old link was live again.
  Reviving a row that had ended now clears `confirmation_sent_at` and `confirmation_used_at` in the
  same write. A row that was already pending keeps its link untouched, or submitting a stranger's
  address would be a way to break their sign-up.

- **`"opfer"@example.com` was a second bucket for the same inbox.** A quoted local part passes
  validation, is unquoted by the receiving server, and hashed differently — a free doubling of both
  tiers for two characters. Unquoted and unescaped in `DeliveryIdentity` now.

- **Two simultaneous sign-ups for one address 500'd.** `subscribe()` selects and then inserts, and a
  double-click lands in the gap: the loser hit the consent unique and got an unhandled exception on
  an anonymous endpoint. The violation is caught and the winner's row returned.

- **An address with an RFC comment 500'd at send.** `jemand(kommentar)@example.com` passes the
  default `email` rule and makes `Symfony\Component\Mime\Address` throw as the message reaches the
  transport, well past anything that could catch it. The public endpoint validates
  `email:rfc,filter`.

### Known and deliberate

The withheld mail is indistinguishable in status and body, but not in timing: the confirmation is
built and sent inline, so a request that sent one is measurably slower than one that did not.
Closing that means queueing the send, which changes how every install delivers this mail, so it is
named rather than quietly half-fixed.

`per_mailbox` bounds one mailbox across all lists, which means an attacker can also spend somebody
else's daily budget and delay their genuine sign-up. That is inherent to any per-recipient limit;
the alternative is no bound at all.

### Upgrading

One migration adds three columns to `marketing_subscriptions` and backfills them. Pending and
subscribed rows keep a working link — a confirmation mail sent minutes before the upgrade still
resolves. Rows that had unsubscribed, bounced or complained get a value that has never been
anywhere, which is the point: every confirmation link previously issued to an address that has
since left stops working at that line.


## 2.3.1 — 2026-08-13
### Fixed

- **Blade escaped the `text/plain` part.** `{{ }}` runs `e()` whatever the output is for, so a
  campaign whose body says "Musik & Chor" arrived as "Musik &amp; Chor" in the text alternative —
  and `toText()` had decoded those entities on the line before, deliberately. There is no HTML to
  escape into in a text part. `{!! !!}` now, with a test.


## 2.3.0 — 2026-08-13
### Fixed

- **The text part of a campaign carried the wrong unsubscribe link, in the wrong language.**
  `CampaignRenderer::toText()` appended the FOOTER link, which resolves to the preference centre
  wherever that sibling is installed — a page of checkboxes on which one click unsubscribes nobody.
  A reader of the `text/plain` alternative was therefore offered no way out that works by itself,
  while the HTML part next to it had one. And because the line was built at render time, `__()`
  answered in the application locale: a German campaign on a host with `APP_LOCALE=en` said
  "Unsubscribe".

  The line now lives in the mailable's text view (`marketing::mail.text`), which a Mailable renders
  inside the recipient's locale, and it carries `one_click_unsubscribe_url` — the same address the
  RFC 8058 `List-Unsubscribe` header uses. New translation key `marketing::public.unsubscribe_text`.
  `RenderedMail->text` is now the content alone; a host that renders it itself keeps exactly what it
  had, minus a line it did not choose.

- **Click tracking rewrote every `href`, not only links.** The regex matched any `href` in the
  document, so a `<link rel="stylesheet" href="https://fonts.googleapis.com/…">` in the head of a
  real template went through the signed click redirect — measured on 13.08.2026 on a live newsletter
  layout. Every mail client that loaded the web font counted a click on a campaign nobody had
  clicked, so the click rate measured the readers' font settings; and the typography of the mail
  depended on a signed redirect surviving whatever a provider appends to it. Anchors only now, with
  their other attributes preserved.


## 2.2.0 — 2026-08-12
### Fixed

- **A brand that named a mailer or a from-name but no from-address kept sending**, over the brand's
  own transport with the host-wide From. That is exactly the pair a relay verifying sending domains per account
  refuses — or delivers under whichever identity the account does own, which is another brand's.
  2.1.0 chose to warn and send anyway, on the grounds that a loud rejection beats a quiet
  mis-delivery; the three sibling addons chose to refuse, which beats both. **It now refuses**: no
  mail, an error line naming the brand and the setting, and the subscription stays `pending` so a
  second attempt can rescue it once the brand row is fixed. A mailer name that `config/mail.php`
  does not define is refused the same way, at resolution rather than at the send.

- **`BrandMailer` still worked through `Config::set('marketing.from.*')` with a `finally`.** It held
  only as long as every mailable read `marketing.from.*` and nothing else ever put a From on the
  message — a rule enforced by nothing. The identity is now applied as values on the message.
  `ConfirmSubscriptionMail` and `CampaignMail` only fill a From that is not already there.

### Changed

- **A campaign's `from_email` no longer beats the brand's own address.** The two sibling packages
  disagreed about this: the `send_email` node in `statamic-automations` let the brand win, this
  package let the campaign win. One of them had to be wrong, and it was the one that could put a
  brand's transport behind an address it does not own — the address and the transport are one pair,
  and only the brand row knows which addresses the relay account behind that transport owns. A
  per-campaign address can be checked by nobody until the provider sees it, at which point the
  fan-out has already started.

  **Where no brand declares an address — every single-brand install — nothing changes**, and the
  campaign's `from_email` applies exactly as before. Where one does, the dropped value is written to
  the log once per pair per window, and the Control Panel field now says so instead of promising
  "Defaults to the site sender". `reply_to` is untouched and still per campaign, which is the field
  that decides where an answer lands.

- **The five sender-identity classes moved to `goldnead/statamic-brand-context` 1.8.0**, which is
  now required at `^1.8`. They were four byte-identical copies with four namespaces, and they had
  already begun to drift — see the fix above. `Goldnead\Marketing\Contracts\SenderIdentityResolver`
  stays as the per-package extension point (it extends the brand-context contract), as does
  `Sending\BrandMailer`, which keeps `marketing.sending.mailer` as the default transport below the
  brand. `Sending\SenderIdentity` is gone from this namespace; use
  `Goldnead\BrandContext\Sending\SenderIdentity`, and build it with `::of()` rather than `new`.

- `BrandMailer::send()` takes a recipient name (`send($brandId, $to, $toName, $mailable)`) and
  returns whether the mail went out, matching the sibling packages.

## 2.1.0 — 2026-08-12

### Fixed — every brand now sends as itself, not as whoever the config named

Four send paths — the campaign fan-out (`SendMessageJob`), the single send
(`SingleSend`, which is also how the automations node sends), the CP test send
and the double opt-in confirmation — each read `config('marketing.sending.mailer')`
directly. That value is global. In a multi-brand install every brand therefore
sent over the same transport, and with the same `marketing.from.*` sender.

This is not cosmetic. A relay that verifies sending domains per account
(Scaleway TEM, Postmark, SES with a verified identity) refuses a From it does
not own, or replaces it with one it does. Measured on 12.08.2026 in the hub this
addon runs in: the double opt-in confirmation for **chorgesucht's** newsletter
went out through the **FamilyStack** Scaleway project, and arrived under
FamilyStack's sender. A reader who asked one organisation for its newsletter got
a confirmation from another one.

The transport and the From now come from one place, together, per brand:

```php
$brand->update(['settings' => ['mail' => [
    'from_address' => 'noreply@chorgesucht.de',
    'from_name'    => 'chorgesucht.de',        // defaults to the brand name
    'mailer'       => 'scaleway_chorgesucht',  // a mailer from config/mail.php
    'locale'       => 'de',                    // the language its mail is in
]]]);
```

The credentials stay in `config/mail.php` and the environment. Putting SMTP
usernames and passwords into `settings` would carry them into the database,
every backup and every CP export.

**`locale` is part of the identity** because the subject line is. A German
brand on an English installation was sending "Please confirm your subscription
to …", which is the same defect wearing different clothes: the mail did not
sound like the sender it claimed to be. The locale travels on the mailable
(`Mailable::locale()`), so the application's own locale is untouched.

### Added — `SenderIdentityResolver`, the extension point

```php
$this->app->bind(
    \Goldnead\Marketing\Contracts\SenderIdentityResolver::class,
    MyOwnResolver::class,   // resolve(?int $brandId): SenderIdentity
);
```

The bundled `BrandSenderIdentity` reads `brands.settings.mail`. A host that
keeps sender identities elsewhere replaces it without the addon knowing
anything about the host — which is the point: the hub that found this bug has
its own `BrandMail`, and the addon must not depend on it.

### Nothing changes for a single-brand install

A brand with no `settings.mail` — which is every brand until somebody fills one
in — resolves to `marketing.sending.mailer`, `marketing.from.*` and the
application locale. Byte for byte the previous behaviour, including when the
brands table is missing, the brand row is gone, or a queue worker has no brand
in context: all three mean "use the configured identity" rather than "fail".

`BrandMailer` scopes its config overrides and restores them in a `finally`. A
queue worker sends for more than one brand in one process, and a throwing send
must not leave the next brand's mail holding the previous brand's From.

Deliberately **not** scoped: `mail.from.*`. Laravel's `MailManager` reads it the
first time a mailer name is resolved and burns it into the cached instance
(`alwaysFrom`), so an override there outlives the window it was set in — the
first brand to send would leave its address standing for every later message
through that transport that sets no From of its own. Only `marketing.from.*` is
touched, which both mailables read first anyway. A test pins this.

### Two things worth knowing before you configure a brand

**A campaign's own `from_email` still wins** over the brand identity. Explicit
configuration beats a default, and now that the transport is the brand's, a
foreign address fails at the relay instead of being silently replaced — a
visible error rather than a quiet one. Leave the field empty unless you mean it.

**A brand that names a `mailer` but no `from_address`** gets its own transport
with the host-wide From, and a warning in the log (once per brand per process).
That pair is what has to agree; splitting it is the same incident with the
halves swapped. The other direction — an address without a mailer — is the
ordinary case for the brand the global credentials belong to and stays silent.

`settings.locale` on the brand is used when `settings.mail.locale` is absent.

### Upgrading

`SingleSend` and `CampaignSender` each take one new constructor argument
(`BrandMailer`). Both are resolved from the container everywhere in this
package; only a host that builds them with `new` has to add the argument.
`SendMessageJob::handle()` and `SubscriptionService` are unchanged in shape —
they resolve it from the container so that existing callers keep working.

## 2.0.1 — 2026-08-09

### Fixed — the sibling constraint excluded the new majors

`goldnead/statamic-leadhub` was pinned to `^1.4` to the 1.x line. LeadHub 2.0.0 and Marketing 2.0.0 carry no code change over 1.12.2
and 1.13.0 — that major is the licence switch alone. A site running both this package and an
updated sibling could not resolve its dependencies at all. The constraints now accept both
lines.

## 2.0.0 — 2026-08-09

### Changed — the licence is now proprietary

This is a paid Marketplace addon. `composer.json` declares `proprietary` and the
licence file carries the commercial addon licence instead of MIT. Entitlement is
enforced by the Statamic Marketplace, not by code in this package.

Tags up to and including `v1.13.0` remain MIT. The change takes effect with the next
release.

## 1.13.0 — 2026-08-05

### Added — the person is also addressable as `subscriber.*`

Which merge variables a template could use depended on **which node sent it**.
An automation resolves its own node config against the run, where the person
lives under `subscriber.*`. A `marketing.send_email` body is parsed against the
flat array from `CampaignRenderer::variables()`, which had no `subscriber` key.

Antlers resolves an unknown variable to the empty string, so a template written
for one context rendered its greeting empty in the other — with no error, no
log line and no failed send. `Hallo {{ subscriber.first_name }},` became
`Hallo ,` and the mail went out that way. That is what made it expensive to
find on adriangoldner.com, and it would have hit every future template written
for the marketing node.

`variables()` now offers `email`, `first_name`, `last_name`, `name` and
`unsubscribe_url` a second time under `subscriber.*`. `subscriber` is
marketing's own domain word, not an application placeholder.

The two spellings cannot drift: the alias is derived from the flat array rather
than rebuilt beside it, and applied last in both `variables()` and
`archiveVariables()` — the latter overrides the person keys after the fact, so
re-deriving is what keeps a recipient's name off the public archive page.

## 1.12.0 — 2026-08-04

### Added — `marketing.send_email` sends a template, not only a campaign

The node demanded a campaign handle. It was built for sequences in which every
mail is a campaign, and where a site writes its mails that way it was right.

`adriangoldner.com` does not. Its marketing mails are managed email templates
(`et_templates`), and its automations configure them the way the domain-neutral
`send_email` node in `automations` takes them:

```php
'to'       => '{{ subscriber.email }}'
'subject'  => '{{ subscriber.first_name }}, schön, dass du dabei bist'
'template' => 'welcome-sequenz-1-willkommen'
```

Recipient plus template, not campaign. So the site's seven marketing mails could
not use this node at all and went out through the neutral one instead — which
asks nobody whether the recipient wants marketing mail, because it is also how a
site sends a password reset. Seven live marketing mails with no consent check,
no suppression check, no opt-out check and no frequency cap. The node was
correct and unusable, and that is the defect this release fixes.

**Template mode.** `template` + `to` + `subject` + `list`, alongside the
existing `campaign` + optional `list`. Exactly one of `campaign` and `template`:
both is two different answers to "what is this mail", neither is no answer at
all, and both are reported as configuration errors — before the test-mode
branch, so pressing **Test** finds a broken node rather than the first person to
reach that step three days later.

**The gates are unchanged, in both modes.** Consent (list subscription) →
suppression (fail-closed) → LeadHub `do_not_contact` (fail-closed) → frequency
cap. `SingleSend::sendTemplate()` is `send()` with the campaign replaced by the
three things a template send actually has; everything between the gates and the
delivery is shared code, so the two modes cannot drift apart.

**`list` is required in template mode**, where it was optional in campaign mode.
A campaign carries its own list; a template carries none. Without one there is
nothing to prove the recipient ever agreed to be mailed, and the node refuses
rather than sending unchecked — this is the one place the new mode is stricter
than the old one, deliberately.

**A `mail_class` field**, defaulting to `marketing`. Campaign mode still takes
`Campaign::mailClass()` and ignores this field. Template mode has no campaign to
ask, so the node states it, and the cap exceptions (`transactional`, `digest`,
`reminder`) work exactly as they do on the broadcast path. Anything unrecognised
reads as `marketing`: forgetting to classify a mail costs a delay, never an
exemption nobody asked for.

**A template that resolves to nothing fails.** The campaign path falls back to
the built-in layout for an unknown template handle, which is right there — the
content is the mail and the layout is the frame. Here the template *is* the mail,
and the same fallback would deliver an empty one under a subject the reader
recognises. `statamic-email-templates` stays optional: without it, template mode
resolves against marketing's own template repository, and a slug that answers to
neither fails with a message naming the missing package instead of a fatal error
on its facade.

### Changed — a `marketing_messages` row may belong to no campaign

`campaign_handle` becomes nullable and `template_handle` is added beside it.
Exactly one of the two is set on every row.

A template send has no campaign — not a draft one, not a hidden one, not a
synthetic handle that resolves to nothing — and `NULL` says that literally.
It is also the useful encoding: `Message::forCampaign()` and every campaign
report are a `where campaign_handle = ?`, which never matches `NULL` on any
engine, so a template mail stays out of numbers it was never part of without a
single one of those queries being touched. A placeholder handle would have been
counted by all of them as a campaign that does not exist.

`template_handle` keeps the row self-describing. "Which mail was this" is the
first question a bounce, a complaint or a support request asks, and it has to be
answerable from the row alone.

The migration is additive and carries existing rows and indexes through SQLite's
table rebuild; `tests/Migrations/CampaignlessMessagesTest.php` runs a populated
1.6.3 install forward and checks both.

### Note

`campaign` is no longer marked `required` in the node's schema. That is not a
loosening — a form that demands both fields cannot express a node that takes
exactly one of them, so the rule moved into `execute()`, where it can say which
of the two mistakes was made. Existing campaign-mode nodes are unaffected in
every respect: same config, same gates, same classification, same message row.

## 1.11.2 — 2026-08-04

### Fixed — the archive took `/newsletter` from the host application

The newsletter archive shipped **on** by default and registered its routes
unconditionally. Both were wrong, and together they took a public URL from the
site that installed this package.

`adriangoldner.com` has its own `/newsletter` page. Upgrading this addon there
stopped it rendering: two of the site's Inertia smoke tests began reporting
"Not a valid Inertia response" for that exact path. The addon's archive index had
won the route, during a `composer update`, without anyone being asked.

Two changes:

- **`marketing.archive.enabled` now defaults to `false`.** Set
  `MARKETING_ARCHIVE=true` to switch it on. A package may not claim a readable
  public path on installation.
- **The routes are registered only when it is on.** Checking the flag inside the
  controller was not enough — the route still existed and still matched first, so
  a host page on that path was unreachable even with the archive switched off.

Three tests pin it: no `marketing.archive.*` route exists while off, the
configured prefix is left unclaimed, and a host route on `/newsletter` still
answers.

**A correction to the 1.10.0 entry.** It said both features "ship inert" and that
a `composer update` "changes neither what is sent nor what is public". The first
half was true of the frequency cap. The second was not true of the archive, and
this release is what makes the sentence honest.

Nothing else changed. Sites that want the archive set one environment variable.

## 1.11.1 — 2026-08-04

### Fixed — the addon could not be installed on a current Statamic 6 site

`symfony/yaml` was constrained to `^6.0|^7.0`, and so was `goldnead/statamic-leadhub`,
which this package requires. Statamic 6.26 on Laravel 13 ships `symfony/yaml` v8, so
`composer require goldnead/statamic-marketing` on a site created today failed to resolve
with "the package is fixed to v8.1.2 by a partial update".

Widened to `^6.0|^7.0|^8.0`, and the leadhub requirement now resolves to v1.12.1, which
carries the same widening. The addon uses exactly `Yaml::parse`, `Yaml::dump` and one
`DUMP_*` constant, all unchanged across Symfony 6, 7 and 8. The suite runs green against
v8.1.2: 311 passed, 2128 assertions, PHPStan and Pint clean.

**How this was missed.** Installing the package into an empty directory succeeded, because
Composer was free to pick `symfony/yaml` v7 there. A real Statamic site already has v8, and
nothing can move it. An empty-directory install proves a package has *some* resolvable set,
not that it fits the environment it is built for.

## 1.11.0 — 2026-08-04

<!--
    Additive. Nothing existing sends differently: the new node is a node
    somebody has to place, and the one-recipient send path is new code that no
    existing caller reaches.
-->

### Added — `marketing.send_email`, the send node a sequence is built out of

`goldnead/statamic-automations` can already do the timing a sequence needs —
delays, wait-until windows, branches, brands. What it could not do was send a
*marketing* mail, because everything that makes one different from an ordinary
mail is this addon's domain: which list carries the consent, whether the
address is suppressed, whether the person has opted out, and whether they have
already had their three mails this week.

So the node lives here and is contributed to the builder, rather than
`automations` learning what a newsletter is. It sends one campaign to the
contact the run is about, through `Sending\SingleSend`, which asks the four
questions in the order the send path has always asked them:

1. **Consent** — a subscribed subscription on the configured list, or nothing
   is sent. Not even to an address the flow otherwise knows perfectly well.
2. **Suppression** — the hard no, and the only gate that fails *closed*: a
   check that cannot be answered blocks the send.
3. **Opt-out** — LeadHub's `do_not_contact`, which is what the preference
   centre and an editor's manual opt-out both write. Also fail-closed.
4. **Frequency cap** — last, because it is the only one that says "later"
   rather than "no", and there is no point deferring a mail to an address that
   may never receive it at all.

**This is not `ThrottleNode`.** That node throttles one flow. The cap counts a
*person's* marketing mail across every flow, every campaign and every broadcast
in the same brand — two sequences that each throttle themselves correctly still
add up to six mails a week for somebody who is in both. Only a node on the
marketing send path can see that.

**And not `automations`' own `send_email`**, which stays domain-neutral: an
address, a subject, a body, and no opinion about consent, because it is also
how a site sends a password reset.

What a gate answers turns into what the run does:

- *Blocked* ends the run. Not "skip this mail and carry on to the next one":
  every later step of a marketing sequence is more marketing mail.
- *Capped* pauses the run and asks again later — the same deferral budget the
  campaign path spends, so a reader is held back for the same length of time
  whether the mail came from a broadcast or from a sequence.
- *Out of deferrals* sends nothing, lets the flow continue, and writes a
  warning naming the recipient and the campaign, so somebody asking in three
  months why the third mail never arrived can be answered.

The mail is an ordinary campaign — authored in the campaign editor, left in
draft — and the send writes a real `marketing_messages` row, so opens, clicks,
bounces, the unsubscribe link and the ESP feedback loop all work exactly as
they do for a broadcast. The campaign is never marked sent, because it is the
content of a step rather than a broadcast that happened.

### Added — `Sending\SingleSend`

The marketing send path for exactly one recipient: `StartCampaignJob` +
`SendMessageJob` with the fan-out removed and all four gates kept. Usable on
its own, and the reason the node above is thin enough to read.

### Changed

- `Integrations\Automations\AutomationsBridge` registers the node as
  **built-in** and contributes the `marketing.campaigns` / `marketing.lists`
  option sources. Built-in because the node is this addon's own surface in the
  builder; gating it behind the orchestrator's Pro licence would make a
  marketing feature depend on an automations edition.
- `Models\Subscription` carries `@property` annotations for `email`,
  `list_handle` and `contact_uuid`.
- `.phpstan/automations-stubs.php` keeps the new node inside level-5 analysis
  even though the optional sibling is absent, exactly as the webhook-manager
  stubs next door do. The live check that the class still satisfies the real
  interface is `tests/Integration/AutomationsIntegrationTest.php`, run by
  `scripts/test-siblings.sh`.

## 1.10.0 — 2026-08-03

<!--
    Both features are additive and both ship inert: the frequency cap is off,
    and no campaign is in the archive until somebody puts it there. A
    `composer update` therefore changes neither what is sent nor what is public.
-->

### Added — the newsletter web archive

A campaign existed only as an e-mail. Anyone who wanted to read it in a browser,
link it, or find it later could not, and every "can you send me the last issue"
was answered by hand.

Each campaign can now be released to a public web version on a readable,
guessable URL — `/newsletter/{handle}`, a slug and not a token, because the
token link is the personalised one and this is deliberately the other thing.
With it come a chronological index per brand, an RSS feed, and the head tags a
search engine and a share preview need: title, description, canonical, Open
Graph.

**Off by default, per campaign.** Applying the migration publishes nothing, and
the flag is not on the edit form — it is on the report page, because `update()`
refuses a campaign that has been sent and "should this be public" is a question
that gets asked afterwards. A campaign can carry a price, a segment's context or
an individual address; putting a year of that on the open web because a package
moved is not a decision an addon may take.

**Not released answers 404, not 403.** 403 is the accurate status and the wrong
one to send: it confirms that a campaign with this handle exists, which turns a
guessable URL into a way to enumerate unpublished issues by name. The archive
says nothing about what it is not showing — a draft, another brand's campaign
and a handle nobody ever used are the same answer.

**Nothing on the page counts.** The web version goes through the existing
`CampaignRenderer`, not a second implementation, and it goes through it with no
message — which is what removes the open pixel and the click rewriting. That is
a correctness property rather than a preference: an open in the archive is not
an open of the e-mail, and a click counted with no recipient behind it would be
added to the campaign's rate as if somebody who received the mail had clicked.
Both numbers would go up and mean less.

**Personalisation resolves neutrally.** `{{ first_name }}` and `{{ name }}` come
out as a configurable word (`archive.neutral_name`, translated by default)
rather than as raw braces — the embarrassing failure — or as an empty string,
which turns `Hallo {{ first_name }},` into `Hallo ,` on a page search engines
index. `{{ email }}` stays empty: there is no address this copy went to, and a
made-up one in "this mail was sent to …" is worse than a gap.

The page is served with `default-src 'none'` and no script source, so a
`<script>` that reaches a template cannot run against the site's origin. It is
deliberately *not* the CP preview's `sandbox`: an opaque origin is not something
a page meant to be read and shared can be.

### Added — frequency caps, and the classification they rest on

An upper bound on how much marketing mail one contact receives in a rolling
window — three in seven days, by default, once switched on.

**The exceptions are the rule, so they are a contract.**
`Goldnead\Marketing\Contracts\MailClass` names four kinds of outgoing mail —
`marketing`, `transactional`, `digest`, `reminder` — and the cap acts on
`marketing` alone. A community digest is the rhythm somebody subscribed to, not
the extra mail the cap exists to limit, so counting it would mean the digest ate
the budget and silenced everything else. A password reset is somebody waiting on
a screen. An event reminder that arrives late is a missed event, not a quieter
inbox. None of that can be decided by whichever addon happens to be sending, so
the class travels with the mail and any package in the family can name one
through `Goldnead\Marketing\Contracts\FrequencyCap`.

Unknown or absent reads as `marketing`. Forgetting to classify costs a delay;
it never buys an exemption nobody asked for.

**The decision is taken at the send, not at the enqueue.** A campaign snapshots
its audience and hands the queue one job per recipient, and those jobs sit
behind a throttle, a retry or a stopped worker — sometimes for days. Whether
somebody has had their three mails is a fact about the moment the mail leaves.
Both directions are tested: a recipient who was under the limit when the job was
created and over it by the time it ran is held, and one who was over it then and
under it now is sent.

**A capped message is moved, not dropped.** It goes back on the queue and is
tried again; only when its deferral budget runs out is it discarded — with
`status = capped` on the row and a warning in the log naming the campaign, the
recipient and the limit. Silent discarding is the version where somebody asks in
three months why they never got the March issue and nobody can answer. `capped`
is a different word from `skipped` on purpose: skipped means the address may not
be mailed, capped means it may and was not.

While a message is deferred it stays `pending`, which is load-bearing: a
campaign is marked sent once nothing is pending, and a deferred message under
any other status would let it report itself finished while people were still
waiting.

**On the `sync` connection there is no later.** A dispatch runs inline and a
delay is ignored, so pushing a message back would re-enter the same code
immediately and spend a three-day deferral budget inside one request, ending in
a discard that reads as if three attempts had been made. There, the message is
discarded once and the log says exactly why.

Counting is keyed on the normalized address rather than the subscription:
somebody on four lists is one person with one inbox, and per-subscription
counting would have handed them four times the cap while the config still said
three. The window is measured on one clock end to end — `now()` writes the log
row and `now()->subHours()` reads it back — because Laravel's `datetime` cast
serialises a zoned Carbon without converting it, and a window built on a value
that crossed a timezone is wrong at both edges by that offset. There is a test
that runs the whole flow in Europe/Berlin.

The cap falls **open**: a check that cannot be answered lets the mail through
and says so in the log. That is the deliberate opposite of the suppression gate,
which falls closed and aborts the campaign. Suppression is the only thing
between a send and an address that said no; the cap is between a send and
somebody who has been hearing from us a lot, and refusing to send because a
count failed would trade a real delivery failure for a hypothetical annoyance.

**Visible at the contact.** Where a cap is configured, each subscriber row shows
how many marketing mails they have had inside the window against the limit, and
how many campaigns have actually been held back from them. Without it, "capped"
on a campaign report names a message that nobody can trace back to a person.

### Changed

- `SendMessageJob::handle()` takes a fifth argument, the `FrequencyCap`. The
  gate order in the send path is now suppression → what the reader has said they
  want → the cap, which is the order in which "never", "no" and "not yet" have
  to be asked.
- The archive route uses `{marketingCampaign}` rather than `{campaign}`. Route
  parameter names are application-wide, and `campaign` is a word half this
  family could reach for; a sibling binding it would resolve our handle against
  its own repository and 404 every archive page. The name never appears in a
  URL, so the prefix costs nothing.

### Fixed — every tracked link in a campaign sent through Brevo was a 403

Brevo rewrites every `href` in the HTML part of a message onto its own click counter, and when that
counter forwards the reader it appends `_se`, the recipient address in base64, in front of the rest of
the query. Laravel signs the whole query string. One appended parameter is therefore not the URL that
was signed, and `ValidateSignature` answers 403 before `TrackingController` runs.

Measured at the QA hub against a real `marketing_messages` row, not reasoned about: without a signature
403 — with a valid signature no longer 403 — with a valid signature **plus `_se`** a 403 again. The
appended parameter destroys exactly the signature check.

**What that cost.** On a campaign sent through Brevo it hit *every* tracked link, which is every
absolute `http(s)` link in the message. Twice over: the reader never reached the destination, and the
click was never counted either, because the middleware aborts ahead of `recordClick()`. Confirmation
and unsubscribe links came through unharmed — their token is in the path and nothing about them is
signed — which is why the failure looked partial rather than total. Preference-centre links did not:
they were missing from the renderer's exception list, so they were rewritten like any other link and
inherited the same 403, leaving people unable to change what they receive.

**The fix, and the line it does not cross.** `delivery.ignored_query_parameters` names the parameters a
sending platform may append without invalidating the signature — eleven of them, each one a name a real
provider adds, each one commented with which. Everything else is still refused.

The boundary matters more here than in the same fix in `statamic-preference-center`, where a magic
link's payload sits in the path. This route carries its destination **in the query**, as
`?url=https://…`. A `url` on that list would not be a weaker signature, it would be an open redirect on
the sender's own domain with the sender's own reputation behind it. So `Support\TrackingParameters`
refuses to ignore `url`, `expires` or `signature` — in any casing, and through any comma-separated
smuggling — however the config is edited. The tests prove the refusal at the endpoint, with `url` on
the live ignore list: an edited destination is still a 403 and still counts no click.

The list is read per request rather than baked into the route, because this addon merges its config
after Statamic has loaded the route files, and because `route:cache` would otherwise freeze whatever
was on the list the day the cache was built.

### Added — `delivery.mail_headers`

The other half of the answer: the per-message header that asks the provider not to rewrite the links at
all, added verbatim to campaigns and double-opt-in mail. Mailgun, Postmark, Mailjet, SparkPost,
SendGrid, Mandrill and Elastic Email each have one, verified against their own documentation and
tabulated in `config/marketing.php`. Brevo has none, and none is coming — there the ignore list above is
not defence in depth, it is the only thing that works. Empty by default: an addon that guessed your
provider and changed how it behaves would be worse than one that asks.

### Fixed — preference links are no longer rewritten

Unsubscribe and confirm were already out of the click redirect: their token is in the path, and they
are the routes a reader has to be able to reach when everything else has failed. The preference page
belongs in that group and was not in it, so it went through the signed redirect and took the 403 above
with it — the one reader who acted on the footer got an error page instead of their settings.

It is not fixed by adding a path. Since 1.9.0 marketing serves no preference page: it belongs to
`goldnead/statamic-preference-center`, and where its route lives is that addon's business, not
something this renderer may spell out. The renderer asks `Support\PreferenceLink` — the one resolver
that already decides where a subscriber's links point — for this reader's own self-service URLs, and
keeps what comes back out of the redirect. Install the preference centre and its links are exempt;
install nothing and marketing's unsubscribe page is. Neither case needs a path written down twice.

The token is cut off the end of each answer, so the exemption covers the page rather than the one URL:
a footer that appends `?utm_source=` to `{{ unsubscribe_url }}` survives too. Ordinary links are still
tracked with the centre installed, which is its own test — an exemption that widened to the whole host
would stop counting every click in silence.

### Fixed — CI

Nothing in this section reaches an installed site: it touches `.github/workflows/`,
`scripts/test-siblings.sh` and `composer.json`'s repository metadata, and no runtime file.
`resources/views/`, `lang/` and `resources/dist/` are byte-for-byte 1.9.0. (`src/`, `config/` and
`routes/` are not — the click-tracking fix above changes them.)

- **The cross-addon integration job ran no tests at all.** `scripts/test-siblings.sh` staged its
  throwaway copy with `git archive HEAD`, and `git archive` applies `.gitattributes` `export-ignore`
  — which since the packaging sweep earlier today holds `/tests`, `/phpunit.xml` and `/scripts`. The
  staged copy therefore had no test suite and no PHPUnit config, and Pest aborted with `The test
  directory [%s] does not exist.` Staging now goes through `git read-tree` into a scratch index plus
  `git checkout-index`, which is the same HEAD content without the export filter. The
  `export-ignore` list is correct for what a site downloads and is unchanged.
- **A skipped integration run no longer passes for green.** All seven tests in `tests/Integration`
  call `markTestSkipped()` when their sibling class is missing, so a run where the siblings failed to
  install or their bridges failed to boot exited 0 and read as a pass — the state these tests were in
  for their entire existence. The script now asserts against the JUnit report that tests ran and none
  skipped. (`--fail-on-skipped` is not enough: Pest 3 accepts the flag and exits 0 anyway.)
- **The optional siblings come from Packagist.** `goldnead/statamic-automations` and
  `goldnead/statamic-webhook-manager` were published today, so the script requires the newest stable
  release of each instead of writing VCS repository entries and pinning `*@dev` — which tested
  unreleased sibling branches against a released addon. Only their `src/`, `routes/`, `config/` and
  `database/migrations/` are needed, none of which is export-ignored, so a dist install is correct
  and `--prefer-source` is not. Local checkouts via `AUTOMATIONS_PATH`/`WEBHOOK_MANAGER_PATH`/
  `LEADHUB_PATH` still work, and are now resolved to absolute paths before the script changes
  directory — the documented relative form pointed at nothing.
- **`Show what actually resolved` failed on every matrix cell.** `composer show` takes one package,
  and it was handed four: `Too many arguments to "show" command`. It is `composer show --direct` now,
  and the `--prefer-lowest` job prints the same table.
- **`composer validate` is strict.** `--no-check-publish` existed only to hide "this package is not
  publishable" while the siblings were private. They are public, so the flag is gone and `--strict`
  replaces it.

## 1.9.0 — 2026-08-01
### Security — the campaign preview ran in the Control Panel's own origin

`GET marketing/campaigns/{handle}/preview` returns HTML a Control Panel user wrote: the campaign body
and the e-mail template wrapped around it. It was served from a Control Panel route into a
same-origin `<iframe>` with no `sandbox` attribute and no Content-Security-Policy. So a holder of
`manage marketing templates` could put a `<script>` in a template and have it execute with the
session of whichever super user previewed a campaign using it. Not cross-site — the script was
already inside the site, one permission tier below the account it reached.

The barrier is two-sided, because either side alone is a single point of failure on a
privilege-escalation path. The response now carries `Content-Security-Policy: sandbox; default-src
'none'`, which puts the document in a unique opaque origin with scripts off and holds even when the
HTML is opened straight into a tab — which the "open in new tab" link invites. Images and inline
styles are handed back explicitly, because an e-mail preview without them is not a preview; scripts
never are. The iframe carries `sandbox` with neither `allow-scripts` nor `allow-same-origin`, which
holds when the header does not reach the parser. `tests/Feature/CampaignPreviewIsolationTest.php` and
`tests/js/preview-sandbox.test.js`.

### Changed — the preference page belongs to the preference centre now

**Breaking for anyone linking to `/!/marketing/preferences/{token}` by hand.**

`goldnead/statamic-preference-center` serves one page over marketing's lists, notification types and
the suppression state. Marketing shipped its own copy of that page — the same `data-*` contract, the
same error split, the same class names — and kept linking to it, so installing the preference centre
changed nothing a reader could see: the footer link still landed on the single-list page.

- `resources/views/partials/preferences.blade.php`, `resources/views/preferences.blade.php`,
  `PreferencesController` and the `marketing.preferences` routes are removed.
- Marketing keeps exactly one unsubscribe path — the tokenized landing page and the RFC 8058
  one-click endpoint — and it works with no optional package installed. Stopping mail is a legal
  obligation and may not depend on someone having chosen to install something.
- `src/Support/PreferenceLink.php` is the single place that decides where a link goes: the preference
  centre where it is installed, marketing's own unsubscribe otherwise. Detection is `class_exists()`
  plus the route registry — the centre registers its token route conditionally, so class-present and
  route-absent is a real state. Never `method_exists()` on a facade class; that is `__callStatic`
  and it is false for every proxied method, which is how automations 1.0.3 disabled every LeadHub
  action node on real installs.
- The `List-Unsubscribe` header stays on marketing unconditionally. A provider POSTs it expecting an
  unsubscribe, not a form.
- `MarketingSubscribed` / `MarketingUnsubscribed` keep their payload contract: `unsubscribe_url` still
  means marketing's own endpoint. `preferences_url` is added beside it.

`SubscriptionPreferences` is untouched — it is the consent logic, and the preference centre reads it
rather than reimplementing it. Its two test files now drive it at the service layer instead of
through the removed page, with the same assertions.

### Fixed — a German Control Panel showed an English addon

The Vue layer calls `__()` with the English sentence as the key, which resolves through the JSON
loader rather than the `marketing::` namespace the PHP lang files serve. There was no JSON file and
no registered JSON path, so nav and flash messages came out German from the PHP files and the entire
screen behind them stayed English. `resources/lang/de.json` covers all 102 strings; `en.json` is the
identity map so the set is diffable. `tests/Feature/CpTranslationCoverageTest.php` reads the sources
rather than a list, so a new `__('…')` fails on the day it is written.

Also: `CampaignController::preview()`'s 422 message was a hardcoded English string on an error path
while every other message in the file went through `__()`.

### Fixed — CI was checking one of three siblings and one axis of two

- `composer.json` declared `laravel/framework: ^11.0|^12.0|^13.0`. Every 11.x release is withdrawn
  behind security advisories, so that line was not untested but uninstallable. Narrowed to
  `^12.0|^13.0`, with `orchestra/testbench` and `pestphp/pest` widened to match — Laravel 13 does not
  resolve otherwise.
- The test matrix now varies PHP **and** Laravel, adds a `prefer-lowest` run, a MySQL run against the
  `phpunit.mysql.xml` that had been in the repo unused since the InnoDB key-length incident, and a
  run of `scripts/test-siblings.sh`, which had also never run in CI — so the seven cross-addon
  Integration tests had only ever been skipped, which reads as passing in a run summary. Every cell
  was resolved with `composer update --dry-run` first; PHP 8.2 with Laravel 13 is excluded because it
  cannot resolve.
- The workflows checked out one of three required siblings, described it as a path repo (it has been
  a VCS repo for several releases) and gave Composer no credentials of its own — `actions/checkout`
  scopes its token to its own checkout. Replaced with `COMPOSER_AUTH`.
- `scripts/test-siblings.sh` rewrote a path repository that no longer exists, and could abort on the
  `cd` in front of it under `set -e`.

### Changed — Control Panel polish and release hygiene

- The dashboard hand-built two tables and two empty states; both are native components now.
- `text-gray-700`, `bg-white` and `max-w-5xl` do not follow the CP theme or the width toggle.
- `icon="email"` and `icon="template"` are not filenames in the Statamic icon set.
- Primary actions on the dashboard are in the command palette.
- Pint, Larastan (level 5 with a baseline), `.gitattributes`, and `extra.statamic` gaining `slug`,
  `url`, `developer` and `developer-url` — without them the manifest carried four nulls and the CP
  addon card had no developer link.
- The README install command could not work for anyone and omitted two of three hard dependencies.

## 1.8.1 — 2026-07-30

### Fixed — the one page that could hand back what the gate had just taken away

1.8.0 put `goldnead/statamic-suppression` in front of every path that puts a mail on the wire. The
preference page is not one of those paths, which is why it was not in the list, and the reasoning was
sound as far as it went. It is also the only surface in this addon that **writes consent back** — and
it was still asking the older, narrower question: `do_not_contact` on the LeadHub contact, and the
subscription's own `bounced`/`complained` status. Nothing asked the table the gate reads.

**What was possible while that was true.** Take an address that hard-bounces or files a spam complaint
after 1.8.0. The provider event is recorded in `suppressions` and nowhere else: it does not set
`do_not_contact`, and it does not rewrite this addon's subscription row unless the addon's own ESP
ingress happened to see the same event. The unsubscribe token in every mail that address was ever sent
still resolved. Opening it produced a page with every row switchable, ticking them all was accepted,
and the addon wrote fresh consent records — `reason: preference_center`,
`consent_proof: unsubscribe_token`, into the LeadHub timeline — for an address that was blocked at the
time it did so. A subscription that had been ended came back as `subscribed`; a list the person had
never been on could be created outright.

Measured on the QA hub against v1.8.0 before this release was written, not reasoned about: an address
carrying one active `complaint` row in `suppressions` (brand-scoped, `do_not_contact = false`, both
subscription rows still `subscribed`) opened its preference page and got **13 lists, none of them
disabled**. Removing nothing and simply ticking every box returned **13 subscriptions where there had
been 2** — eleven of them to lists the address had never been on — with no refusal and not one error
message on the page.

No mail went out. The gate held at all four send paths, so the block did its job at the door. What was
damaged is the record and what happens after it: a complainant carries a consent trail dated *after*
their complaint, and the day somebody legitimately releases a suppression — a mailbox restored, a
bounce that turned out to be an outage — that address is subscribed to lists it never asked for and
starts receiving them. And the token is not a private thing. It is repeated in every mail this brand
sends and has passed through mail clients, forwarding rules, scanners and link checkers. "Only the
person could have done it" was never the claim.

The rule this violated is the one 1.7.0 wrote down as the difference between a feature and a hole:
**the token may end consent, and may restore consent the person themselves ended, but may never lift a
block.** For one release that sentence was untrue whenever the block lived only in the suppression
table — which, since 1.8.0, is where provider events go.

### Changed — the page asks what the send paths ask, per row and in one brand

`SubscriptionPreferences` now reads **both** signals and treats either one as a block: `do_not_contact`
on the contact, and `Goldnead\Suppression\Contracts\Gate`. Neither replaces the other. An editor's
opt-out in the CRM still lands only on the contact; a provider bounce lands only in the table.

Two details that are not incidental:

**One question per row, not one for the page.** The rows of a preference centre do not all carry the
same mailbox. A person who holds a second list under a second address is found through the same
`contact_uuid`, and that row would be mailed at *its* address — so a single question asked for the
token's address would answer for the wrong mailbox on every other row. It is still one query;
`suppressedAmong()` takes the batch.

**One brand, and it is the page's own.** No brand is passed, so the gate resolves the current one,
which `SetBrandFromRouteValue` derived from this very token — the same brand whose lists are on
screen. That is what makes decision D1 land per row rather than as a blanket: a hard bounce is stored
globally and blocks here, because the mailbox is gone for everyone; a complaint stays in the brand
that received it and must not shut a page belonging to a brand the person never objected to. Asking
"blocked anywhere" would leak one brand's relationship into another; asking only this addon's own
state is what shipped in 1.8.0.

**A gate that cannot answer blocks everything.** "The query failed" and "nobody is suppressed" are not
the same statement. Catching `SuppressionCheckFailed` here is not carrying on — it *is* the closed
answer, drawn as a page whose rows are all un-switchable. It costs an unsubscribe during the outage,
which is the cheap side of the trade: every send path fails closed on the same fault, so there is
nothing going out to unsubscribe from.

The refusal stays in the service and not in the template, for the reason it always was: a disabled
checkbox is a suggestion, and a crafted POST is not. The test that removes `disabled` in the browser
and submits now runs against a block that exists **only** in the suppression table.

### Fixed — a test whose premise leadhub 1.11.0 retired

`PreferenceCenterBrandTest > it shares one contact across the brands` was red on both drivers when
1.8.0 was tagged, and it was not the gate's doing. It asserted that one address in two brands is one
LeadHub contact, so that the identity reached across brands on its own and the brand scope was the
only thing holding it. `statamic-leadhub` v1.11.0 made the flat contact store brand-isolated; it is
now two contacts. The premise is gone, and its going is an improvement.

Rewritten to the case that still proves something. The subscriptions have not changed: both brands
hold a row carrying the same address, and `subscriptionsOfThePerson()` matches on `email_normalized`
as well as on the contact, so `marketing_subscriptions` remains a second route to the person that does
not pass through LeadHub at all. What stops it surfacing is that the person's rows are keyed by list
handle, and **a list handle has exactly one owner across the whole install** — the flat store refuses
a handle another brand holds, and `2026_07_28_000001_restore_global_unique_on_list_handles` enforces
the same thing in the schema. Brand B's membership has no row of brand A's to land on, whatever the
identity match returns. The test now pins that, so the day handles become unique per brand instead, it
fails here and names this page as the consequence to re-check.

### Notes

- Suite: **202 passed, 7 skipped (756 assertions)** on the flat driver, **201 passed, 8 skipped (754)**
  on eloquent, plus **24** Vitest. Green on both drivers, and 1.8.0's known red is gone: +3 new cases
  and +1 that used to fail. The skips are the two sibling-integration files (those addons are not
  installed in this bed) and one flat-only repository case; they are unchanged from 1.8.0.
- Nothing under `resources/views/` changed, so the committed CP bundle is untouched. `npm run build`
  and `npm run build:check` were run anyway and report `FRESH` — the 1.7.0 lesson was that a Blade
  edit is enough to move it, not that a PHP edit is safe to assume about.
- `SubscriptionPreferences::__construct()` takes one more argument. It is container-resolved in every
  code path; only a test building it by hand needs updating.

## 1.8.0 — 2026-07-30

### Added — every send path asks one question, and it is not this addon's question any more

This addon could put a mail on the wire from four places. One of them checked whether the address was
allowed to receive it, in its own way; the other three did not check at all.

- `StartCampaignJob` asked LeadHub's `do_not_contact`, and failed closed when it could not resolve a
  contact — good, and the only one.
- `SendMessageJob` checked `isSubscribed()` and nothing else, so an address blocked *during* a long
  campaign was mailed by a queue that had stopped listening.
- `SubscriptionService::sendConfirmationMail()` sent a double opt-in mail to whatever a public form
  had been given. A blocked address that typed itself back in got mail from us.
- `CampaignSender::sendTest()` sent to any address an editor typed.

All four now go through `goldnead/statamic-suppression`, a new dependency at `^1.0`.

### Changed — the suppression list is a package, not a folder here

**1.7.0's changelog said, in bold, that the preference page reads the state this addon already keeps
"and not a suppression list of its own … there is no second list to drift out of step with the
first."** That sentence was right about the danger and this release does not walk it back — but it is
worth saying plainly what changed, because the shape does now include a list.

What made a second list the wrong answer *inside this addon* is what makes it the right answer
underneath it. A hard bounce is a property of the **mailbox**. It bounces identically whoever sends,
and it damages a sending reputation shared by everything in the application. If the list lived here,
`statamic-notifications` would still write to that mailbox with its immediate mail and its weekly
digest — same application, same reputation, same dead address — and the block would depend on which
addon happened to be sending. That is not one list drifting from another; it is one addon knowing
something and the rest of the install not being told.

So the layer sits underneath, beside `statamic-brand-context` and `statamic-identity-contracts`, and
this addon is a consumer of it rather than its owner. `do_not_contact` stays exactly where it was and
is still read: the gate is an additional signal, not a replacement, and nothing about LeadHub's
opt-out changed.

What did *not* move: the ESP ingress and the Control Panel surface. Those have one feeder and no
second consumer, so relocating them would have bought nothing and cost a dependency.

### Added — fail-closed at the audience, and the reason it is not the segment resolver's rule

`StartCampaignJob` asks once per chunk rather than once per subscriber, and a suppressed address never
becomes a `Message` row at all — a blocked address must not enter a send, not merely fail to leave
one. That also removes the N+1 the old per-subscriber contact lookup carried.

When the gate cannot answer, the campaign is returned to draft and the exception is re-raised. It does
not fall through to "nobody is suppressed".

Twelve lines above it, `resolveSegmentMemberIds()` does the exact opposite: a segment it cannot
resolve is ignored and the campaign goes to the whole list. Both are correct, and the reason is in a
comment beside the catch so the next person to read them does not tidy one into the other. A segment
*narrows* an audience — losing it can only send to more people who already said yes. Suppression is
the only thing standing between the send and an address that said no.

`SendMessageJob` fails a single message rather than the run, because one unreachable read must not
abort a campaign that is already half delivered. `sendTest()` is the one gate that speaks: it throws a
validation error naming the address, because an editor waiting at the screen for a test mail that
silently never arrives learns that the button is broken.

### Added — the backfill, while there is nothing to backfill

`2026_07_31_000001_backfill_suppressions_from_marketing_state` moves what this addon already knew into
the table that can be asked about it: `status = bounced` → `hard_bounce`, global; `status = complained`
→ `complaint`, that row's own brand; `leadhub_contacts.do_not_contact` → `manual`, brand-scoped.

`status = unsubscribed` is **left alone**, and that is the decision in this migration rather than an
omission from it. A per-list unsubscribe is a scoped withdrawal of consent for that list, and
`marketing.unsubscribe.global_opt_out` already defaults to `false` — somebody decided deliberately
that a list unsubscribe is not a global opt-out. Promoting those rows would reverse that decision
inside a migration nobody reads, and destroy legitimate subscriptions on the brand's other lists.

It is idempotent, it never overrules a suppression somebody already released (a released row carries a
name, a date and a stated reason; a backfill does not get to overturn that), and its `down()` removes
only the rows it wrote. It runs today against zero rows, which is the whole argument for writing it
now instead of after a second brand's mail is flowing through the same system.

### Notes

- Suite: **198 passed (733 assertions)** on the flat driver, **197 passed (731)** on eloquent,
  including 11 new gate cases and 5 new migration cases.
- One test fails on both drivers, and it is not this release's:
  `PreferenceCenterBrandTest > it shares one contact across the brands` asserts that one address in
  two brands carries one LeadHub contact. `statamic-leadhub` v1.11.0, released the same day, made the
  flat contact store brand-isolated — so it now carries two. Verified pre-existing by running the
  v1.7.1 tree against this same vendor directory. It belongs to LeadHub's release, not to the gate.
- The new migration is guarded: an install without the suppression tables meets a no-op rather than a
  crash. A migration that dies halfway leaves the addon half-installed, which is worse than a backfill
  that does nothing.
- It is dated `2026_07_31` rather than `2026_07_30` on purpose. Laravel sorts every loaded migration by
  filename across all registered paths, and `2026_07_30_000001_backfill_…` sorts *before*
  `2026_07_30_000001_create_suppressions_table` — "backfill" < "create". The first version of this
  release did exactly that on a real install: the backfill ran first, met its own guard, and did
  nothing, silently. `tests/Migrations/SuppressionBackfillTest.php` now sorts both directories together
  the way the framework does, because the migration bed installs the suppression package separately and
  first, and a bed that agrees with the code is not measuring it.
- `SendMessageJob::handle()` and `StartCampaignJob::handle()` take one more injected argument. Both are
  resolved by the container in every real code path; only a test calling `handle()` by hand needs
  updating.

## 1.7.1 — 2026-07-30

### Fixed — 1.7.0 shipped a control-panel bundle that did not match its source

No behaviour changed and nothing in `src/` was touched. `resources/dist/build` is rebuilt and re-committed, because 1.7.0's copy was already stale on the day it was tagged.

**How a page with no JavaScript on it moved the CP bundle.** `resources/css/cp.css` declares `@source "../views/**/*.blade.php"`, so Tailwind scans every Blade file in the addon for class-name candidates — including the new public preference partial, which has nothing to do with the control panel. That partial contains `<input type="hidden" name="action" …>`, the scanner reads the bare word `hidden` as a candidate, and the compiled stylesheet gains `.hidden{display:none}`. One utility, twenty-one bytes, nothing in the CP uses it.

The size of the change is not the point. **A template edit is enough to change the committed bundle**, and the assumption behind "I did not touch Vue, so I do not need to rebuild" is simply false in this package. `scripts/check-dist-fresh.sh` exists because webhook-manager once shipped a rebuilt source with an un-rebuilt bundle and browsers answered `vue is not defined`; it did its job here and reported `STALE` on a tree that was otherwise fully green across both drivers and Vitest.

Recorded so the next release does not re-learn it: **run `npm run build:check` after any change under `resources/views/`, not only after touching `resources/js/`.**

## 1.7.0 — 2026-07-30

### Added — a preference page, so an unsubscribe link is a choice instead of a door

**What was already right and has not been touched.** The unsubscribe token identifies one *subscription* — one address on one list — so the link has always been per list. Somebody who leaves the newsletter stays on the events. That is the correct model and it is unchanged.

What was missing was everything after it. `unsubscribed.blade.php` was six lines: "you have been removed from X", full stop. It never mentioned that the same brand runs four other lists, and it offered no way to change any of them. On adriangoldner.com, which runs five (`newsletter`, `chorleitung`, `saenger`, `events`, `offers`), a reader who only wanted the events had to wait for four more mails and click four more links to get there. The realistic thing for them to do instead is press the spam button, and that costs the sending domain far more than the four subscriptions were worth.

**The page.** `GET /!/marketing/preferences/{token}` — the same token the unsubscribe link carries, in the same route group, with the same brand derivation 1.5.0 built (`SetBrandFromRouteValue` on `Subscription.token`). It lists every list of that brand with its current state and lets each one be switched on or off. `POST` to the same URL applies a selection or, as a separate and deliberately separated action, ends everything.

**No login, and that is a decision rather than an omission.** Almost no subscriber has an account on the site that mails them, and a registration form standing between a person and their unsubscribe is a dark pattern with a password field on it. The token is the credential. There is also no session to read the brand from — this page is opened by a stranger's browser, exactly like the unsubscribe link, which is why the derivation has to come from the token and not from context.

**The unsubscribe page is now the entry rather than the end.** The unsubscribe still happens on arrival, unchanged; the page then shows what is still running and the controls to change it. That is the one moment the reader is certain to be looking.

**"Unsubscribe from everything" means every list of this brand.** Not the CRM-wide opt-out. Somebody who is done with this brand's mailings has said nothing about another brand's, and nothing at all about transactional mail, which does not rest on consent in the first place. Where an install has switched on `marketing.unsubscribe.global_opt_out`, that setting still applies, through the ordinary unsubscribe path as everywhere else, rather than through a second rule here.

**Switching a list back on does not ask for a second double opt-in.** The token was delivered to the address it belongs to, which is the same proof a confirmation mail collects: send something to the mailbox and see it come back. Asking twice would re-establish a fact the click has already established, and would mean the only way back is to wait for a mail that, by definition, is no longer being sent. The restored consent is written to the LeadHub timeline with `reason: preference_center` and `consent_proof: unsubscribe_token`, so the record says *how* it was given and not merely that it exists. `markSubscribed()` takes that metadata now; called without it, it behaves exactly as before.

### Added — the rule that decides whether this page is a feature or a hole

The token is in **every** mail this brand sends. It has been through mail clients, forwarding rules, corporate scanners and link checkers. So it may end consent, and it may restore consent *the person themselves* ended. It may never lift a **block**.

A hard bounce, a spam complaint, and an opt-out an editor recorded by hand are decisions made *about* an address, not by whoever is holding one mail from it. A click must not undo them. On the page the row still appears and is not switchable, with one sentence that says sending to this address is blocked and nothing more: the reason is known internally and is deliberately not rendered, because the page cannot know who is reading it.

**The check is bound to the state the addon already keeps, not to a suppression list of its own.** `do_not_contact` on the LeadHub contact is what `leadhub.hard_bounce_opt_out` sets, what `leadhub.complaint_opt_out` sets, what `marketing.unsubscribe.global_opt_out` sets, and what the CRM sets by hand. One question covers all four sources, and there is no second list to drift out of step with the first. Row-level blocks — a subscription sitting at `bounced` or `complained` — are read off the row.

Blocked rows are left alone in **both** directions. Saving the form without a blocked row's checkbox does not rewrite it to `unsubscribed`: that status is the reason the sending path stopped, and overwriting it as a side effect of somebody adjusting a different list would lose it.

**The enforcement is in the service, not the template.** A disabled checkbox is a suggestion; a crafted POST is not. `SubscriptionPreferences::apply()` refuses regardless of what arrives, and the test that matters most in this release posts the full list of handles for a blocked contact and requires that nothing is created and nothing is changed.

### Added — what the token discloses, written down on purpose

The page shows the address the token belongs to and which of this brand's lists it is on. That is a deliberate trade, recorded in `PreferenceCenter`'s own docblock rather than left implicit: the token was delivered to that mailbox and is repeated in every mail the brand sends, so whoever holds it can already read the mail those memberships are visible in. It grants nothing that was not already there, and without it the page cannot do its job.

What the token must not do is widen. It never reaches past its own brand, and a handle belonging to another brand is answered exactly as a handle nobody has — telling a stranger which handles exist elsewhere on the install is telling them which brands exist.

### Changed — the public pages can show what the server refused

1.5.3 took "the button did nothing and said nothing" out of the control panel. The same failure out here is worse, because a reader who cannot see why a change was refused does not file a ticket, they report the mail as spam. Errors that belong to a row are rendered under that row; anything else lands in a block above the form. Nothing the server says can fall through the floor.

### Unchanged — the RFC 8058 one-click path

`POST /!/marketing/unsubscribe/{token}` still answers `204` with an empty body and no page, and is still exempted from the forgery check. That endpoint is for Gmail and Outlook, not for people; giving it a body would break deliverability. A test now pins the empty body as well as the status, so the preference work cannot leak into it later.

### Added — twenty checks, and a demonstration of which rule each one holds

`tests/Feature/PreferenceCenterTest.php` (14) covers the page itself; `tests/Feature/PreferenceCenterBrandTest.php` (6) covers it under multi-brand. The suite is 183 on the flat driver and 182 on eloquent, from 163 and 162.

**Demonstrated rather than asserted.** With `src/`, `routes/` and `resources/` stashed and the two test files left in place, 18 of the 20 fail and the 163 that were green before stay green. The two that pass are meant to: one pins that the RFC 8058 endpoint is unchanged, the other pins the premise the multi-brand file rests on.

Two narrower demonstrations name what each group actually holds:

- With the block check neutralised so it always answers "not blocked", exactly two cases fail, and the first thing they report is a *new subscription created for a contact the sending is blocked for*. Nothing else in the file moves.
- With the brand derivation removed from the two new routes, five of the six multi-brand cases fail. The sixth passes, correctly: it asserts that both brands' subscriptions carry the **same** `contact_uuid`, because LeadHub keeps one contact per address. That is the premise — the identity that finds a person's other subscriptions matches across brands on its own, and only the brand scope stops it. It is used as the same address in both brands throughout that file for exactly this reason.

### Added — German and English text for the page

`resources/lang/{de,en}/public.php`. Somebody reading this page came to leave, so the tone is the same plain register the rest of the public pages use: no exclamation marks, no persuasion, no offer to reconsider. The one sentence about blocked rows says that sending is blocked and stops there.

## 1.6.5 — 2026-07-30

### Fixed — the scheduled send was registered twice

`schedule:list` carried `marketing:send-scheduled` twice on every real install. The registration hung off `app->booted()`, and in a Statamic application those callbacks fire twice — something this package already knew, because `registerSiblingBridges()` says so in its own comment and leans on the bridges being idempotent. A schedule registration is not idempotent, and nothing had noticed.

Measured rather than reasoned about: `registerSchedule()` is called once, the booted callback runs twice.

**Nothing broke, and only by accident.** `onOneServer()` with a fixed name means the second copy loses the mutex and is skipped. That is luck, not design — the next command added here without `onOneServer()` would simply run twice. `callAfterResolving(Schedule::class)` binds to the Schedule singleton instead, so the callback runs once no matter how often the application announces that it has booted.

### Added — a check that can actually go red

The first version of the accompanying test passed against the unfixed provider, because Testbench fires the booted callbacks only once and never reproduced the condition. It now replays them, which is what a Statamic application does. That replay is the load-bearing part of the file: a check that cannot fail is not coverage.

It counts whatever is registered rather than asserting against today's list, so a command added later is covered without anyone remembering to come back. It is scoped to this package's own commands — a sibling carrying the same defect is a finding to report there, not a reason to fail here.

## 1.6.4 — 2026-07-28

### Fixed — updating from before 1.3.0 dropped the consent unique and did not replace it

**Affected: installs created under 1.2.1 or earlier that updated to 1.6.1, 1.6.2 or 1.6.3. Nothing else.** An install that had already run `2026_07_24_100001` — anything on 1.3.0 or later — is untouched by this, because a migration that is recorded as run never runs again.

**How to tell whether it happened to you.** Update to 1.6.4 and run:

```
php artisan marketing:consent-integrity
```

It reads the indexes that are on `marketing_subscriptions` right now and the rows that are in it, and says plainly whether one address on one list is still one consent record. It changes nothing.

Three other fingerprints, in case the update is still in front of you rather than behind you:

- `php artisan migrate` stopped with `SQLSTATE[42000] … 1072 Key column 'uniqueness_key' doesn't exist in table` (MySQL) or `SQLSTATE[23000] … UNIQUE constraint failed: index 'ms_brand_list_email_unique'` (SQLite);
- running it a second time stopped with something else entirely — `1060 Duplicate column name 'brand_id'`, or `duplicate column name: brand_id` — which is the interrupted first step complaining, not the actual problem, and is the message most likely to send you looking in the wrong place;
- `select * from migrations where migration like '%add_brand_id_to_marketing%'` returns nothing, while `marketing_subscriptions` already has a `brand_id` column.

**What was wrong.** 1.6.1 added `uniqueness_key` to the create-table migration for `marketing_subscriptions` and, in the same commit, rewrote the already-published `2026_07_24_100001` to build the consent unique over that column. On a fresh install the column is there, because the create-table migration now makes it. On an install created before 1.3.0 the table predates the column, the create-table migration is recorded as run and never runs again, and `2026_07_28_000002` — the migration that adds the column to existing installs — sorts *after* the one that had already started using it.

**What that cost.** Not the abort. The state it left behind. Neither engine rolls DDL back, and the statement that failed came *after* the one that dropped `(list_handle, email_normalized)`. So the update ended with `marketing_subscriptions` carrying no consent unique of any kind, and with the migration not recorded, so nothing in the install knew. The sign-up form kept working; it stopped refusing duplicates. The most expensive promise this addon makes — one address, one list, one consent record — was open, silently, until somebody happened to look.

The two engines got there differently and it is worth writing down. MySQL refuses outright with ERROR 1072. SQLite reads a double-quoted identifier that resolves to no column as a *string literal*, so `create unique index … ("brand_id", "uniqueness_key")` quietly became a unique over `brand_id` and a constant — unique on the brand alone. On an empty table that is accepted and then corrected seconds later by `2026_07_28_000002` in the same `migrate` run, which is exactly why it was never seen here: the development hub and every test install are empty. On a table with rows the second row collides and the statement dies.

**What changed.** `2026_07_24_100001` now decides which consent unique to build from the schema it actually finds. Where `uniqueness_key` exists it builds the hash index as before. Where it does not, it builds the brand-scoped natural tuple `(brand_id, list_handle, email_normalized)` — which is not a new invention but precisely the index 1.3.0 through 1.6.0 shipped, fits InnoDB at 2048 of 3072 bytes, and is converted to the hash form by `2026_07_28_000002` moments later in the same run.

Correcting the order alone would have fixed the next install and left every install that already broke exactly as broken, so the whole migration is now re-runnable: it adds `brand_id` only where it is missing, drops only indexes that are actually present, does nothing at all where the wanted index is already in place, and stops with the offending values named rather than a bare integrity error where the rows cannot carry the index. Re-running it on a half-migrated install finishes the update and puts the consent unique back.

**If duplicates were created in the meantime.** They are real sign-ups that a form accepted while the constraint was missing, so nothing here deletes them. `php artisan migrate` refuses and names the list/address pairs it found; `marketing:consent-integrity` prints every colliding row with its id, status, confirmation date and source. Which of them is *the* consent record — the one whose confirmation timestamp the install will stand behind if anybody asks — is a question about people, not about rows. Delete the others by hand, then migrate again. `marketing:consent-integrity --repair` rebuilds the index alone once nothing is in the way, and refuses while anything is.

### Added — the migrations are finally tested against a database with data in it

This is the actual finding. Not the wrong order — the fact that no migration path in this addon was ever run over anything but empty tables, so a defect that only exists when rows are present had nowhere to be caught. Three releases went out green.

`tests/Migrations/` is a suite of its own, on a connection of its own, and it is in both `phpunit.xml` and `phpunit.mysql.xml`, because the failure behaves differently on each engine and one run cannot speak for the other.

It does not name the two migrations that were broken. It walks `database/migrations/` and runs the files one at a time, seeding every marketing table that exists before each one — so every migration in the addon meets rows written by an older schema, including migrations added long after this was written. `tests/Fixtures/released-migrations/` holds the migration sets as published in 1.2.1, 1.6.0 and 1.6.3, and the suite installs each of them, puts data in and upgrades forward: twelve sign-ups across five lists, two addresses on two lists each, one differing from another only by case, and every lifecycle state the double opt-in flow can leave behind.

The half-migrated install is not described from memory, it is produced: the suite runs the 1.6.3 migrations exactly as published, watches them die, confirms the consent unique is gone by writing a duplicate that the database accepts, and only then applies the current ones and requires the constraint back.

**Every check is behavioural.** "The migration ran" and "the constraint is there" are not the same statement, and mistaking one for the other is the whole defect. So nothing here asserts that `migrate` exited zero, or that an index of a given name exists. It writes the row the constraint is supposed to refuse and requires the database to refuse it.

Demonstrated rather than asserted: with the 1.6.3 file put back in place, four of the nine cases fail — on SQLite with the unique violation, on MySQL with ERROR 1072 and then the misleading `1060 Duplicate column name 'brand_id'` on the retry. The cases that keep passing are the fresh-install ones, which is exactly the coverage that existed before and exactly why none of this was found.

### Changed — the MySQL key-length probe can read the schema it is measuring

`tests/Unit/IndexKeyLengthTest.php` compiles the migrations through Laravel's MySQL grammar in pretend mode to measure index bytes without a server. Under `pretend()` a `select` returns nothing, so a migration that asks `Schema::hasColumn()` or `Schema::getIndexes()` before deciding what to build was being told the table is empty of everything — which, now that `2026_07_24_100001` branches on exactly those answers, would have had the probe measuring a schema no install ever holds.

It now runs two connections interleaved: the probe compiles the DDL through MySQL's grammar, and a real SQLite database one file behind answers every question the migrations ask about the current schema. Same measurements, on the schema that actually results.

## 1.6.3 — 2026-07-28

### Changed — the route parameter guard checks the rule, not a snapshot of the siblings

No defect in this addon, no route changed, and nothing in `src/` was touched. What changed is that 1.6.2's guard test was asserting something false.

That test carried a hand-written map of the names other installed packages bind application-wide, and it named `webhook`, `endpoint`, `rule` and `template` as claimed by `goldnead/statamic-webhook-manager` and `automation` as claimed by `goldnead/statamic-automations`. Webhook-manager renamed its four in its 1.7.0 and automations renamed its one in its 1.6.0. All five names are free. The entries were harmless — an entry for a name nobody binds matches nothing, which is why the suite stayed green — but a check that describes the world incorrectly is a check nobody can rely on, and correcting the five names would only have reset the clock on the same problem.

A snapshot of the siblings can only ever describe them as they are today. It says nothing about the addon that starts binding `{handle}` next month, which is exactly the case that hurts, and it has to be maintained by five repositories at once. What replaces it is the rule webhook-manager arrived at in its 1.7.0:

> **A `Route::bind()` is registered on the router, not on the package that calls it. Bind only names that unambiguously belong to your addon — specific enough that no sibling would reach for one by accident. Names you do *not* bind may stay as generic as they like: nothing resolves them, so nothing can be taken from anyone.**

That is a property of *this* package, so this package's own suite can enforce it without knowing anything about its neighbours.

`it binds only parameter names that belong to this addon` reads the `Route::bind()` calls out of this package's own `src/` — comments stripped, string literals only, and a call whose name is not a literal fails the test rather than escaping it — and requires every name found to match `marketing` + a capital. This addon binds nothing at all today, so the rule costs it nothing, which is precisely why it is worth pinning now: the binding that hurts is never the one somebody weighed, it is the one added later because binding by the entity's obvious name looked like the obvious thing to do.

`it does not swallow a sibling addon's generic route parameter` is the behavioural half. `tests/TestCase.php` now mounts stand-in routes for a sibling package — `{automation}`, `{rule}`, `{template}`, `{webhook}`, `{endpoint}`, `{handle}`, `{id}`, `{slug}`, `{record}`, each doing nothing but echoing its own value — and the test asserts every one answers with what it was given. They live in the bed rather than in the test body deliberately: a route added from inside a test body is shadowed by Statamic's `{segments?}` frontend catch-all and answers 404 whatever the bindings do, which would have made the check pass for the wrong reason.

Demonstrated rather than asserted: with a `Route::bind('handle', …)` added to a service provider in this family, the old three-test file stayed **green on all three**, while the new file fails three of its five and names `{handle}` in both directions — once as bound-but-not-ours, once as a sibling route answering 404 instead of its own value.

`1.6.2`'s first test is kept as it was: it pins that the CP bed mounts `SubstituteBindings`, without which no `Route::bind()` has any effect in tests and the whole file would pass for nothing. So is the check against `statamic/cms`, reduced to the ten CMS entity names it actually binds — that list is third-party, short and stable, and stays hand-kept for the same reason the sibling list could not.

**What deliberately did not change: `{handle}`, `{subscription}`, `{token}` and `{uuid}`. They are generic and they are staying. Renaming them would move text without removing any exposure, because they are not bound — nothing resolves them, so nothing can collide. The rule above is what protects them: a package that binds must bind a name of its own, and `handle` is nobody's own.**

## 1.6.2 — 2026-07-28

### Added — the suite can finally see route bindings

No defect in this addon. What is fixed is that the suite could not have found one, for a whole class of failure.

`Route::bind()` is registered on the router, not on a package. A binding one addon registers for `{rule}` or `{template}` applies to every route with that parameter name in every other addon installed beside it. `goldnead/statamic-leadhub` 1.8.0 shipped `/scoring/{rule}` while `goldnead/statamic-webhook-manager` binds `rule` to its own rule repository, and on the production hub, which has both, editing or deleting a scoring rule resolved against the wrong repository, returned 404, and reported nothing. A button that did nothing and said nothing, through a release.

**Why a green suite would not have found the same thing here.** `tests/TestCase.php` mounted the CP routes without `SubstituteBindings`. That middleware is part of Statamic's real CP middleware group and is the thing that actually applies a route binding — without it, every `Route::bind()` in the process was inert in this bed, including any a sibling addon registers. The failure was not under-tested, it was unobservable: no test written in this suite could have exhibited it. The middleware is now part of the CP route group here. Nothing in this addon uses implicit model binding — every routed controller method takes `string $handle` — so it changes no other behaviour, and the 147 tests that were green before are green after.

Demonstrated rather than asserted: with the middleware taken out again, the first case in the new `tests/Feature/RouteParameterCollisionTest.php` fails and everything else stays green.

### Added — the route parameter names are checked against the rest of the family

`tests/Feature/RouteParameterCollisionTest.php` reads this addon's own parameter names out of `routes/cp.php` and `routes/web.php` and checks them two ways.

The first is exact: a hand-maintained list of the names that packages installed beside this one bind application-wide, read off the running hub — `automation` from statamic-automations, `webhook` / `endpoint` / `rule` / `template` from statamic-webhook-manager, and the ten CMS entity names from statamic/cms. Using one of those is a live defect, and the test names the package that would swallow the route. Renaming `/{handle}/preview` to `/{template}/preview` makes it fail with exactly that sentence.

The second is a judgement call made explicit: `handle` and `token` are generic enough that a sibling could claim either tomorrow, so they are recorded in the test with their reason. A *new* generic parameter fails until somebody either renames it or writes down why it stays.

**What this cannot do.** A collision only exists once two packages are installed together, and no package can see its siblings from inside its own suite. The reserved list is a snapshot maintained by hand; it will not catch an addon that starts binding a name nobody binds today, and `handle` — used here for lists, campaigns and templates alike — is precisely such a name. The hub remains the only place the real answer is measurable. What the test does buy is that the next `{rule}` fails in the addon that introduces it, before it reaches a hub.

This addon's four parameters (`handle`, `subscription`, `token`, `uuid`) collide with nothing bound today.

## 1.6.1 — 2026-07-28

### Fixed — the consent unique was two thirds of the way to being unbuildable

`ms_brand_list_email_unique` spanned `(brand_id, list_handle, email_normalized)`.
Under utf8mb4 every character costs four bytes, so each `varchar(255)` costs
1020 and the index MySQL builds is **2048 of the 3072 InnoDB allows**. It
worked. It was also the addon's most important constraint — one address, one
list, one brand, one consent record — sitting one added column away from a
migration that fails with SQLSTATE 1071 — which is what kept two
`statamic-notifications` tables from ever being created on the production hub,
through four releases.

**Why a green suite did not find this.** The suite runs on in-memory SQLite,
and every mechanism in that paragraph is a MySQL mechanism. SQLite has no index
length limit, stores no fixed column widths (it accepts `varchar(255)` and
ignores the 255), and has no per-character byte cost to multiply. The migration
was not passing a test — there was no test for it to pass, because the
constraint it approaches does not exist in the engine the tests use. 136 green
tests and a schema whose limits were never once measured is not a contradiction;
it is the same blind spot in every addon in this family.

**Why the index was replaced rather than shortened.** A prefix index
(`list_handle(64)`) would have fit and would have been the smaller diff. It
would also have declared two lists whose handles share their first 64
characters to be one list — swapping a migration that fails loudly for consent
records that are quietly merged. Narrowing the columns themselves is worse
still: a handle is generated from a name nobody caps, and an address is not
ours to truncate.

`marketing_subscriptions` now carries a `uniqueness_key` — a SHA-256 of
`(list_handle, normalized email)`, maintained by the model on every save — and
the unique is `(brand_id, uniqueness_key)`, **264 bytes**. Every character of
both values is still covered, nothing is truncated, and `brand_id` stays a
column of the index rather than an ingredient of the hash, so the tenant
boundary remains legible in the schema and usable as a range. Two brands still
hold fully independent consent for the same address on the same list, which is
the guarantee the brand column exists for. `SubscriptionService::subscribe()`
looks a subscription up by the same key the index is built on, so the check and
the constraint can no longer disagree about what "already subscribed" means.

**Every other unique was measured too.** The widest remaining are the three
`(brand_id, handle)` uniques at 1028 bytes and the across-all-brands
`marketing_lists.handle` at 1020 — all under half the limit, which is now the
asserted rule rather than an accident. And none of them covers a nullable
column: a SQL unique does not constrain NULL, so an index over a nullable column
enforces nothing for exactly the rows it exists for. That is what let a whole
recipient type in notifications never have a uniqueness guarantee at all. It was
checked here and this addon does not have it — `uniqueness_key` is NOT NULL for
the same reason.

### Fixed — a rejected handle on an edit form was shown nowhere

1.5.3 made every mask render what the server sent back, and split the work in
two: keys with a field of their own are shown at that field, everything else in
a summary above the form. `handle` was on the first list — correct while
creating, where the handle input exists, and wrong on an update, where it is
`v-if="isCreating"` and therefore absent. A rejected handle was filtered out of
the summary as "already shown at its field" and had no field to be shown at. It
was rendered nowhere, which is exactly the failure 1.5.3 set out to end.

The campaign and template controllers validate `handle` through the same shared
validator on store and on update, so this was one changed payload away from
being reachable. The three edit pages now decide per key whether its field is
actually on screen, and anything without one falls through to the summary.

### Fixed — the last `Field` that sized itself with `flex-1`

The subscriber filter row still had `<Field class="flex-1 sm:max-w-xs">` for the
search box. That is the same trap 1.5.1 fixed one row above it: `flex-1` is
`flex: 1 1 0%`, Statamic's `Field` brings its own `min-w-0`, and together they
remove the floor that stops a column collapsing. `sm:max-w-xs` cannot help — a
max-width is not a floor, which is why the 1.5.0 attempt failed. It has the
explicit width the add-subscriber row uses.

### Added — a JavaScript test layer

`npm test` runs Vitest against the Control Panel components — **24 tests** over
the pages this release touched. Adopted from `statamic-webhook-manager` 1.6.0,
which established the shape: no second build chain, the existing `vite.config.js`
swaps the Statamic Vite plugin for the plain Vue plugin under `VITEST`, and
`tests/js/setup.js` installs the `__STATAMIC__` global the `@statamic/cms/*`
shims destructure at import time.

It is not backfilled coverage. It covers the classes of defect this round found:
that a rejected form says so at the field or in the summary and not nowhere,
that a `Field` never sizes itself with `flex-1`, and that the handful of places
where a stored `false` or a `0` has to survive the round trip still do —
`recipients: 0` reads as none rather than as unknown, and a list whose double
opt-in is explicitly off is not read as "use the default". Each of those is a
one-character edit away from being wrong and every existing test would have
stayed green.

Both fixes above were written against a failing test. Every guard was verified
by breaking the thing it guards and watching it go red.

### Added — the schema can be measured against MySQL, without MySQL

`tests/Unit/IndexKeyLengthTest.php` compiles the addon's own migration files
through Laravel's MySQL grammar in pretend mode — no server, no connection,
nothing to install in CI — and measures every index the way InnoDB would: total
key bytes, headroom against half the limit, and whether a unique covers a column
that may be NULL. It reads the real migration files, so it cannot drift from
them. Against the 1.6.0 schema it reports 2048 bytes and fails, which is the
check that was missing.

The whole suite can also be run against a real MySQL server:
`vendor/bin/pest -c phpunit.mysql.xml`. Same tests, `DB_DRIVER=mysql`. Both are
lifted from `statamic-notifications` 1.0.4.

### Migration

- `php artisan migrate`. The create-migrations are corrected too, so a new
  install never builds the wide index in the first place; that reaches nobody
  who already ran them, which is what the new migration is for.
- `2026_07_28_000002_rebuild_subscription_uniqueness_keys` adds the column,
  backfills it from the existing rows and swaps the index. It is idempotent and
  a no-op on a fresh install.
- No rows can be lost or merged. The new key is a pure function of the two
  columns the old unique already covered, and neither of them is nullable, so
  two rows cannot collide under the new index without having collided under the
  old one.

### Notes

- Suite green on both drivers: flat **147 passed + 7 skipped**, eloquent
  **146 passed + 8 skipped** (baseline 136 / 135). Plus **24** Vitest tests.
- Verified against **MySQL 8.4** as well as SQLite, which is the point of the
  exercise: `vendor/bin/pest -c phpunit.mysql.xml` — the same 147 passed and 7
  skipped.
- `tests/TestCase.php` now names its connection `testing` and honours
  `DB_DRIVER`. `EloquentUserCompatTest` pointed Statamic's user tables at the
  connection named `sqlite`, which was a second, empty in-memory database as
  soon as the suite's own connection was renamed; it follows the suite now.
- The first MySQL run turned up two test fixtures that only ever worked because
  SQLite is lenient, which is the whole argument for having the run: one wrote a
  41-character value into a `char(36)` column, and one attached a message to a
  subscription id that did not exist, across a foreign key SQLite does not
  enforce. Both are test-side; no production code was involved.

## 1.6.0 — 2026-07-28

### Added — the flat driver works under multi-brand

The plan was to remove this driver. Multi-brand was said to require eloquent
storage, because a YAML file carries no brand, so the flat driver looked like a
dead end that only kept people from the driver that works. Two findings turned
that around.

The first: **adriangoldner.com runs five real mailing lists on it.**
`content/marketing/lists/{newsletter,chorleitung,saenger,events,offers}.yaml`,
`MARKETING_DRIVER` unset, so the default. A removal would have stranded every
one of them, and there was nothing wrong with them.

The second is the one that made the work small. **The flat driver only ever
held definitions** — lists, campaigns, templates. `Subscription`, `Message` and
`MessageEvent` are Eloquent models with `HasBrand` in every driver, always were.
The consent data, the part that must never bleed across brands, was never in
those files. What was missing was not isolation of anything sensitive; it was
the definitions saying which brand they belong to.

**A brand is a directory, not a key in the file.** Under multi-brand:

```
content/marketing/acme/lists/newsletter.yaml
content/marketing/contoso/lists/updates.yaml
```

A `brand:` key inside the file was the alternative and was rejected. The handle
is the filename here, so a key would give every definition two identities that
can disagree, and reading one brand's lists would mean opening every other
brand's files to find out they are not yours. Worse, a missing or misspelt key
falls through to the default brand — a leak that looks like a typo. With a
directory the isolation is structural: a brand's read never opens another
brand's file, and being in the wrong place is visible in `ls` and in a diff.

**Nothing has to move for an install to keep working.** Files in the pre-1.6
layout are read as the default brand's — and as no other brand's, ever. A
single-brand install keeps writing there too, so its content directory looks
exactly as it did in 1.5. `php artisan marketing:migrate-flat-brands` moves
them into the brand directory once a second brand exists; `--dry-run` prints
the moves and touches nothing, `--brand=` picks the target. It only moves,
never overwrites and never deletes, refuses on conflict, and a second run finds
nothing to do. An update that opens to empty lists and a command that repairs
it afterwards was not an acceptable shape for this.

### Fixed — the public subscribe endpoint had no brand to find, in either driver

Every other public route derives its brand from a token: one token, one record,
one brand. A subscribe form carries no token. It carries a list handle, and
until now that was traced back to a brand through `MailingListRecord` — an
Eloquent model that does not exist in flat storage. On a flat multi-brand
install the endpoint therefore ran with no brand at all, the store failed
closed, and the list the form named did not exist. Every public sign-up, 404.

The lookup now goes through `HandleOwnership`, which answers for both drivers
— a query in one, a path in the other — and keeps the guarantees brand-context
established unchanged: two owners throw rather than being guessed between, no
owner sets no brand and leaves the response exactly as it was, and the brand is
always set explicitly so a long-lived worker cannot serve one visitor under the
previous visitor's brand.

### Fixed — list handles were unique per brand, which is the one thing they must not be

This is what the middleware rests on, and it was not true. The brand-scoping
migration turned `marketing_lists.handle` into a `(brand_id, handle)` unique —
correct for campaigns and templates, wrong for lists, because brand-context
states the precondition plainly: a column that is unique only *per brand* must
never be used to derive a brand from. Two brands could each own a list called
`newsletter`, and the next sign-up for that handle would raise
`AmbiguousBrandRecord` — the form dead in both brands at once, and no way to
tell from the outside which brand the visitor meant.

The across-all-brands unique is restored, and both drivers now enforce it
rather than assume it: the flat store refuses the write, and the control panel
asks first, so an editor gets a message at the handle field naming the brand
that holds it instead of a 500. An install that already has the same list
handle in two brands stops the migration with both names — that state already
breaks sign-ups and cannot be resolved by picking a winner.

### Fixed — `marketing:send-scheduled` sent nothing at all under multi-brand

A console run has no session, so no brand is current, and both drivers then
answer with nothing. The command printed "No campaigns due." every minute
forever while every scheduled campaign quietly missed its date — the silent
failure `RunsForEachBrand` (brand-context 1.3.0) exists for, still unfixed
here. It now walks every brand, with `--brand=` to restrict a run. Single-brand
installs run the body once, exactly as before.

### Why 1.6 and not 2.0

A major would have been right if this forced existing installs to act. It does
not. A single-brand flat install updates and keeps its layout, its paths and
its behaviour unchanged — the store writes to `content/marketing/lists/…` as
long as multi-brand is off, and the new command is only needed once a second
brand exists. adriangoldner.com pins `^1.0` and would not have received a 2.0;
receiving this is the point, because it is the install that stays on the
pre-1.6 layout and must keep working.

### Notes

- Suite green on both drivers: flat **136 passed + 7 skipped**, eloquent
  **135 passed + 8 skipped** (baseline 104 / 7). Every part was verified to
  fail without its implementation, by removing it and re-running.
- Cross-brand coverage is the bulk of it: two brands with their own lists,
  campaigns and templates seeing nothing of each other; a public sign-up
  landing in the brand that owns the list and not in the default one; an
  unknown handle setting no brand and not inheriting the previous request's;
  the pre-1.6 files readable by the default brand and invisible to every other;
  the migration losing nothing, refusing rather than overwriting, and being a
  no-op the second time.

## 1.5.3 — 2026-07-28

### Fixed — the rest of the control panel still swallowed every rejection

1.5.2 fixed the campaign form because that is where the reused-handle guard runs. The gap was never limited to that one form: no other page in this control panel rendered what the server sent back either. Creating a list, renaming a list, creating a template, adding a subscriber, sending a test mail, scheduling a send — every one of them answered a rejected input the same way. Nothing was written, nothing was said, and the button looked broken. That is worse than the bug a guard prevents, because a person who cannot see why their input was refused will try the same thing again.

Errors now appear **at the field they belong to**, using the `error` prop Statamic's `Field` component already has — the same thing LeadHub 1.5.0 does for its contact form, so the two addons behave alike. A summary above the form was the cheaper option and would have been the wrong one: the sender fields sit in a sidebar, and a red line at the top of the page does not tell you which of eleven inputs is the problem.

Not every rejection maps to an input. A test send refused because the campaign has no list arrives under a key no field carries. Those go into a collected block above the form, so nothing the server says can fall through the floor. Both paths, not one: a page that only had the summary would have hidden the field errors' location, and a page that only had field errors would have dropped everything else.

The three listing pages send nothing but delete requests, and the server currently has no rejection for a delete. They were wired up anyway, so that a delete guard added later is not silently swallowed a second time.

**Guarded structurally, not by a browser test.** There is no JS test runner in this addon and this release does not introduce one. Instead `CpValidationVisibilityTest` reads the page components: every function that submits must handle the rejection, every submitting page must have somewhere to put an unassignable error, and every field the controllers validate must be rendered somewhere. A form added without error handling fails the suite.

## 1.5.2 — 2026-07-27

### Fixed — validation errors were invisible in the campaign form

Found while photographing the 1.5.0 fix: the rejected handle worked exactly as intended, and the screen showed nothing at all. The request came back with errors, nothing was saved, and Save simply looked dead. A guard nobody can see is barely better than the silent wrong send it replaced, so the form now renders what came back.

The same gap exists elsewhere in this control panel — no page in it rendered validation errors — but only the campaign form is fixed here, because that is the one this release's guard runs in.

## 1.5.1 — 2026-07-27

### Fixed — the e-mail field fix in 1.5.0 did not work

1.5.0 replaced `flex-1` with `flex-1 min-w-56`, which was the right diagnosis and the wrong remedy: Statamic's `Field` brings its own `min-w-0`, and between two utilities of equal specificity the stylesheet order decides — so the column still computed to zero width and the neighbouring field still sat on top of it. Measured in a running control panel rather than reasoned about: 26 px before, 313 px after. The field now carries an explicit width, which is what its two neighbours already did.

## 1.5.0 — 2026-07-27

### Fixed — the public routes worked for nobody under multi-brand

Confirmation links, unsubscribe links and open/click tracking are opened without a session, so no brand was current and the fail-closed scope hid the very record the token pointed at. A subscription could never be confirmed and stayed pending forever; every unsubscribe link in every sent mail led to a 404; and tracking was the quiet one — the pixel returned 200 and the redirect returned 302 while nothing at all was stored, so campaign statistics sat at 0 % and nothing looked broken.

The brand now comes from the token, which belongs to exactly one record (`SetBrandFromRouteValue`, brand-context 1.4.0). Each column used for this carries a unique index across all brands; that is the precondition, and the lookup throws rather than guesses if it is ever violated. An unknown token still does exactly what it did before: nothing.

**Multi-brand requires the eloquent storage driver.** Flat-file lists live in YAML and carry no brand at all, so the public subscribe endpoint has nothing to derive one from.

### Fixed — one-click unsubscribe answered 419 to every mail provider

The CSRF exclusion on the RFC 8058 route named `ValidateCsrfToken`, but the class in the stack is `PreventRequestForgery` — Laravel renamed it, and excluding a name that is not there matches nothing silently. Gmail and Outlook call this endpoint themselves and read a 419 as a broken unsubscribe path, which is the kind of thing that costs deliverability. All known names are now listed.

### Fixed — reusing a campaign handle reported a send that never happened

Deleting a campaign leaves its delivery rows behind on purpose: they are the record of what went to whom. But a message is identified by campaign handle plus subscriber, so a new campaign on the same handle inherited them, skipped every recipient as already sent, finished instantly and reported success — with not one mail sent. Creating a campaign on a handle that already has delivery history is now refused, with an explanation. History is kept, and no send is ever claimed that did not happen.

### Fixed — an editor's addition was confirmed and asked to confirm at the same time

Adding a subscriber in the control panel deliberately bypasses double opt-in, but it did so *after* the subscription was written — by which time the confirmation mail was already on its way. The person was set to subscribed and asked to confirm the same thing. The decision now happens before writing (`skip_confirmation`); public sign-ups are untouched.

### Fixed — the e-mail field in "add subscriber" was unusable with a mouse

`flex-1` alone gave it a flex-basis of zero, so it collapsed to a sliver its neighbour overlapped.

## 1.1.0 — 2026-07-03

### Added — send to segment

- **Campaign audience narrowing via LeadHub segments.** A campaign can now target an optional **segment** in addition to its list. At send time the audience is `subscribed list members ∩ LeadHub::segmentMemberIds(handle)`, resolved live. The segment only ever *narrows*: consent is always taken from the list subscription, so a segment member who is not a subscribed list member (or who unsubscribed) never receives the campaign, and a subscriber with no linked LeadHub contact is excluded when a segment is set. No segment = the whole list, exactly as before (**backward compatible**).
- **Graceful degradation.** The facade call is guarded with `method_exists(LeadHub::getFacadeRoot(), 'segmentMemberIds')`. If the installed LeadHub predates segments, the filter is ignored (whole-list send) with a single logged warning, and the CP segment picker hides itself — no fatals.
- **CP segment selector.** The campaign form shows a segment dropdown (only when segments are available) with a live member count next to each option.
- **`segment_handle`** added to the campaign schema/data/repositories (eloquent + flat).

### Requirements

- Requires `goldnead/statamic-leadhub` **^1.1** (for the segments API). Merges after LeadHub v1.1.0 is tagged.

### Notes

- Suite green on both drivers: flat **74 passed + 7 skipped**, eloquent **73 passed + 8 skipped** (baseline 66 + 7). New coverage: intersection, consent precedence (segment member not subscribed / unsubscribed segment member never receives), no-linked-contact exclusion, backward compatibility, and graceful degradation when LeadHub lacks segments.

## 1.0.1 — 2026-07-02

### Fixed

- **Eloquent-users compatibility.** The CP base controller called Statamic-only methods (`hasPermission()`, `isSuper()`) on the raw authenticated user. On sites using the eloquent users repository the auth user is a plain model (e.g. `App\Models\User`), so every Marketing CP page crashed with a `BadMethodCallException`. Permission checks now go through Laravel's Gate (`$user->can()`, which Statamic wires up via `Gate::after` for both user drivers). Regression-tested with `statamic.users.repository=eloquent` and a plain `Authenticatable` model.

## 1.0.0 — 2026-07-02

Initial release.

- Boot-order regression tests for the sibling-addon bridges: deferred
  app->booted() registration with trailing retry, no-mark-booted while the
  sibling binding is absent, and idempotent re-boot (mirrors the LeadHub
  fix from statamic-leadhub@9fd6d6a).

- Mailing lists with per-list double opt-in and public subscribe endpoint
  (honeypot-guarded) plus `{{ marketing:subscribe }}` Antlers tag.
- Campaigns with Antlers content, reusable email templates, preview, test
  send, scheduling (`marketing:send-scheduled`), and queued batch delivery
  with optional throttling.
- Open pixel + signed click tracking, per-campaign reports, per-recipient
  message log.
- Tokenized unsubscribe pages and RFC 8058 one-click unsubscribe headers,
  optional global opt-out.
- LeadHub integration (hard dependency): contact upsert + timeline events on
  subscribe/unsubscribe, `list:{handle}` contact tags, opt-out on hard
  bounces/complaints.
- ESP feedback processing (generic/Mailgun/Postmark) — exposed as the
  `marketing.process_esp_event` inbound action when statamic-webhook-manager
  is installed; marketing events double as outbound webhook triggers.
- statamic-automations integration: `marketing.subscribed` /
  `marketing.unsubscribed` / `marketing.campaign_sent` triggers and
  `marketing.subscribe` / `marketing.unsubscribe` / `marketing.send_campaign`
  actions.
- Dual storage for definitions: flat YAML under `content/marketing/`
  (default) or Eloquent (`MARKETING_DRIVER=eloquent`); runtime data always in
  `marketing_*` tables.
- Control Panel: Dashboard, Lists (incl. subscriber management), Campaigns
  (composer + report), Templates — Inertia + Vue 3 with Statamic UI
  components, English and German translations.
