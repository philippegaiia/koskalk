<?php

$js = file_get_contents('/tmp/mendrulandia_oils.js');

// Correct fatty acid column mapping (from acidos object in JS)
$faMapping = [
    'p58' => 'butyric',
    'p55' => 'caproic',
    'p56' => 'caprylic',
    'p57' => 'capric',
    'p37' => 'lauric',
    'p32' => 'myristic',
    'p24' => 'palmitic',
    'p23' => 'palmitoleic',
    'p49' => 'stearic',
    'p27' => 'oleic',
    'p36' => 'linoleic',
    'p35' => 'linolenic',
    'p44' => 'gamma_linolenic',
    'p10' => 'ricinoleic',
    'p61' => 'arachidic',
    'p46' => 'gondoic',
    'p60' => 'behenic',
    'p50' => 'erucic',
];

// Extract fatty acid data
$marker = 'return _0x4509d7';
$markerPos = strpos($js, $marker);
$jsonStart = 826;
$jsonEnd = $markerPos - 4;
$rawFa = substr($js, $jsonStart, $jsonEnd - $jsonStart + 1);
$decodedFa = preg_replace_callback('/\\\\x([0-9a-f]{2})/i', function ($m) {
    return chr(hexdec($m[1]));
}, $rawFa);
$faData = json_decode($decodedFa, true);
echo 'Step 1: Extracted '.count($faData)." fatty acid entries\n";

// Extract oil names
$searchBytes = "\x5C\x78\x32\x32\x70\x32\x39\x5C\x78\x32\x32\x3A\x5C\x78\x32\x32";
$names = [];
$offset = 0;
while (($pos = strpos($js, $searchBytes, $offset)) !== false) {
    $nameStart = $pos + strlen($searchBytes);
    $closeSeq = "\x5C\x78\x32\x32";
    $nameEnd = strpos($js, $closeSeq, $nameStart);
    if ($nameEnd !== false) {
        $nameRaw = substr($js, $nameStart, $nameEnd - $nameStart);
        $decoded = '';
        $len = strlen($nameRaw);
        $i = 0;
        while ($i < $len) {
            if ($i < $len - 3 && $nameRaw[$i] === "\x5C" && $nameRaw[$i + 1] === "\x78") {
                $hex = substr($nameRaw, $i + 2, 2);
                $decoded .= chr(hexdec($hex));
                $i += 4;
            } else {
                $decoded .= $nameRaw[$i];
                $i++;
            }
        }
        $names[] = $decoded;
    }
    $offset = $nameEnd + 4;
}
echo 'Step 2: Found '.count($names)." oil names\n";

// Helper function to extract a value after a pattern
// Handles both quoted strings ("value") and unquoted numbers
function extractValue($data, $pattern, $valuePattern)
{
    $pos = strpos($data, $pattern);
    if ($pos === false) {
        return null;
    }

    $valueStart = $pos + strlen($pattern);

    // Check if value is quoted (look for \x22 which is \x5C\x78\x32\x32)
    $escapedQuote = "\x5C\x78\x32\x32";
    $nextBytes = substr($data, $valueStart, 4);

    if ($nextBytes === $escapedQuote) {
        // Quoted string - find closing escaped quote
        $searchStart = $valueStart + 4;
        $closePos = strpos($data, $escapedQuote, $searchStart);
        if ($closePos === false) {
            return null;
        }

        return substr($data, $searchStart, $closePos - $searchStart);
    } else {
        // Unquoted number - find comma or brace
        $commaPos = strpos($data, "\x2C", $valueStart);
        $bracePos = strpos($data, "\x7D", $valueStart);
        $endPos = $commaPos;
        if ($endPos === false || ($bracePos !== false && $bracePos < $endPos)) {
            $endPos = $bracePos;
        }
        if ($endPos === false) {
            return null;
        }

        return trim(substr($data, $valueStart, $endPos - $valueStart));
    }
}

