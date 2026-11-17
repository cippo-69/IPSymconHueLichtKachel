# Hue Licht Kachel

Bedienkachel für die Symcon-Kachel-Visualisierung (HTML-SDK) zu einer Leuchte oder Gruppe aus dem Modul
„Philips HUE“ (Schnittcher, Modul `HUEDevice`).

## Zwei Ansichten, eine Instanz

- **Regler-Knopf** (Kachel unter 260 px Höhe): rundes Regler-Symbol mit Ring in der aktuellen Lampenfarbe.
  Tippen öffnet per `openObject` das **Einstellungsfenster** (Eigenschaft „Einstellungsfenster“: eine Kategorie mit
  einem Link auf diese Instanz). Ohne Kategorie öffnet es das Visu-Fenster der Hue-Instanz.
- **Einstellungsfenster** (Kachel ab 260 px Höhe): alle Einstellungen der Lampe
  - Ein/Aus, Erreichbarkeit
  - Helligkeit (Regler mit Verlauf in der Lampenfarbe)
  - Weiß: Farbtemperatur in Kelvin (Bereich aus dem Profil der Kelvin-Variable) und Vorgaben Kerze … Tageslicht
  - Farbe: Farbton, Sättigung und zwölf Farbvorgaben; die Helligkeit bleibt dabei erhalten
  - Effekte: Farbschleife (colorloop), einmal blinken, 15 s blinken, Blinken stoppen

Fehlt einer Lampe eine Fähigkeit (z. B. reine Weiß-Lampe ohne Farbe), blendet die Kachel den Teil aus.

## Einrichtung

1. Modulverwaltung → `https://github.com/cippo-69/IPSymconHueLichtKachel` hinzufügen.
2. Instanz „Hue Licht Kachel“ anlegen, Hue-Instanz auswählen.
3. Versteckte Kategorie für das Fenster anlegen (z. B. „W50 Büro Stripe RGB Einstellungen“), darin einen Link auf die Instanz;
   die Kategorie als „Einstellungsfenster“ eintragen.
4. In der Visu: Link auf die Instanz neben den Lampen-Button legen (Größe 1×1), im Fenster die Kachel groß stellen.
   Die Kachelgröße stellt man im Bearbeiten-Modus der Visu je Gerät ein.

## Eigenschaften

| Eigenschaft | Bedeutung |
|---|---|
| Hue-Leuchte oder -Gruppe | Instanz des Moduls `HUEDevice` |
| Einstellungsfenster | Kategorie, die der Regler-Knopf öffnet (optional) |
| Ein/Aus über andere Variable | optional, z. B. ein Fade-Schalter mit eigenem Aktionsskript |

Geschaltet wird über die Aktionen der Hue-Variablen (`RequestAction`). Die Farbe geht über `PHUE_ColorSetOpt` mit der aktuellen
Helligkeit, weil `RequestAction` auf `HUE_Color` die Helligkeit aus dem RGB-Wert ableitet. Effekte über `PHUE_EffectSet`
und `PHUE_AlertSet`. Farben kommen aus dem Modul „Visu Design“ (W5D), falls vorhanden.
