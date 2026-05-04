<?php
// decode.php
header('Content-Type: application/json');

if (!isset($_POST['metar'])) {
    echo json_encode(['error' => 'No METAR provided.']);
    exit;
}

$metarRaw = strtoupper(trim($_POST['metar']));

// Split at "RMK" first to handle remarks separately
$parts = explode(' RMK ', $metarRaw);
$mainBody = $parts[0];
$remarks = isset($parts[1]) ? $parts[1] : null;

$tokens = explode(' ', $mainBody);
$decodedData = [];

// Cloud cover mapping
$cloudCoverMap = [
    'SKC' => 'Sky Clear',
    'CLR' => 'Clear below 12,000 feet',
    'FEW' => 'Few',
    'SCT' => 'Scattered',
    'BKN' => 'Broken',
    'OVC' => 'Overcast',
    'VV'  => 'Vertical Visibility'
];

foreach ($tokens as $token) {
    if (trim($token) === '') continue;

    // 1. Station Identifier (ICAO)
    if (preg_match('/^[A-Z]{4}$/', $token) && !isset($decodedData['Station'])) {
        $decodedData['Station'] = $token;
    } 
    // 2. Issuance Time (e.g., 091955Z)
    elseif (preg_match('/^(\d{2})(\d{2})(\d{2})Z$/', $token, $matches)) {
        $decodedData['Time'] = "Day {$matches[1]} at {$matches[2]}:{$matches[3]} UTC (Zulu)";
    } 
    // 3. Modifiers (COR or AUTO)
    elseif ($token === 'COR') {
        $decodedData['Modifier'] = 'Corrected Observation';
    }
    elseif ($token === 'AUTO') {
        $decodedData['Modifier'] = 'Automated Observation';
    }
    // 4. Winds (e.g., 22015G25KT)
    elseif (preg_match('/^(\d{3}|VRB)(\d{2,3})(G\d{2,3})?(KT|MPS|KMH)$/', $token, $matches)) {
        $direction = $matches[1] === 'VRB' ? 'Variable' : "{$matches[1]}&deg; True";
        $speed = (int)$matches[2];
        $unit = $matches[4];
        
        $windString = "$speed $unit from $direction";
        if (isset($matches[3]) && $matches[3] !== '') {
            $gusts = (int)substr($matches[3], 1);
            $windString .= ", Gusting to $gusts $unit";
        }
        $decodedData['Winds'] = $windString;
    } 
    // 5. Visibility (e.g., 3/4SM, 5SM, P6SM)
    elseif (preg_match('/^(P?)([\d\/]+)SM$/', $token, $matches)) {
        $prefix = $matches[1] === 'P' ? 'Greater than ' : '';
        $decodedData['Visibility'] = "{$prefix}{$matches[2]} Statute Miles";
    }
    // 6. Sky Condition / Clouds (e.g., OVC010CB, FEW020)
    elseif (preg_match('/^(SKC|CLR|FEW|SCT|BKN|OVC|VV)(\d{3})?(CB|TCU)?$/', $token, $matches)) {
        $cover = $cloudCoverMap[$matches[1]];
        $layer = $cover;
        
        if (isset($matches[2]) && $matches[2] !== '') {
            $height = (int)$matches[2] * 100;
            $layer .= " at " . number_format($height) . " feet";
        }
        if (isset($matches[3]) && $matches[3] !== '') {
            $type = $matches[3] === 'CB' ? ' (Cumulonimbus)' : ' (Towering Cumulus)';
            $layer .= $type;
        }
        
        // Append layers if multiple exist
        if (isset($decodedData['Sky Condition'])) {
            $decodedData['Sky Condition'] .= "<br>" . $layer;
        } else {
            $decodedData['Sky Condition'] = $layer;
        }
    }
    // 7. Temperature & Dewpoint (e.g., 18/16 or M05/M10)
    elseif (preg_match('/^(M?\d{2})\/(M?\d{2})?$/', $token, $matches)) {
        $temp = str_replace('M', '-', $matches[1]);
        $dewpoint = isset($matches[2]) ? str_replace('M', '-', $matches[2]) : 'Unknown';
        
        $decodedData['Temperature / Dewpoint'] = "$temp &deg;C / $dewpoint &deg;C";
    } 
    // 8. Altimeter / Pressure (e.g., A2992)
    elseif (preg_match('/^A(\d{4})$/', $token, $matches)) {
        $inches = substr($matches[1], 0, 2) . "." . substr($matches[1], 2, 2);
        $decodedData['Altimeter'] = "$inches inHg";
    }
    // 9. Basic Weather Phenomena (e.g., +TSRA, -SN)
    elseif (preg_match('/^(-|\+|VC)?(TS|SH|FZ|PR|MI|BC|DR|BL)?(RA|SN|DZ|SG|IC|PL|GR|GS|UP|BR|FG|FU|VA|DU|SA|HZ|PY|PO|SQ|FC|SS|DS)+$/', $token, $matches)) {
        // This is a simplified catcher. A full implementation would map every code.
        $intensity = "";
        if ($matches[1] === '-') $intensity = "Light ";
        if ($matches[1] === '+') $intensity = "Heavy ";
        if ($matches[1] === 'VC') $intensity = "In Vicinity: ";
        
        if (isset($decodedData['Weather'])) {
            $decodedData['Weather'] .= ", " . $intensity . $token;
        } else {
            $decodedData['Weather'] = $intensity . $token . " (Raw Code)";
        }
    }
}

// Attach Remarks if they exist
if ($remarks) {
    $decodedData['Remarks'] = $remarks;
}

// Return the parsed data
echo json_encode([
    'raw' => $metarRaw,
    'decoded' => $decodedData
]);
?>