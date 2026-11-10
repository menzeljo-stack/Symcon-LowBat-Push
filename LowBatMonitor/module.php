<?php

declare(strict_types=1);

/**
 * Störungsmelder (vormals LowBat Monitor) v2.0
 *
 * Meldet per Pushover sofort, wenn
 *  - ein Batteriezustand schwach wird (HomeMatic LOWBAT/LOW_BAT automatisch,
 *    Batterie-%-Variablen anderer Systeme automatisch, plus eigene Liste),
 *  - ein HomeMatic-Gerät nicht erreichbar ist (UNREACH),
 *  - eine Instanz in einen Fehlerstatus geht (Status >= 200),
 * und wenn die Störung wieder behoben ist. Optional tägliche Sammel-Erinnerung.
 * Zustände werden als Attribut gespeichert und überstehen Neustarts.
 */
class LowBatMonitor extends IPSModule
{
    private const HM_DEVICE = '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}';

    public function Create()
    {
        parent::Create();

        // Pushover
        $this->RegisterPropertyInteger('PushoverInstance', 0);
        $this->RegisterPropertyString('PushTitle', 'Störungsmelder');
        $this->RegisterPropertyInteger('PushPriority', 0);

        // Texte (leer = Standard)
        $this->RegisterPropertyString('AlarmText', '');
        $this->RegisterPropertyString('OkText', '');

        // Erinnerung
        $this->RegisterPropertyBoolean('ReminderEnabled', true);
        $this->RegisterPropertyInteger('ReminderHour', 15);

        // Quellen
        $this->RegisterPropertyBoolean('AutoHomeMaticBattery', true);
        $this->RegisterPropertyBoolean('AutoBatteryPercent', true);
        $this->RegisterPropertyInteger('BatteryThreshold', 20);
        $this->RegisterPropertyBoolean('MonitorUnreach', true);
        $this->RegisterPropertyBoolean('MonitorInstances', true);
        $this->RegisterPropertyInteger('ErrorDelay', 10);   // Minuten bis Meldung (Erreichbarkeit/Instanzen)
        $this->RegisterPropertyString('Variables', '[]');   // eigene Variablen
        $this->RegisterPropertyString('Excludes', '[]');    // ignorierte Objekte (Instanz/Variable/Kategorie)

        // Persistenter Zustand: key => [since, alarmed, text, kind]
        $this->RegisterAttributeString('Problems', '{}');
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterAttributeString('LastReminder', '');

        $this->RegisterTimer('CheckTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "Check", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableInteger('Count', 'Störungen', '', 1);
        $this->RegisterVariableString('List', 'Störungsliste', '~HTMLBox', 2);

        $this->SetTimerInterval('CheckTimer', 60 * 1000);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Check();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->Check();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Check':
                $this->Check();
                break;
            case 'Test':
                $this->sendPushover('Testnachricht – Störungsmelder ist aktiv.');
                break;
            case 'Reminder':
                $this->sendReminder(true);
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    /** Hauptprüfung, läuft jede Minute */
    public function Check(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $current = $this->collectProblems();           // key => [text, kind]
        $known   = json_decode($this->ReadAttributeString('Problems'), true) ?: [];
        $first   = !$this->ReadAttributeBoolean('Initialized');
        $delay   = max(0, $this->ReadPropertyInteger('ErrorDelay')) * 60;
        $now     = time();

        $alarms = [];
        $oks    = [];

        // neue / bestehende Störungen
        foreach ($current as $key => $p) {
            if (!isset($known[$key])) {
                // Beim ersten Start bestehende Störungen still übernehmen
                $known[$key] = ['since' => $now, 'alarmed' => $first, 'text' => $p['text'], 'kind' => $p['kind']];
            }
            $known[$key]['text'] = $p['text'];
            $wait = ($p['kind'] === 'bat') ? 0 : $delay;
            if (!$known[$key]['alarmed'] && ($now - $known[$key]['since']) >= $wait) {
                $known[$key]['alarmed'] = true;
                $alarms[] = $p['text'];
            }
        }

        // behobene Störungen
        foreach ($known as $key => $k) {
            if (!isset($current[$key])) {
                if ($k['alarmed'] && !$first) {
                    $oks[] = $k['text'];
                }
                unset($known[$key]);
            }
        }

        $this->WriteAttributeString('Problems', json_encode($known));
        if ($first) {
            $this->WriteAttributeBoolean('Initialized', true);
        }

        if ($alarms) {
            $this->sendPushover($this->alarmPrefix() . "\n" . implode("\n", $alarms));
        }
        if ($oks) {
            $this->sendPushover($this->okPrefix() . "\n" . implode("\n", $oks));
        }

        $this->updateVariables($known);
        $this->sendReminder(false);
        $this->SetSummary(count($known) . ' Störung(en)');
    }

    /** Ermittelt alle aktuell anliegenden Störungen */
    private function collectProblems(): array
    {
        $out = [];
        $excl = $this->excludedIDs();
        $thr = $this->ReadPropertyInteger('BatteryThreshold');

        // 1) HomeMatic automatisch: Batterie + Erreichbarkeit
        $autoBat = $this->ReadPropertyBoolean('AutoHomeMaticBattery');
        $unreach = $this->ReadPropertyBoolean('MonitorUnreach');
        if ($autoBat || $unreach) {
            foreach (IPS_GetInstanceListByModuleID(self::HM_DEVICE) as $inst) {
                if ($this->isExcluded($inst, $excl)) {
                    continue;
                }
                foreach (IPS_GetChildrenIDs($inst) as $v) {
                    if (!IPS_VariableExists($v) || isset($excl[$v])) {
                        continue;
                    }
                    $ident = IPS_GetObject($v)['ObjectIdent'];
                    if ($autoBat && ($ident === 'LOWBAT' || $ident === 'LOW_BAT') && $this->isTrue($v)) {
                        $out['bat:' . $v] = ['kind' => 'bat', 'text' => '🔋 ' . $this->deviceName($inst)];
                    }
                    if ($unreach && $ident === 'UNREACH' && $this->isTrue($v)) {
                        $out['unr:' . $inst] = ['kind' => 'unr', 'text' => '📡 nicht erreichbar: ' . $this->deviceName($inst)];
                    }
                }
            }
        }

        // 2) Batterie-Prozent anderer Systeme (Zigbee2MQTT, SwitchBot, Home Assistant …)
        if ($this->ReadPropertyBoolean('AutoBatteryPercent') && $thr > 0) {
            foreach (IPS_GetVariableList() as $v) {
                $o = IPS_GetObject($v);
                if (!preg_match('/batter/i', $o['ObjectIdent'] . ' ' . $o['ObjectName'])) {
                    continue;
                }
                $var = IPS_GetVariable($v);
                if (!in_array($var['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                    continue;
                }
                $parent = $o['ParentID'];
                if (IPS_InstanceExists($parent) && IPS_GetInstance($parent)['ModuleInfo']['ModuleID'] === self::HM_DEVICE) {
                    continue; // HomeMatic läuft über LOWBAT
                }
                if ($this->isExcluded($v, $excl)) {
                    continue;
                }
                $val = (float) GetValue($v);
                if ($var['VariableUpdated'] > 0 && $val > 0 && $val < $thr) {
                    $name = IPS_InstanceExists($parent) ? $this->deviceName($parent) : $this->shortPath($v);
                    $out['pct:' . $v] = ['kind' => 'bat', 'text' => '🔋 ' . $name . ' (' . round($val) . ' %)'];
                }
            }
        }

        // 3) Eigene Variablen
        foreach (json_decode($this->ReadPropertyString('Variables'), true) ?: [] as $row) {
            $v = (int) ($row['VariableID'] ?? 0);
            if (!($row['Enabled'] ?? true) || $v <= 0 || !IPS_VariableExists($v) || isset($excl[$v])) {
                continue;
            }
            $label = trim((string) ($row['Label'] ?? '')) ?: $this->shortPath($v);
            $t = (int) ($row['Threshold'] ?? 0);
            $low = $t > 0 ? ((float) GetValue($v) < $t) : $this->isTrue($v);
            if ($low) {
                $out['bat:' . $v] = ['kind' => 'bat', 'text' => '🔋 ' . $label];
            }
        }

        // 4) Instanzen im Fehlerstatus
        if ($this->ReadPropertyBoolean('MonitorInstances')) {
            foreach (IPS_GetInstanceList() as $inst) {
                if ($inst === $this->InstanceID || $this->isExcluded($inst, $excl)) {
                    continue;
                }
                $st = IPS_GetInstance($inst)['InstanceStatus'];
                if ($st >= 200) {
                    $out['inst:' . $inst] = ['kind' => 'inst', 'text' => '⚠️ Fehler (' . $st . '): ' . $this->shortPath($inst)];
                }
            }
        }

        return $out;
    }

    private function updateVariables(array $known): void
    {
        $alarmed = array_filter($known, fn ($k) => $k['alarmed']);
        $this->SetValue('Count', count($alarmed));
        if (!$alarmed) {
            $html = '<div style="padding:4px">✅ Keine Störungen</div>';
        } else {
            uasort($alarmed, fn ($a, $b) => $a['since'] <=> $b['since']);
            $rows = '';
            foreach ($alarmed as $k) {
                $rows .= '<tr><td style="padding:2px 8px 2px 0">' . htmlspecialchars($k['text']) . '</td><td style="opacity:.6;white-space:nowrap">seit ' . date('d.m. H:i', $k['since']) . '</td></tr>';
            }
            $html = '<table style="width:100%">' . $rows . '</table>';
        }
        if ($this->GetValue('List') !== $html) {
            $this->SetValue('List', $html);
        }
    }

    private function sendReminder(bool $force): void
    {
        if (!$force) {
            if (!$this->ReadPropertyBoolean('ReminderEnabled')) {
                return;
            }
            if ((int) date('G') !== $this->ReadPropertyInteger('ReminderHour')) {
                return;
            }
            if ($this->ReadAttributeString('LastReminder') === date('Y-m-d')) {
                return;
            }
            $this->WriteAttributeString('LastReminder', date('Y-m-d'));
        }
        $known = json_decode($this->ReadAttributeString('Problems'), true) ?: [];
        $list = array_column(array_filter($known, fn ($k) => $k['alarmed']), 'text');
        if ($list) {
            $this->sendPushover('🔔 Offene Störungen (' . count($list) . "):\n" . implode("\n", $list));
        } elseif ($force) {
            $this->sendPushover('✅ Keine offenen Störungen.');
        }
    }

    // ---------- Hilfsfunktionen ----------

    private function alarmPrefix(): string
    {
        return trim($this->ReadPropertyString('AlarmText')) ?: 'Neue Störung:';
    }

    private function okPrefix(): string
    {
        return trim($this->ReadPropertyString('OkText')) ?: '✅ Behoben:';
    }

    private function isTrue(int $v): bool
    {
        $x = GetValue($v);
        if (is_string($x)) {
            return in_array(strtolower(trim($x)), ['1', 'true', 'on', 'yes', 'ja'], true);
        }
        return (bool) $x;
    }

    /** Lesbarer Gerätename: bei HomeMatic-Kanälen ":0" o. ä. weglassen, Elternobjekt mit angeben */
    private function deviceName(int $inst): string
    {
        $name = preg_replace('/[:#]\d*$/', '', IPS_GetName($inst));
        $parent = IPS_GetParent($inst);
        $pName = $parent > 0 ? IPS_GetName($parent) : '';
        if (in_array(strtoupper($name), ['MAINTENANCE', 'WARTUNG', ''], true) || preg_match('/^(Kanal|Channel)\s*\d*$/i', $name)) {
            return $pName;
        }
        if ($pName !== '' && stripos($name, $pName) === false && stripos($pName, $name) === false && strlen($pName) < 40) {
            return $pName . ' – ' . $name;
        }
        return $name;
    }

    private function shortPath(int $id): string
    {
        $parts = explode('\\', IPS_GetLocation($id));
        return implode(' › ', array_slice($parts, -2));
    }

    private function excludedIDs(): array
    {
        $ids = [];
        foreach (json_decode($this->ReadPropertyString('Excludes'), true) ?: [] as $row) {
            $id = (int) ($row['ObjectID'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return $ids;
    }

    /** Objekt oder einer seiner Elternknoten ist ausgeschlossen */
    private function isExcluded(int $id, array $excl): bool
    {
        while ($id > 0) {
            if (isset($excl[$id])) {
                return true;
            }
            $id = IPS_GetParent($id);
        }
        return false;
    }

    private function sendPushover(string $message): void
    {
        $inst = $this->ReadPropertyInteger('PushoverInstance');
        if ($inst <= 0 || !IPS_InstanceExists($inst)) {
            $this->LogMessage('Keine gültige Pushover-Instanz gewählt. Meldung: ' . $message, KL_WARNING);
            return;
        }
        $title = trim($this->ReadPropertyString('PushTitle')) ?: 'Störungsmelder';
        if (function_exists('TUPO_SendMessage')) {
            @TUPO_SendMessage($inst, $title, $message, $this->ReadPropertyInteger('PushPriority'));
            return;
        }
        $this->LogMessage('TUPO_SendMessage() nicht gefunden.', KL_ERROR);
    }
}