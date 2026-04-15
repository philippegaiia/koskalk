<?php

declare(strict_types=1);

$jsDataPath = '/tmp/chunk_694.js';
$mendrulandiaPath = '/Users/philippe/Herd/koskalk/database/seeders/data/mendrulandia_oils.json';

if (! file_exists($jsDataPath)) {
    echo "Fetching JS data chunk...\n";
    $ch = curl_init('https://soapcalc.net/_next/static/chunks/694-a7684a4a8f980124.js');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    file_put_contents($jsDataPath, curl_exec($ch));
    curl_close($ch);
}

echo "Parsing SoapCalc data...\n";
$jsContent = file_get_contents($jsDataPath);

if (! preg_match('/let l=\[(.+)\]/s', $jsContent, $matches)) {
    exit("Could not find oil data array in JS\n");
}

$arrayStr = $matches[1];
$parts = preg_split('/\},\{id:/', $arrayStr);

$soapcalcOils = [];
$lastIdx = count($parts) - 1;

foreach ($parts as $i => $part) {
    if ($i === 0) {
        $part = preg_replace('/^\[\{id:/', '{id:', $part);
        $part = $part.'}';
    } else {
        $part = '{id:'.$part;
        if ($i === $lastIdx) {
            $part = preg_replace('/\]\}\]\);?$/', '}', $part);
        } else {
            $part = $part.'}';
        }
    }

    $part = ltrim($part, ',');
    if (trim($part) === '') {
        continue;
    }

    $json = $part;
    $json = str_replace(':.', ':0.', $json);
    $json = str_replace(',.', ',0.', $json);
    $json = preg_replace('/([{,])([a-zA-Z_][a-zA-Z0-9_]*):/', '$1"$2":', $json);

    $decoded = json_decode($json, true);
    if ($decoded) {
        $soapcalcOils[] = $decoded;
    }
}

echo 'Found '.count($soapcalcOils)." oils in SoapCalc data\n";

$mendrulandia = json_decode(file_get_contents($mendrulandiaPath), true);

function normalizeName(string $name): string
{
    $name = strtolower($name);
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);
}

function extractBaseName(string $name): string
{
    $name = normalizeName($name);
    $name = preg_replace('/\b(deg|degree|high\s*oleic|virgin|organic|conventional|refined|hydrogenated|fractionated|pomace|cold\s*pressed|roasted|green)\b/', '', $name);
    $name = preg_replace('/\b(oil|butter|wax|fat|tallow)\b/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);
}

function fuzzyMatch(string $soapcalcName, string $mendraKey): bool
{
    $sBase = extractBaseName($soapcalcName);
    $mBase = extractBaseName($mendraKey);

    if ($sBase === '' || $mBase === '') {
        return false;
    }

    if ($sBase === $mBase) {
        return true;
    }

    $sWords = array_filter(explode(' ', $sBase), fn ($w) => strlen($w) > 2);
    $mWords = array_filter(explode(' ', $mBase), fn ($w) => strlen($w) > 2);

    foreach ($sWords as $sWord) {
        foreach ($mWords as $mWord) {
            if ($sWord === $mWord) {
                return true;
            }
        }
    }

    return false;
}

function matchScore(string $soapcalcName, string $matchedMendrulandiaKey): int
{
    $sBase = extractBaseName($soapcalcName);
    $mBase = extractBaseName($matchedMendrulandiaKey);

    if ($sBase === $mBase) {
        return 10;
    }

    return 1;
}

$matched = 0;
$notMatched = [];
$updatedMendrulandia = [];

foreach ($soapcalcOils as $sIdx => $soapcalcOil) {
    $soapcalcName = $soapcalcOil['name'];
    $bestIdx = -1;
    $bestScore = PHP_INT_MIN;

    foreach ($mendrulandia as $mIdx => $mendraOil) {
        $mendraKey = $mendraOil['source_key'] ?? '';
        if ($mendraKey === '') {
            continue;
        }

        if (fuzzyMatch($soapcalcName, $mendraKey)) {
            $score = matchScore($soapcalcName, $mendraKey);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $mIdx;
            }
        }
    }

    if ($bestIdx >= 0) {
        if (! isset($updatedMendrulandia[$bestIdx])) {
            $kohSap = (float) $soapcalcOil['sap'];
            $naohSap = round($kohSap * (40 / 56.1), 3);
            $mendrulandia[$bestIdx]['koh_sap_value'] = $kohSap;
            $mendrulandia[$bestIdx]['naoh_sap_value'] = $naohSap;
            $updatedMendrulandia[$bestIdx] = true;
            $matched++;
        }
    } else {
        $notMatched[] = $soapcalcName;
    }
}

echo "Matched: $matched unique Mendrulandia oils\n";
echo 'Not matched: '.count($notMatched)." SoapCalc oils\n";

if (count($notMatched) > 0) {
    echo "\nUnmatched oils (first 30):\n";
    foreach (array_slice($notMatched, 0, 30) as $name) {
        echo "  - $name\n";
    }
    if (count($notMatched) > 30) {
        echo '  ... and '.(count($notMatched) - 30)." more\n";
    }
}

file_put_contents($mendrulandiaPath, json_encode($mendrulandia, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nUpdated: $mendrulandiaPath\n";

$withData = count(array_filter($mendrulandia, fn ($o) => $o['koh_sap_value'] !== null));
echo "Oils with SAP data: $withData / ".count($mendrulandia)."\n";
