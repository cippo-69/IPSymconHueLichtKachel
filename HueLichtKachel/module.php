<?php

declare(strict_types=1);

/**
 * Hue Licht Kachel (HTML-SDK)
 *
 * Bedienkachel für eine Leuchte oder Gruppe aus dem Modul „Philips HUE“ (Schnittcher, HUEDevice).
 * - Kleine Kachel (unter 260 px Höhe): Regler-Knopf mit Lampenfarbe; Tippen öffnet per openObject das Einstellungsfenster
 *   (Kategorie mit Link auf diese Instanz) oder, ohne Kategorie, das Visu-Fenster der Hue-Instanz.
 * - Große Kachel: Ein/Aus, Helligkeit, Farbtemperatur (Kelvin) mit Vorgaben, Farbe (Farbton und Sättigung) mit Vorgaben,
 *   Farbschleife und Blinken (Identifizieren).
 *
 * Geschaltet wird über die Aktionen der Hue-Variablen (RequestAction). Nur die Farbe geht direkt über PHUE_ColorSetOpt,
 * damit die Helligkeit erhalten bleibt (RequestAction auf HUE_Color setzt die Helligkeit aus dem RGB-Wert).
 */
class HueLichtKachel extends IPSModuleStrict
{
    private const HUE_GUID = '{83354C26-2732-427C-A781-B3F5CDF758B1}';
    private const DESIGN_GUID = '{018EEC85-8FFC-4E1F-A966-702C4BBDC2E8}';
    private const IDENTS = [
        'state' => 'HUE_State', 'bri' => 'HUE_Brightness', 'color' => 'HUE_Color', 'sat' => 'HUE_Saturation',
        'kelvin' => 'HUE_ColorTemperatureKelvin', 'ct' => 'HUE_ColorTemperature', 'mode' => 'HUE_ColorMode',
        'reachable' => 'HUE_Reachable'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('HueID', 0);
        $this->RegisterPropertyInteger('DetailID', 0);
        $this->RegisterPropertyInteger('SwitchID', 0);
        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeString('Effect', 'none');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        foreach ((array) json_decode($this->ReadAttributeString('Watched'), true) as $w) {
            $this->UnregisterMessage((int) $w[0], (int) $w[1]);
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        $watch = [];
        $design = $this->DesignID();
        if ($design > 0) {
            $this->RegisterMessage($design, IM_CHANGESETTINGS);
            $watch[] = [$design, IM_CHANGESETTINGS];
        }

        $hid = $this->ReadPropertyInteger('HueID');
        if (!$this->HueOk($hid)) {
            $this->WriteAttributeString('Watched', json_encode($watch));
            $this->SetStatus(201);
            $this->Push();
            return;
        }
        $this->RegisterReference($hid);
        $vars = $this->Vars();
        $sw = $this->ReadPropertyInteger('SwitchID');
        if ($sw > 0 && IPS_VariableExists($sw)) {
            $vars['switch'] = $sw;
            $this->RegisterReference($sw);
        }
        foreach ($vars as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
            $watch[] = [$vid, VM_UPDATE];
        }
        $this->WriteAttributeString('Watched', json_encode($watch));
        $this->SetStatus(IS_ACTIVE);
        $this->Push();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $this->Push();
    }

    public function GetVisualizationTile(): string
    {
        $initial = '<script>handleMessage(' . json_encode(json_encode($this->BuildState())) . ');</script>';
        return '<style id="w5d">' . $this->DesignCSS() . '</style>' . file_get_contents(__DIR__ . '/module.html') . $initial;
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $hid = $this->ReadPropertyInteger('HueID');
        if (!$this->HueOk($hid)) {
            return;
        }
        $v = $this->Vars();
        switch ($Ident) {
            case 'State':
                $sw = $this->ReadPropertyInteger('SwitchID');
                if ($sw > 0 && IPS_VariableExists($sw)) {
                    RequestAction($sw, (bool) $Value);
                } elseif (isset($v['state'])) {
                    RequestAction($v['state'], (bool) $Value);
                }
                break;
            case 'Brightness':
                if (isset($v['bri'])) {
                    RequestAction($v['bri'], max(1, min(254, (int) $Value)));
                }
                break;
            case 'Kelvin':
                if (isset($v['kelvin'])) {
                    $p = IPS_GetVariableProfile($this->Profile($v['kelvin']) ?: 'HUE.ColorTemperatureKelvin');
                    RequestAction($v['kelvin'], (int) max($p['MinValue'], min($p['MaxValue'], (int) $Value)));
                } elseif (isset($v['ct'])) {
                    RequestAction($v['ct'], (int) max(153, min(500, round(1000000 / max(1, (int) $Value)))));
                }
                $this->SetIfExists($v, 'mode', 1);
                break;
            case 'Color':
                $p = json_decode((string) $Value, true);
                if (!is_array($p)) {
                    break;
                }
                $rgb = $this->HsvToInt((float) ($p['h'] ?? 0), (float) ($p['s'] ?? 1));
                $opt = isset($v['bri']) ? ['bri' => max(1, (int) GetValue($v['bri']))] : [];
                $r = @PHUE_ColorSetOpt($hid, $rgb, $opt);
                $this->SendDebug('Farbe', sprintf('#%06X ', $rgb) . json_encode($r), 0);
                if (is_array($r) && isset($r[0]['success'])) {
                    $this->SetIfExists($v, 'color', $rgb);
                    $this->SetIfExists($v, 'state', true);
                    $this->SetIfExists($v, 'mode', 0);
                }
                break;
            case 'Effect':
                $e = (string) $Value === 'colorloop' ? 'colorloop' : 'none';
                @PHUE_EffectSet($hid, $e);
                $this->WriteAttributeString('Effect', $e);
                break;
            case 'Alert':
                if (in_array((string) $Value, ['select', 'lselect', 'none'], true)) {
                    @PHUE_AlertSet($hid, (string) $Value);
                }
                break;
            case 'Refresh':
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Push();
    }

    /* ---------- Hilfen ---------- */

    private function HueOk(int $id): bool
    {
        return $id > 0 && IPS_InstanceExists($id) && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::HUE_GUID;
    }

    /** Variablen der Hue-Instanz nach Ident (nur vorhandene). */
    private function Vars(): array
    {
        $hid = $this->ReadPropertyInteger('HueID');
        $r = [];
        if (!$this->HueOk($hid)) {
            return $r;
        }
        foreach (self::IDENTS as $k => $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $hid);
            if (is_int($id) && $id > 0) {
                $r[$k] = $id;
            }
        }
        return $r;
    }

    private function Profile(int $vid): string
    {
        $v = IPS_GetVariable($vid);
        return $v['VariableCustomProfile'] !== '' ? $v['VariableCustomProfile'] : $v['VariableProfile'];
    }

    /** Wert einer Hue-Variable nachführen (die Bridge meldet ihn beim nächsten Abgleich ohnehin). */
    private function SetIfExists(array $v, string $key, mixed $value): void
    {
        if (isset($v[$key]) && GetValue($v[$key]) !== $value) {
            SetValue($v[$key], $value);
        }
    }

    private function HsvToInt(float $h, float $s): int
    {
        $h = fmod(fmod($h, 360) + 360, 360) / 60;
        $s = max(0, min(1, $s));
        $i = (int) floor($h);
        $f = $h - $i;
        $p = 1 - $s;
        $q = 1 - $s * $f;
        $t = 1 - $s * (1 - $f);
        [$r, $g, $b] = [[1, $t, $p], [$q, 1, $p], [$p, 1, $t], [$p, $q, 1], [$t, $p, 1], [1, $p, $q]][$i % 6];
        return ((int) round($r * 255) << 16) | ((int) round($g * 255) << 8) | (int) round($b * 255);
    }

    private function DesignID(): int
    {
        if (!IPS_ModuleExists(self::DESIGN_GUID)) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID(self::DESIGN_GUID);
        sort($ids);
        return $ids[0] ?? 0;
    }

    private function DesignCSS(): string
    {
        $id = $this->DesignID();
        return $id > 0 && function_exists('W5D_GetCSS') ? W5D_GetCSS($id) : '';
    }

    private function Push(): void
    {
        $this->UpdateVisualizationValue(json_encode(['design' => $this->DesignCSS()] + $this->BuildState()));
    }

    private function BuildState(): array
    {
        $hid = $this->ReadPropertyInteger('HueID');
        $s = [
            'ok'       => $this->HueOk($hid),
            'name'     => $this->HueOk($hid) ? IPS_GetName($hid) : '',
            'hueId'    => $hid,
            'detailId' => $this->ReadPropertyInteger('DetailID'),
            'effect'   => $this->ReadAttributeString('Effect')
        ];
        if (!$s['ok']) {
            return $s;
        }
        $v = $this->Vars();
        $get = fn (string $k) => isset($v[$k]) ? GetValue($v[$k]) : null;
        $sw = $this->ReadPropertyInteger('SwitchID');
        $s['on'] = (bool) ($sw > 0 && IPS_VariableExists($sw) ? GetValue($sw) : $get('state'));
        $s['reachable'] = $get('reachable') === null ? true : (bool) $get('reachable');
        $s['bri'] = $get('bri');
        $s['color'] = $get('color');
        $s['mode'] = $get('mode');
        $s['kelvin'] = $get('kelvin');
        if ($s['kelvin'] === null && $get('ct')) {
            $s['kelvin'] = (int) round(1000000 / $get('ct'));
        }
        $s['kmin'] = 2000;
        $s['kmax'] = 6500;
        if (isset($v['kelvin'])) {
            $p = @IPS_GetVariableProfile($this->Profile($v['kelvin']));
            if ($p) {
                $s['kmin'] = (int) $p['MinValue'];
                $s['kmax'] = (int) $p['MaxValue'];
            }
        }
        $s['hasColor'] = isset($v['color']);
        $s['hasCt'] = isset($v['kelvin']) || isset($v['ct']);
        $s['hasBri'] = isset($v['bri']);
        return $s;
    }
}
