<?php

$html = file_get_contents('/tmp/fnwl_sapon.html');

$jsonPath = '/Users/philippe/Herd/koskalk/database/seeders/data/mendrulandia_oils.json';
$oils = json_decode(file_get_contents($jsonPath), true);

$dom = new DOMDocument;
@$dom->loadHTML($html);

$tables = $dom->getElementsByTagName('table');
$table = null;
foreach ($tables as $t) {
    if ($t->getAttribute('class') === 'tablelines') {
        $table = $t;
        break;
    }
}

if (! $table) {
    echo "Table not found\n";
    exit(1);
}

$rows = $table->getElementsByTagName('tr');
$extracted = [];

$headerSkipped = false;
foreach ($rows as $row) {
    $cells = $row->getElementsByTagName('td');
    if ($cells->length === 0) {
        if (! $headerSkipped) {
            $headerSkipped = true;
        }

        continue;
    }

    $oilName = trim($cells[0]->textContent);
    $oilName = preg_replace('/\s+/', ' ', $oilName);
    $oilName = str_replace(['&nbsp;', "\xc2\xa0"], ' ', $oilName);
    $oilName = trim(preg_replace('/<[^>]+>/', '', $oilName));

    $sapText = trim($cells[1]->textContent);
    $sapText = str_replace(['&nbsp;', "\xc2\xa0", ' '], '', $sapText);

    if (preg_match('/(\d+)\s*-\s*(\d+)/', $sapText, $m)) {
        $sapValue = ($m[1] + $m[2]) / 2;
    } elseif (preg_match('/(\d+\.?\d*)/', $sapText, $m)) {
        $sapValue = (float) $m[1];
    } else {
        $sapValue = null;
    }

    $naohValue = trim($cells[2]->textContent);
    $naohValue = str_replace(['&nbsp;', "\xc2\xa0", ' '], '', $naohValue);
    $naohValue = (preg_match('/[\d.]+/', $naohValue, $m) ? (float) $m[0] : null);

    $kohValue = trim($cells[3]->textContent);
    $kohValue = str_replace(['&nbsp;', "\xc2\xa0", ' '], '', $kohValue);
    $kohValue = (preg_match('/[\d.]+/', $kohValue, $m) ? (float) $m[0] : null);

    $inciName = trim($cells[4]->textContent);
    $inciName = str_replace(['&nbsp;', "\xc2\xa0", '&#160;'], ' ', $inciName);
    $inciName = trim(html_entity_decode($inciName, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    $extracted[] = [
        'name' => $oilName,
        'sap_value' => $sapValue,
        'naoh_sap_value' => $naohValue,
        'koh_sap_value' => $kohValue,
        'inci_name' => $inciName,
    ];
}

function extractBaseName(string $name): string
{
    $name = strtolower($name);
    $name = str_replace('_', ' ', $name);

    $patterns = [
        '/\boil\b/i',
        '/\bbutter\b/i',
        '/\bseed\b/i',
        '/\bkernel\b/i',
        '/\b(oil,|oils,)/i',
        '/,.*$/',
        '/\borganic\b/i',
        '/\bvirgin\b/i',
        '/\brefined\b/i',
        '/\bdeodorized\b/i',
        '/\bunrefined\b/i',
        '/\bhigh\s*oleic\b/i',
        '/\bhydrogenated\b/i',
        '/\b(mct|caprylic\s*capric)/i',
        '/\b(20%|22%|gla)\b/i',
    ];

    foreach ($patterns as $pattern) {
        $name = preg_replace($pattern, '', $name);
    }

    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);

    $name = preg_replace('/\s+/', '', $name);

    return $name;
}

function getMatchScore(string $fnwlName, string $jsonName): ?float
{
    $fnwlBase = extractBaseName($fnwlName);
    $jsonBase = extractBaseName($jsonName);

    if ($fnwlBase === '' || $jsonBase === '') {
        return null;
    }

    if ($fnwlBase === $jsonBase) {
        $lenDiff = abs(strlen($fnwlBase) - strlen($jsonBase));

        return 100 - $lenDiff;
    }

    $score = 0;
    if (strpos($fnwlBase, $jsonBase) !== false) {
        $score = 50 + (strlen($jsonBase) / strlen($fnwlBase)) * 50;
    } elseif (strpos($jsonBase, $fnwlBase) !== false) {
        $score = 50 + (strlen($fnwlBase) / strlen($jsonBase)) * 50;
    }

    return $score > 0 ? $score : null;
}

function namesMatch(string $fnwlName, string $jsonName): bool
{
    return getMatchScore($fnwlName, $jsonName) !== null;
}

$matched = 0;
$updated = 0;
$notFound = [];

foreach ($extracted as $entry) {
    $fnwlName = $entry['name'];

    $bestMatchIndex = null;
    $bestMatchScore = 0;
    $bestMatchInci = false;
    $bestBaseLen = 0;

    foreach ($oils as $index => $oil) {
        if (! isset($oil['source_key']) || $oil['source_key'] === null) {
            continue;
        }

        $sourceKey = $oil['source_key'];

        if (isset($oil['inci_name']) && $oil['inci_name'] !== null && $oil['inci_name'] !== '') {
            if (strcasecmp(trim($oil['inci_name']), trim($entry['inci_name'])) === 0 && $entry['inci_name'] !== '') {
                $bestMatchIndex = $index;
                $bestMatchScore = PHP_INT_MAX;
                $bestMatchInci = true;
                break;
            }
        }

        $score = getMatchScore($fnwlName, $sourceKey);
        $baseLen = strlen(extractBaseName($sourceKey));
        if ($score !== null && ($score > $bestMatchScore || ($score === $bestMatchScore && $baseLen >= $bestBaseLen))) {
            $bestMatchScore = $score;
            $bestMatchIndex = $index;
            $bestMatchInci = false;
            $bestBaseLen = $baseLen;
        }
    }

    if ($bestMatchIndex !== null) {
        $matched++;

        $changed = false;

        if ($entry['koh_sap_value'] !== null && ($oils[$bestMatchIndex]['koh_sap_value'] ?? null) === null) {
            $oils[$bestMatchIndex]['koh_sap_value'] = $entry['koh_sap_value'];
            $changed = true;
        }

        if ($entry['naoh_sap_value'] !== null && ($oils[$bestMatchIndex]['naoh_sap_value'] ?? null) === null) {
            $oils[$bestMatchIndex]['naoh_sap_value'] = $entry['naoh_sap_value'];
            $changed = true;
        }

        if ($entry['inci_name'] !== '' && ! isset($oils[$bestMatchIndex]['inci_name'])) {
            $oils[$bestMatchIndex]['inci_name'] = $entry['inci_name'];
            $changed = true;
        }

        if ($changed) {
            $updated++;
        }
    } else {
        $notFound[] = $fnwlName;
    }
}

file_put_contents($jsonPath, json_encode($oils, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo 'Extracted '.count($extracted)." oils from FNWL page\n";
echo "Matched: $matched\n";
echo "Updated: $updated\n";
echo 'Not found in JSON: '.count($notFound)."\n";

if (count($notFound) > 0) {
    echo "\nUnmatched oils:\n";
    foreach (array_slice($notFound, 0, 30) as $name) {
        echo "  - $name\n";
    }
    if (count($notFound) > 30) {
        echo '  ... and '.(count($notFound) - 30)." more\n";
    }
}
