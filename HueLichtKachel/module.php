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
        // Szenen (ab 0.2): eigener Zugang zur Bridge (CLIP v1), Szenen-Zwischenspeicher, zuletzt gewählte Szene
        $this->RegisterPropertyInteger('SceneScope', 0);      // 0 = nur diese Lampe, 1 = ganze Szene (Raum)
        $this->RegisterAttributeString('ApiUser', '');
        $this->RegisterAttributeString('Scenes', '[]');
        $this->RegisterAttributeString('SceneSel', '');
        $this->RegisterAttributeString('SceneMsg', '');
        $this->RegisterTimer('SceneRefresh', 0, 'HLK_ReloadScenes($_IPS[\'TARGET\']);');
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
        if ($this->ReadAttributeString('ApiUser') === '' && IPS_GetKernelRunlevel() === KR_READY) {
            // Zugang von einer schon gekoppelten Instanz an derselben Bridge übernehmen (sonst je Instanz Link-Taste)
            foreach ($this->Siblings() as $sib) {
                if (@HLK_ShareApiUser($sib, $this->InstanceID)) {
                    break;
                }
            }
        }
        $paired = $this->ReadAttributeString('ApiUser') !== '';
        $this->SetTimerInterval('SceneRefresh', $paired ? 15 * 60 * 1000 : 0);
        if ($paired && IPS_GetKernelRunlevel() === KR_READY) {
            $this->ReloadScenes();
        }
        $this->Push();
    }

    /* ---------- Szenen der Hue-Bridge (CLIP v1) ---------- */

    /**
     * Eigenen Zugang an der Bridge anlegen. Vorher die Link-Taste der Bridge drücken (30 s Zeit).
     * Der Zugang des Symcon-Hue-Moduls ist nicht lesbar und das Modul kann Szenen weder im Detail lesen noch speichern.
     */
    public function Pair(): string
    {
        $r = $this->Api('POST', '', ['devicetype' => 'symcon#huelichtkachel'], true);
        $user = (string) ($r[0]['success']['username'] ?? '');
        if ($user === '') {
            $err = (string) ($r[0]['error']['description'] ?? 'Bridge nicht erreichbar');
            return 'Nicht verbunden: ' . $err . ' (Link-Taste der Bridge drücken und innerhalb von 30 s erneut versuchen)';
        }
        $this->AdoptApiUser($user);
        $k = 0;
        foreach ($this->Siblings() as $sib) {
            $k += $this->ShareApiUser($sib) ? 1 : 0;
        }
        $n = count((array) json_decode($this->ReadAttributeString('Scenes'), true));
        return 'Verbunden, ' . $n . ' Szenen gefunden.' . ($k > 0 ? ' Zugang an ' . $k . ' weitere Kachel(n) weitergegeben.' : '');
    }

    /** Eigenen Bridge-Zugang an eine andere Hue Licht Kachel an derselben Bridge weitergeben (der Zugang wird nicht ausgegeben). */
    public function ShareApiUser(int $Target): bool
    {
        $user = $this->ReadAttributeString('ApiUser');
        if ($user === '' || $Target === $this->InstanceID || !in_array($Target, $this->Siblings(), true)) {
            return false;
        }
        HLK_AdoptApiUser($Target, $user);
        return true;
    }

    /** Bridge-Zugang übernehmen (von Pair oder einer anderen Kachel). */
    public function AdoptApiUser(string $User): void
    {
        if ($User === '') {
            return;
        }
        $this->WriteAttributeString('ApiUser', $User);
        $this->SetTimerInterval('SceneRefresh', 15 * 60 * 1000);
        $this->ReloadScenes();
        $this->Push();
    }

    /** Andere Instanzen dieses Moduls, deren Hue-Instanz an derselben Bridge hängt. */
    private function Siblings(): array
    {
        $bridge = $this->BridgeID();
        if ($bridge <= 0) {
            return [];
        }
        $r = [];
        foreach (IPS_GetInstanceListByModuleID(IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID']) as $i) {
            $h = (int) IPS_GetProperty($i, 'HueID');
            if ($i !== $this->InstanceID && $h > 0 && IPS_InstanceExists($h) && IPS_GetInstance($h)['ConnectionID'] === $bridge) {
                $r[] = $i;
            }
        }
        return $r;
    }

    private function BridgeID(): int
    {
        $hid = $this->ReadPropertyInteger('HueID');
        return $this->HueOk($hid) ? IPS_GetInstance($hid)['ConnectionID'] : 0;
    }

    /** Szenen mit dieser Lampe (bzw. dieser Gruppe) samt Lichtzustand der Lampe neu lesen; liefert die Anzahl. */
    public function ReloadScenes(): int
    {
        $all = $this->Api('GET', 'scenes');
        if (!is_array($all) || isset($all[0]['error'])) {
            return -1;
        }
        [$type, $dev] = $this->HueDevice();
        $list = [];
        foreach ($all as $id => $sc) {
            if (($sc['type'] ?? '') !== 'GroupScene') {
                continue;   // nur Raum-/Zonen-Szenen, wie in der Hue-App
            }
            $mine = $type === 'groups' ? (string) ($sc['group'] ?? '') === $dev : in_array($dev, (array) ($sc['lights'] ?? []), true);
            if (!$mine) {
                continue;
            }
            $d = $this->Api('GET', 'scenes/' . $id);
            $ls = (array) ($d['lightstates'] ?? []);
            $st = $type === 'groups' ? (reset($ls) ?: []) : ($ls[$dev] ?? []);
            $list[] = ['id' => (string) $id, 'name' => (string) $sc['name'], 'group' => (string) ($sc['group'] ?? ''),
                'state' => $st, 'updated' => (string) ($sc['lastupdated'] ?? '')];
        }
        usort($list, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $this->WriteAttributeString('Scenes', json_encode($list));
        return count($list);
    }

    private function SceneById(string $id): ?array
    {
        foreach ((array) json_decode($this->ReadAttributeString('Scenes'), true) as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    /** Szene abrufen: nur diese Lampe (Lichtzustand aus der Szene) oder die ganze Szene über die Gruppe. */
    private function SceneRecall(string $id): void
    {
        $sc = $this->SceneById($id);
        if ($sc === null) {
            return;
        }
        [$type, $dev] = $this->HueDevice();
        if ($type === 'groups' || $this->ReadPropertyInteger('SceneScope') === 1) {
            $grp = $type === 'groups' ? $dev : $sc['group'];
            $r = $this->Api('PUT', 'groups/' . $grp . '/action', ['scene' => $id]);
        } else {
            $st = array_intersect_key((array) $sc['state'], array_flip(['on', 'bri', 'xy', 'ct', 'hue', 'sat', 'effect', 'transitiontime']));
            $r = $this->Api('PUT', 'lights/' . $dev . '/state', $st + ['transitiontime' => 4]);
        }
        $this->SendDebug('Szene abrufen', $sc['name'] . ' ' . json_encode($r), 0);
        $this->WriteAttributeString('SceneSel', $id);
        $this->WriteAttributeString('SceneMsg', '');
        $this->RefreshHue();
    }

    /** Aktuellen Zustand der Lampe in die Szene schreiben (nur diese Lampe, die übrigen Lampen der Szene bleiben). */
    private function SceneSave(string $id): void
    {
        $sc = $this->SceneById($id);
        if ($sc === null) {
            return;
        }
        [$type, $dev] = $this->HueDevice();
        if ($type === 'groups') {
            $r = $this->Api('PUT', 'scenes/' . $id, ['storelightstate' => true]);   // Gruppe: alle Lampen übernehmen
        } else {
            $r = $this->Api('PUT', 'scenes/' . $id . '/lightstates/' . $dev, $this->CurrentLightState($dev));
        }
        $ok = is_array($r) && isset($r[0]['success']);
        $this->WriteAttributeString('SceneMsg', $ok ? '„' . $sc['name'] . '“ gespeichert' : 'Speichern fehlgeschlagen: ' . (string) ($r[0]['error']['description'] ?? 'keine Antwort'));
        $this->LogMessage('Hue-Szene ' . $sc['name'] . ' (' . $id . ') aus der Kachel gespeichert: ' . json_encode($r), KL_NOTIFY);
        $this->WriteAttributeString('SceneSel', $id);
        $this->ReloadScenes();
    }

    /** Neue Szene im Raum der Lampe anlegen; sie übernimmt den aktuellen Zustand aller Lampen des Raums (wie „Szene speichern“ in der App). */
    private function SceneNew(string $name): void
    {
        $name = trim(mb_strcut(trim($name), 0, 32, 'UTF-8'));   // Hue: höchstens 32 Byte (Umlaute zählen doppelt)
        if ($name === '') {
            return;
        }
        [$type, $dev] = $this->HueDevice();
        $grp = $type === 'groups' ? $dev : $this->RoomOf($dev);
        if ($grp === '') {
            $this->WriteAttributeString('SceneMsg', 'Kein Raum für die Lampe gefunden');
            return;
        }
        $r = $this->Api('POST', 'scenes', ['name' => $name, 'type' => 'GroupScene', 'group' => $grp, 'recycle' => false]);
        $id = (string) ($r[0]['success']['id'] ?? '');
        if ($id === '') {
            $this->WriteAttributeString('SceneMsg', 'Anlegen fehlgeschlagen: ' . (string) ($r[0]['error']['description'] ?? 'keine Antwort'));
            return;
        }
        $this->Api('PUT', 'scenes/' . $id, ['storelightstate' => true]);
        $this->LogMessage('Hue-Szene „' . $name . '“ (' . $id . ') in Gruppe ' . $grp . ' aus der Kachel angelegt', KL_NOTIFY);
        $this->WriteAttributeString('SceneSel', $id);
        $this->WriteAttributeString('SceneMsg', '„' . $name . '“ angelegt');
        $this->ReloadScenes();
    }

    private function CurrentLightState(string $dev): array
    {
        $s = (array) ($this->Api('GET', 'lights/' . $dev)['state'] ?? []);
        $r = ['on' => (bool) ($s['on'] ?? false)];
        if (isset($s['bri'])) {
            $r['bri'] = (int) $s['bri'];
        }
        $mode = $s['colormode'] ?? '';
        if ($mode === 'ct' && isset($s['ct'])) {
            $r['ct'] = (int) $s['ct'];
        } elseif (isset($s['xy'])) {
            $r['xy'] = $s['xy'];
        } elseif (isset($s['ct'])) {
            $r['ct'] = (int) $s['ct'];
        }
        return $r;
    }

    /** Raum (Gruppe vom Typ Room) mit dieser Lampe. */
    private function RoomOf(string $dev): string
    {
        foreach ((array) $this->Api('GET', 'groups') as $gid => $g) {
            if (($g['type'] ?? '') === 'Room' && in_array($dev, (array) ($g['lights'] ?? []), true)) {
                return (string) $gid;
            }
        }
        return '';
    }

    /** [DeviceType, HUEDeviceID] der Hue-Instanz */
    private function HueDevice(): array
    {
        $hid = $this->ReadPropertyInteger('HueID');
        if (!$this->HueOk($hid)) {
            return ['', ''];
        }
        $t = (string) IPS_GetProperty($hid, 'DeviceType');
        return [$t === 'groups' ? 'groups' : 'lights', (string) IPS_GetProperty($hid, 'HUEDeviceID')];
    }

    /** Symcon-Variablen der Lampe nach einem Szenenabruf sofort nachziehen. */
    private function RefreshHue(): void
    {
        [$type, $dev] = $this->HueDevice();
        $s = (array) ($this->Api('GET', $type . '/' . $dev)[$type === 'groups' ? 'action' : 'state'] ?? []);
        if ($s === []) {
            return;
        }
        $v = $this->Vars();
        $this->SetIfExists($v, 'state', (bool) ($s['on'] ?? false));
        if (isset($s['bri'])) {
            $this->SetIfExists($v, 'bri', (int) $s['bri']);
        }
        if (($s['colormode'] ?? '') === 'ct' && !empty($s['ct'])) {
            $this->SetIfExists($v, 'mode', 1);
            $this->SetIfExists($v, 'ct', (int) $s['ct']);
            $this->SetIfExists($v, 'kelvin', (int) round(1000000 / $s['ct']));
        } elseif (isset($s['xy'])) {
            $this->SetIfExists($v, 'mode', 0);
            $this->SetIfExists($v, 'color', $this->XyToInt((float) $s['xy'][0], (float) $s['xy'][1]));
        }
    }

    /** CIE xy → RGB (Gamut ignoriert, volle Helligkeit), für die Anzeige der Farbe. */
    private function XyToInt(float $x, float $y): int
    {
        $y = max($y, 0.0001);
        $X = $x / $y;
        $Z = (1 - $x - $y) / $y;
        $rgb = [$X * 1.656492 - 0.354851 - $Z * 0.255038, -$X * 0.707196 + 1.655397 + $Z * 0.036152, $X * 0.051713 - 0.121364 + $Z * 1.011530];
        $m = max(max($rgb), 0.0001);
        $out = 0;
        foreach ($rgb as $c) {
            $c = max(0, $c / $m);
            $c = $c <= 0.0031308 ? 12.92 * $c : 1.055 * pow($c, 1 / 2.4) - 0.055;
            $out = ($out << 8) | (int) round(min(1, $c) * 255);
        }
        return $out;
    }

    /** Aufruf der Hue-API (v1). $noUser: ohne Benutzer (Anmeldung). */
    private function Api(string $method, string $path, array $body = [], bool $noUser = false): mixed
    {
        $bridge = $this->BridgeID();
        $host = $bridge > 0 ? (string) @IPS_GetProperty($bridge, 'Host') : '';
        $user = $this->ReadAttributeString('ApiUser');
        if ($host === '' || (!$noUser && $user === '')) {
            return null;
        }
        if (!preg_match('~^https?://~', $host)) {
            $host = 'http://' . $host;
        }
        $url = rtrim($host, '/') . '/api' . ($noUser ? '' : '/' . $user . ($path !== '' ? '/' . $path : ''));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
        if ($method !== 'GET') {
            // Umlaute unmaskiert senden: die Bridge (v1) übernimmt ü sonst wörtlich in den Namen
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $res = curl_exec($ch);
        curl_close($ch);
        $this->SendDebug('API ' . $method . ' ' . $path, is_string($res) ? substr($res, 0, 400) : 'keine Antwort', 0);
        return is_string($res) ? json_decode($res, true) : null;
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
            case 'Scene':
                $this->SceneRecall((string) $Value);
                break;
            case 'SceneSave':
                $this->SceneSave((string) $Value);
                break;
            case 'SceneNew':
                $this->SceneNew((string) $Value);
                break;
            case 'ScenesReload':
                $this->ReloadScenes();
                break;
            case 'Refresh':
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Push();
    }

    /* ---------- Hilfen ---------- */

    /** Zurück-Ziel des Fensters: Seite, in der die Einstellungskategorie liegt (wie bei der Heizung Kachel). */
    private function BackID(): int
    {
        $d = $this->ReadPropertyInteger('DetailID');
        if ($d <= 0 || !IPS_ObjectExists($d)) {
            return 0;
        }
        $up = IPS_GetParent($d);
        return ($up > 0 && IPS_GetObject($up)['ObjectType'] === 0) ? $up : 0;
    }

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
            'backId'   => $this->BackID(),
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
        // Szenen: Liste, gewählte Szene, Meldung; aktiv = Lampe entspricht dem gespeicherten Zustand
        $s['paired'] = $this->ReadAttributeString('ApiUser') !== '';
        $s['sceneSel'] = $this->ReadAttributeString('SceneSel');
        $s['sceneMsg'] = $this->ReadAttributeString('SceneMsg');
        $s['scenes'] = [];
        foreach ((array) json_decode($this->ReadAttributeString('Scenes'), true) as $sc) {
            $st = (array) $sc['state'];
            $s['scenes'][] = ['id' => $sc['id'], 'name' => $sc['name'], 'active' => $this->Matches($st, $s, $v),
                'color' => isset($st['xy']) ? $this->XyToInt((float) $st['xy'][0], (float) $st['xy'][1]) : null,
                'kelvin' => isset($st['ct']) && $st['ct'] > 0 ? (int) round(1000000 / $st['ct']) : null, 'on' => (bool) ($st['on'] ?? false)];
        }
        return $s;
    }

    private function Matches(array $st, array $s, array $v): bool
    {
        if ($st === [] || (bool) ($st['on'] ?? false) !== $s['on']) {
            return false;
        }
        if (!$s['on']) {
            return true;
        }
        if (isset($st['bri']) && $s['bri'] !== null && abs((int) $st['bri'] - (int) $s['bri']) > 3) {
            return false;
        }
        if (isset($st['ct'])) {
            return $s['mode'] !== 0 && isset($v['ct']) && abs((int) GetValue($v['ct']) - (int) $st['ct']) <= 4;
        }
        if (isset($st['xy'])) {
            if ($s['mode'] !== 0 || $s['color'] === null) {
                return false;
            }
            $a = $this->XyToInt((float) $st['xy'][0], (float) $st['xy'][1]);
            $d = 0;
            for ($k = 0; $k < 24; $k += 8) {
                $d += abs((($a >> $k) & 255) - (($s['color'] >> $k) & 255));
            }
            return $d < 60;
        }
        return true;
    }
}
