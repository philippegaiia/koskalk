<?php

$js = file_get_contents('/tmp/mendrulandia_oils.js', FILE_BINARY);

// Fatty acid key mapping from pXX to platform names
$faMapping = [
    'p24' => 'lauric',
    'p23' => 'myristic',
    'p27' => 'palmitic',
    'p36' => 'stearic',
    'p28' => 'ricinoleic',
    'p30' => 'oleic',
    'p31' => 'linoleic',
    'p33' => 'linolenic',
];

// ====== STEP 1: Extract fatty acid data from x2yxmba ======
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

// ====== STEP 2: Extract oil names ======
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

// ====== STEP 3: Extract SAP/Iodine values ======
// p8 = SAP value, p48 = iodine
// Pattern: p29\x22:\x22NAME\x22,\x22p0\x22:NUMBER,\x22p8\x22:VALUE,\x22p48\x22:VALUE
$searchSap = "\x5C\x78\x32\x32\x70\x32\x39\x5C\x78\x32\x32\x3A\x5C\x78\x32\x32";
$sapPattern = "\x5C\x78\x32\x32\x70\x38\x5C\x78\x32\x32\x3A";
$iodinePattern = "\x5C\x78\x32\x32\x70\x34\x38\x5C\x78\x32\x32\x3A";

$sapValues = [];
$offset = 0;
while (($pos = strpos($js, $searchSap, $offset)) !== false) {
    // Find the name for this entry
    $nameStart = $pos + strlen($searchSap);
    $nameEnd = strpos($js, "\x5C\x78\x32\x32", $nameStart);
    if ($nameEnd !== false) {
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

        // Now find p8 value after p29
        $p8Pos = strpos($js, $sapPattern, $nameEnd);
        if ($p8Pos !== false) {
            $p8Start = $p8Pos + strlen($sapPattern);
            $p8End = strpos($js, "\x2C", $p8Start);
            if ($p8End !== false) {
                $sapValue = (float) substr($js, $p8Start, $p8End - $p8Start);

                // Find p48 (iodine) after p8
                $iodinePos = strpos($js, $iodinePattern, $p8End);
                if ($iodinePos !== false) {
                    $iodineStart = $iodinePos + strlen($iodinePattern);
                    $iodineEnd = strpos($js, "\x2C", $iodineStart);
                    if ($iodineEnd !== false) {
                        $iodineValue = (int) substr($js, $iodineStart, $iodineEnd - $iodineStart);

                        $sapValues[$name] = [
                            'koh_sap_value' => $sapValue,
                            'iodine_value' => $iodineValue,
                        ];
                    }
                }
            }
        }
    }
    $offset = $nameEnd + 4;
}
echo 'Step 3: Found SAP/Iodine for '.count($sapValues)." oils\n";

// ====== STEP 4: Build output ======
$output = [];

foreach ($faData as $index => $faEntry) {
    $id = (int) $index;
    $name = $names[$id] ?? "Oil_$id";

    // Convert fatty acids
    $fattyAcids = [];
    foreach ($faMapping as $pKey => $faName) {
        if (isset($faEntry[$pKey]) && is_numeric($faEntry[$pKey])) {
            $fattyAcids[$faName] = (float) $faEntry[$pKey];
        }
    }

    // Get SAP/Iodine from extracted values
    $kohSapValue = null;
    $iodineValue = null;

    // Match by name (with some tolerance)
    foreach ($sapValues as $sapName => $attrs) {
        $normName = strtolower(preg_replace('/[^a-z]/', '', $name));
        $normSapName = strtolower(preg_replace('/[^a-z]/', '', $sapName));
        if (stripos($normName, $normSapName) !== false || stripos($normSapName, $normName) !== false) {
            $kohSapValue = $attrs['koh_sap_value'];
            $iodineValue = $attrs['iodine_value'];
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

    $output[] = [
        'source_key' => $sourceKey,
        'fatty_acids' => $fattyAcids,
        'koh_sap_value' => $kohSapValue,
        'iodine_value' => $iodineValue,
        'ins_value' => null,
    ];
}

echo 'Step 4: Generated '.count($output)." oil entries\n";

// Write to output file
$outputPath = '/Users/philippe/Herd/koskalk/database/seeders/data/mendrulandia_oils.json';
file_put_contents($outputPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Written to $outputPath\n";

// Show sample entries
echo "\nSample entries:\n";
for ($i = 0; $i < 5; $i++) {
    echo json_encode($output[$i], JSON_PRETTY_PRINT)."\n\n";
}
