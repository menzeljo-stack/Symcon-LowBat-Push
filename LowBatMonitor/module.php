<?php

class LowBatMonitor extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Pushover Instanz-ID
        $this->RegisterPropertyInteger('PushoverInstance', 0);

        // Liste: [{"VariableID":12345,"Label":"Fensterkontakt Küche"}, ...]
        $this->RegisterPropertyString('Variables', '[]');

        // interne Buffers
        $this->SetBuffer('States', json_encode([]));
        $this->SetBuffer('VarList', json_encode([]));
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

        // Neue Liste registrieren
        $cfg = $this->getConfigVars(); // [varID => label]
        $ids = array_keys($cfg);

        foreach ($ids as $vid) {
            if (IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        $this->SetBuffer('VarList', json_encode($ids));

        // Zustände initialisieren (damit beim Speichern nicht sofort "Alarm" rausgeht)
        $states = [];
        foreach ($cfg as $vid => $label) {
            if (!IPS_VariableExists($vid)) {
                continue;
            }
            $states[(string)$vid] = $this->readBool($vid);
        }
        $this->SetBuffer('States', json_encode($states));

        $this->SetSummary(count($ids) . ' Variablen');
    }

    // Button aus der Config
    public function Test(): void
    {
        $this->sendPushover("🧪 Testnachricht vom LowBat Monitor");
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message !== VM_UPDATE) {
            return;
        }

        $cfg = $this->getConfigVars();
        if (!isset($cfg[$SenderID])) {
            return;
        }

        $label = $cfg[$SenderID];

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

        $varName = IPS_GetName($SenderID);
        $path = IPS_GetLocation($SenderID);

        if ($new) {
            $msg = "🔋 Low_Bat = TRUE\nName: {$label}\nVariable: {$varName}\nOrt: {$path}";
        } else {
            $msg = "✅ Low_Bat zurückgesetzt (FALSE)\nName: {$label}\nVariable: {$varName}\nOrt: {$path}";
        }

        $this->sendPushover($msg);
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

            $label = trim((string)($row['Label'] ?? ''));

            // Fallback: Variablenname, falls leer
            if ($label === '' && IPS_VariableExists($vid)) {
                $label = IPS_GetName($vid);
            }
            if ($label === '') {
                $label = 'LowBat';
            }

            $out[$vid] = $label;
        }

        return $out;
    }

    private function readBool(int $varID): bool
    {
        // Viele Low_Bat sind bool; manche sind 0/1 -> wir behandeln beides sinnvoll
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

private function sendPushover(string $message): void
{
    $inst = $this->ReadPropertyInteger('PushoverInstance');
    if ($inst <= 0 || !IPS_InstanceExists($inst)) {
        $this->LogMessage('Keine gültige Pushover-Instanz gewählt.', KL_WARNING);
        return;
    }

    // Optional: Titel/Priorität aus Properties (siehe Punkt 2)
    $title = $this->ReadPropertyString('PushTitle');
    if ($title === '') {
        $title = 'LowBat Monitor';
    }
    $priority = $this->ReadPropertyInteger('PushPriority'); // 0 = normal

    if (function_exists('TUPO_SendMessage')) {
        @TUPO_SendMessage($inst, $title, $message, $priority);
        return;
    }

    $this->LogMessage('TUPO_SendMessage() nicht gefunden. Prüfe, ob das Pushover-Modul geladen ist.', KL_ERROR);
}
