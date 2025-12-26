<?php

class LowBatMonitor extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Pushover (TUPO)
        $this->RegisterPropertyInteger('PushoverInstance', 0);
        $this->RegisterPropertyString('PushTitle', 'LowBat Monitor');
        $this->RegisterPropertyInteger('PushPriority', 0);

        // Texte
        $this->RegisterPropertyString('AlarmText', 'Niedriger Batteriezustand');
        $this->RegisterPropertyString('OkText', 'Batteriezustand OK');

        // Reminder
        $this->RegisterPropertyBoolean('ReminderEnabled', false);
        $this->RegisterPropertyInteger('ReminderHour', 9); // 0-23

        // Variablenliste
        $this->RegisterPropertyString('Variables', '[]');

        // Buffer
        $this->SetBuffer('States', json_encode([]));     // letzter Zustand pro Variable
        $this->SetBuffer('VarList', json_encode([]));    // registrierte VariableIDs
        $this->SetBuffer('Reminders', json_encode([]));  // varID => "YYYY-MM-DD" (letzte Erinnerung/Alarm am Tag)

        // Timer: alle 15 Minuten prüfen (sendet aber max. 1x/Tag pro Variable)
        $this->RegisterTimer('ReminderTimer', 0, "IPS_RequestAction(\$_IPS['TARGET'], 'ReminderTick', 0);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Alte Registrierungen entfernen
        $old = json_decode($this->GetBuffer('VarList'), true);
        if (!is_array($old)) {
            $old = [];
        }
        foreach ($old as $vid) {
            $vid = (int)$vid;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->UnregisterMessage($vid, VM_UPDATE);
            }
        }

        // Neue Registrierungen setzen (nur aktive)
        $cfg = $this->getConfigVars(); // [varID => ['label'=>..., 'enabled'=>bool]]
        $ids = array_keys($cfg);

        $activeCount = 0;
        foreach ($cfg as $vid => $info) {
            if (!$info['enabled']) {
                continue;
            }
            if (IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                $activeCount++;
            }
        }
        $this->SetBuffer('VarList', json_encode($ids));

        // Zustände initialisieren (damit beim Speichern nichts sofort triggert)
        $states = [];
        foreach ($cfg as $vid => $info) {
            if (!$info['enabled'] || !IPS_VariableExists($vid)) {
                continue;
            }
            $states[(string)$vid] = $this->readBool($vid);
        }
        $this->SetBuffer('States', json_encode($states));

        // Timer aktivieren/deaktivieren
        if ($this->ReadPropertyBoolean('ReminderEnabled')) {
            // alle 15 Minuten
            $this->SetTimerInterval('ReminderTimer', 15 * 60 * 1000);
        } else {
            $this->SetTimerInterval('ReminderTimer', 0);
        }

        $this->SetSummary($activeCount . ' aktiv / ' . count($ids) . ' gesamt');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Test':
                $this->Test();
                break;

            case 'ReminderTick':
                $this->ReminderTick();
                break;

            default:
                throw new Exception("Invalid Ident: " . $Ident);
        }
    }

    public function Test(): void
    {
        $this->sendPushover("Test (OK)");
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        $cfg = $this->getConfigVars();
        if (!isset($cfg[$SenderID])) {
            return;
        }

        $label = $cfg[$SenderID]['label'];
        $enabled = $cfg[$SenderID]['enabled'];

        if (!$enabled) {
            return;
        }

        $states = json_decode($this->GetBuffer('States'), true);
        if (!is_array($states)) {
            $states = [];
        }

        $old = (bool)($states[(string)$SenderID] ?? false);
        $new = $this->readBool((int)$SenderID);

        if ($new === $old) {
            return;
        }

        // Zustand merken
        $states[(string)$SenderID] = $new;
        $this->SetBuffer('States', json_encode($states));

        // Reminder-Tracking aktualisieren (damit am selben Tag nicht zusätzlich erinnert wird)
        $rem = $this->loadReminders();
        $today = date('Y-m-d');

        if ($new) {
            // Alarm (TRUE)
            $msg = $this->formatMessage($label, true, false);
            $this->sendPushover($msg);
            $rem[(string)$SenderID] = $today;
        } else {
            // OK (FALSE) + Reminder-Status zurücksetzen
            $msg = $this->formatMessage($label, false, false);
            $this->sendPushover($msg);
            unset($rem[(string)$SenderID]);
        }

        $this->saveReminders($rem);
    }

    private function ReminderTick(): void
    {
        if (!$this->ReadPropertyBoolean('ReminderEnabled')) {
            return;
        }

        $hour = (int)$this->ReadPropertyInteger('ReminderHour');
        $nowH = (int)date('G');   // 0-23
        $nowM = (int)date('i');

        // Wir senden im 15-Minuten-Fenster nach der Ziel-Stunde (Timer läuft alle 15 Minuten)
        if ($nowH !== $hour) {
            return;
        }
        if ($nowM >= 15) {
            // in dieser Stunde nur beim ersten Tick (Minute 0-14)
            return;
        }

        $cfg = $this->getConfigVars();
        $states = json_decode($this->GetBuffer('States'), true);
        if (!is_array($states)) {
            $states = [];
        }

        $rem = $this->loadReminders();
        $today = date('Y-m-d');

        foreach ($cfg as $vid => $info) {
            if (!$info['enabled'] || !IPS_VariableExists($vid)) {
                continue;
            }

            // aktueller Zustand (TRUE = low bat)
            $cur = $this->readBool($vid);
            $states[(string)$vid] = $cur; // nebenbei aktualisieren

            if (!$cur) {
                // wenn OK, sicherheitshalber Reminder-Flag löschen
                unset($rem[(string)$vid]);
                continue;
            }

            // Heute schon gesendet?
            if (($rem[(string)$vid] ?? '') === $today) {
                continue;
            }

            // Reminder senden
            $msg = $this->formatMessage($info['label'], true, true);
            $this->sendPushover($msg);
            $rem[(string)$vid] = $today;
        }

        $this->SetBuffer('States', json_encode($states));
        $this->saveReminders($rem);
    }

    private function formatMessage(string $label, bool $isLow, bool $isReminder): string
    {
        // NUR: Name + Zustand/Text
        $alarmText = trim($this->ReadPropertyString('AlarmText'));
        if ($alarmText === '') {
            $alarmText = 'Niedriger Batteriezustand';
        }

        $okText = trim($this->ReadPropertyString('OkText'));
        if ($okText === '') {
            $okText = 'Batteriezustand OK';
        }

        if ($isLow) {
            $text = $alarmText;
            $state = 'TRUE';
            if ($isReminder) {
                $text .= ' (Erinnerung)';
            }
        } else {
            $text = $okText;
            $state = 'FALSE';
        }

        return "{$label}: {$text} ({$state})";
    }

    private function getConfigVars(): array
    {
        $list = json_decode($this->ReadPropertyString('Variables'), true);
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            $vid = (int)($row['VariableID'] ?? 0);
            if ($vid <= 0) {
                continue;
            }

            $enabled = (bool)($row['Enabled'] ?? true);
            $label = trim((string)($row['Label'] ?? ''));

            // Fallback: Variablenname
            if ($label === '' && IPS_VariableExists($vid)) {
                $label = IPS_GetName($vid);
            }
            if ($label === '') {
                $label = 'LowBat';
            }

            $out[$vid] = [
                'enabled' => $enabled,
                'label'   => $label
            ];
        }

        return $out;
    }

    private function readBool(int $varID): bool
    {
        $v = GetValue($varID);

        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((float)$v) != 0.0;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes', 'ja'], true);
        }
        return false;
    }

    private function loadReminders(): array
    {
        $rem = json_decode($this->GetBuffer('Reminders'), true);
        return is_array($rem) ? $rem : [];
    }

    private function saveReminders(array $rem): void
    {
        $this->SetBuffer('Reminders', json_encode($rem));
    }

    private function sendPushover(string $message): void
    {
        $inst = $this->ReadPropertyInteger('PushoverInstance');
        if ($inst <= 0 || !IPS_InstanceExists($inst)) {
            $this->LogMessage('Keine gültige Pushover-Instanz gewählt.', KL_WARNING);
            return;
        }

        $title = trim($this->ReadPropertyString('PushTitle'));
        if ($title === '') {
            $title = 'LowBat Monitor';
        }

        $priority = (int)$this->ReadPropertyInteger('PushPriority');

        if (function_exists('TUPO_SendMessage')) {
            @TUPO_SendMessage($inst, $title, $message, $priority);
            return;
        }

        $this->LogMessage('TUPO_SendMessage() nicht gefunden. Prüfe, ob das Pushover-Modul installiert/geladen ist.', KL_ERROR);
    }
}
