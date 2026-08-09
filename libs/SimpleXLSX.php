<?php
// © Cadurix v5.4 — Tous droits réservés. Utilisation non autorisée interdite.

/**
 * SimpleXLSX — Lecteur XLSX léger sans dépendance
 * Version allégée pour Cadurix
 * Compatible PHP 7.4+
 */
class SimpleXLSX {
    private $sheets = [];
    private static $error = '';

    private function __construct() {}

    public static function parse(string $filename): ?self {
        self::$error = '';
        $obj = new self();
        if (!$obj->load($filename)) return null;
        return $obj;
    }

    public static function parseError(): string {
        return self::$error;
    }

    private function load(string $filename): bool {
        if (!file_exists($filename)) {
            self::$error = 'Fichier introuvable';
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            self::$error = 'Impossible d\'ouvrir le fichier ZIP (xlsx)';
            return false;
        }

        // Lire les chaînes partagées
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = simplexml_load_string($ssXml);
            if ($ss) {
                foreach ($ss->si as $si) {
                    $val = '';
                    if (isset($si->t)) {
                        $val = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            if (isset($r->t)) $val .= (string)$r->t;
                        }
                    }
                    $sharedStrings[] = $val;
                }
            }
        }

        // Lire styles pour détecter les dates
        $dateFormats = [];
        $stylesXml = $zip->getFromName('xl/styles.xml');
        if ($stylesXml) {
            $styles = simplexml_load_string($stylesXml);
            if ($styles && isset($styles->cellXfs)) {
                $builtinDateFormats = [14,15,16,17,18,19,20,21,22,45,46,47];
                foreach ($styles->cellXfs->xf as $xf) {
                    $numFmtId = (int)($xf['numFmtId'] ?? 0);
                    $dateFormats[] = in_array($numFmtId, $builtinDateFormats) || ($numFmtId >= 164);
                }
            }
        }

        // Lire workbook pour lister les feuilles
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $sheetNames = [];
        if ($wbXml) {
            $wb = simplexml_load_string($wbXml);
            if ($wb && isset($wb->sheets)) {
                foreach ($wb->sheets->sheet as $sheet) {
                    $sheetNames[] = (string)$sheet['name'];
                }
            }
        }

        // Lire chaque feuille
        $i = 1;
        while (true) {
            $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
            if ($sheetXml === false) break;

            $rows = $this->parseSheet($sheetXml, $sharedStrings, $dateFormats);
            $this->sheets[] = $rows;
            $i++;
        }

        $zip->close();

        if (empty($this->sheets)) {
            self::$error = 'Aucune feuille trouvée dans le fichier';
            return false;
        }

        return true;
    }

    private function parseSheet(string $xml, array $ss, array $dateFormats): array {
        $rows = [];
        $sheet = simplexml_load_string($xml);
        if (!$sheet || !isset($sheet->sheetData)) return [];

        foreach ($sheet->sheetData->row as $row) {
            $rowData = [];
            $maxCol = 0;

            foreach ($row->c as $cell) {
                $ref = (string)($cell['r'] ?? '');
                $col = $this->colIndex($ref);
                $type = (string)($cell['t'] ?? '');
                $s = (int)($cell['s'] ?? -1);
                $v = isset($cell->v) ? (string)$cell->v : '';
                $f = isset($cell->f) ? (string)$cell->f : null;

                $value = '';

                if ($type === 's') {
                    // Shared string
                    $value = $ss[(int)$v] ?? '';
                } elseif ($type === 'str' || $type === 'inlineStr') {
                    $value = isset($cell->is->t) ? (string)$cell->is->t : $v;
                } elseif ($type === 'b') {
                    $value = $v ? 'TRUE' : 'FALSE';
                } elseif ($v !== '') {
                    // Numérique — vérifier si c'est une date
                    if ($s >= 0 && isset($dateFormats[$s]) && $dateFormats[$s]) {
                        // Convertir numéro de série Excel en date
                        $ts = ((float)$v - 25569) * 86400;
                        $value = date('d/m/Y', (int)$ts);
                    } else {
                        $value = $v;
                    }
                }

                if ($col > $maxCol) $maxCol = $col;
                $rowData[$col] = $value;
            }

            // Remplir les colonnes manquantes
            $fullRow = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $fullRow[] = $rowData[$c] ?? '';
            }

            if (!empty(array_filter($fullRow, fn($v) => $v !== ''))) {
                $rows[] = $fullRow;
            }
        }

        return $rows;
    }

    private function colIndex(string $ref): int {
        // Extraire la partie lettres de la référence (ex: "A1" → "A", "BC12" → "BC")
        preg_match('/^([A-Z]+)/i', $ref, $m);
        $letters = strtoupper($m[1] ?? 'A');
        $col = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $col - 1;
    }

    /**
     * Retourne les lignes d'une feuille (index 0-based)
     */
    public function rows(int $sheetIndex = 0): array {
        return $this->sheets[$sheetIndex] ?? [];
    }

    /**
     * Nombre de feuilles
     */
    public function sheetsCount(): int {
        return count($this->sheets);
    }
}