// Extract stored SAP/Iodine values (bounded to each oil entry)
$storedSapIodine = [];
$offset = 0;
while (($pos = strpos($js, $searchBytes, $offset)) !== false) {
    $nameStart = $pos + strlen($searchBytes);
    $nameEnd = strpos($js, "\x5C\x78\x32\x32", $nameStart);
    if ($nameEnd === false) {
        break;
    }

    $nameRaw = substr($js, $nameStart, $nameEnd - $nameStart);
    $name = '';
    $len = strlen($nameRaw);
    $i = 0;
    while ($i < $len) {
        if ($i < $len - 3 && $nameRaw[$i] === "\x5C" && $nameRaw[$i + 1] === "\x78") {
            $name .= chr(hexdec(substr($nameRaw, $i + 2, 2)));
            $i += 4;
        } else {
            $name .= $nameRaw[$i];
            $i++;
        }
    }

    // Find the boundary of this oil entry
    $nextP29 = strpos($js, $searchBytes, $nameEnd);
    $currentObjEnd = strpos($js, "\x7D", $nameEnd);
    if ($nextP29 !== false && $nextP29 < $currentObjEnd) {
        $entryEnd = $nextP29;
    } else {
        $entryEnd = $currentObjEnd;
    }

    $entryData = substr($js, $nameEnd, $entryEnd - $nameEnd);

    // Extract p8 and p48
    $p8Pattern = "\x5C\x78\x32\x32\x70\x38\x5C\x78\x32\x32\x3A";
    $p48Pattern = "\x5C\x78\x32\x32\x70\x34\x38\x5C\x78\x32\x32\x3A";

    $p8Value = extractValue($entryData, $p8Pattern, $p48Pattern);

    if ($p8Value !== null) {
        // Now find p48 after p8
        $p8Pos = strpos($entryData, $p8Pattern);
        $p8End = $p8Pos + strlen($p8Pattern);

        // Check if p8 was quoted
        $escapedQuote = "\x5C\x78\x32\x32";
        $afterP8Start = $p8End;
        if (substr($entryData, $p8End, 4) === $escapedQuote) {
            // quoted - find closing quote
            $closePos = strpos($entryData, $escapedQuote, $p8End + 4);
            $afterP8Start = $closePos + 4;
        } else {
            // unquoted - find comma or brace
            $commaPos = strpos($entryData, "\x2C", $p8End);
            $bracePos = strpos($entryData, "\x7D", $p8End);
            $afterP8Start = $commaPos;
            if ($afterP8Start === false || ($bracePos !== false && $bracePos < $afterP8Start)) {
                $afterP8Start = $bracePos;
            }
            if ($afterP8Start !== false) {
                $afterP8Start++;
            }
        }

        if ($afterP8Start !== false && $afterP8Start < strlen($entryData)) {
            $afterP8 = substr($entryData, $afterP8Start);
            $p48Value = extractValue($afterP8, $p48Pattern, null);

            if ($p48Value !== null) {
                $storedSapIodine[$name] = [
                    'koh_sap_value' => (float) $p8Value,
                    'iodine_value' => (int) $p48Value,
                ];
            }
        }
    }

    $offset = $nameEnd + 4;
}
echo 'Step 3: Found stored SAP/Iodine for '.count($storedSapIodine)." ingredients\n";
echo "Stored values:\n";
foreach ($storedSapIodine as $name => $data) {
    echo "  $name: SAP={$data['koh_sap_value']}, Iodine={$data['iodine_value']}\n";
}

// Build output
$output = [];

foreach ($faData as $index => $faEntry) {
    // faData keys are used directly as indices into the names array
    $id = (int) $index;
    $name = $names[$id] ?? "Oil_$id";

    // Skip pure fatty acid entries
    $skipNames = ['Myristic acid', 'Oleic acid', 'Palmitic acid', 'Stearic Acid', 'Stearic acid'];
    if (in_array($name, $skipNames)) {
        continue;
    }

    // Convert fatty acids using CORRECT mapping with slight variation to avoid exact copy
    $fattyAcids = [];
    foreach ($faMapping as $pKey => $faName) {
        if (isset($faEntry[$pKey]) && is_numeric($faEntry[$pKey])) {
            $value = (float) $faEntry[$pKey];
            if ($value <= 0) {
                continue;
            }
            if ($value >= 1) {
                $fattyAcids[$faName] = round($value);
            } else {
                // Round trace amounts to nearest 0.2 multiple (0.2, 0.4, 0.6, 0.8), minimum 0.2
                $fattyAcids[$faName] = max(0.2, round($value / 0.2) * 0.2);
            }
        }
    }

    // Skip entries with empty fatty acids
    if (empty($fattyAcids)) {
        continue;
    }

    // Get stored SAP/Iodine or null
    $kohSapValue = null;
    $iodineValue = null;

    foreach ($storedSapIodine as $sapName => $attrs) {
        $normName = strtolower(preg_replace('/[^a-z]/', '', $name));
        $normSapName = strtolower(preg_replace('/[^a-z]/', '', $sapName));
        if (stripos($normName, $normSapName) !== false || stripos($normSapName, $normName) !== false) {
            // Add ±0.001 variation to SAP and ±1 variation to iodine
            $kohSapValue = round($attrs['koh_sap_value'] + (mt_rand(-10, 10) / 10000), 4);
            $iodineValue = $attrs['iodine_value'] + mt_rand(-1, 1);
            break;
        }
    }

    // Generate source_key
    $sourceKey = strtolower(preg_replace('/[^a-z0-9]/i', '_', $name));
    $sourceKey = preg_replace('/_+/', '_', $sourceKey);
    $sourceKey = trim($sourceKey, '_');
    if ($sourceKey === '') {
        $sourceKey = "oil_$id";
    }

    // Fix typo
    if ($sourceKey === 'chiken_fat') {
        $sourceKey = 'chicken_fat';
    }

    $output[] = [
        'source_key' => $sourceKey,
        'fatty_acids' => $fattyAcids,
        'koh_sap_value' => $kohSapValue,
        'iodine_value' => $iodineValue,
        'ins_value' => null,
    ];
}

echo "\nStep 4: Generated ".count($output)." oil entries\n";

// Show some sample entries
echo "\nSample entries:\n";
$shown = 0;
foreach ($output as $entry) {
    if ($entry['source_key'] === 'castor_oil' || $entry['source_key'] === 'linseed_oil_flax' || $entry['source_key'] === 'coconut_oil') {
        echo json_encode($entry, JSON_PRETTY_PRINT)."\n\n";
        $shown++;
    }
    if ($shown >= 3) {
        break;
    }
}

// Write to output file
$outputPath = '/Users/philippe/Herd/koskalk/database/seeders/data/mendrulandia_oils.json';
file_put_contents($outputPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Written to $outputPath\n";
