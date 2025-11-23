<?php
// Read the file
$lines = file('dean/dean_requests.php', FILE_IGNORE_NEW_LINES);

// Find the start and end of the problematic section
$startLine = -1;
$endLine = -1;

for ($i = 0; $i < count($lines); $i++) {
    if (strpos($lines[$i], '<?php ') !== false && strpos($lines[$i], '$release_date') !== false) {
        $startLine = $i;
    }
    if ($startLine >= 0 && strpos($lines[$i], 'endif; ?>') !== false && strpos($lines[$i], 'Not scheduled') !== false) {
        $endLine = $i;
        break;
    }
}

if ($startLine >= 0 && $endLine >= 0) {
    // Replace the problematic section
    $newSection = [
        '									<span style="color: var(--gray-400); font-style: italic;">Not scheduled</span>'
    ];
    
    // Remove old lines and insert new
    array_splice($lines, $startLine, $endLine - $startLine + 1, $newSection);
    
    // Write back to file
    file_put_contents('dean/dean_requests.php', implode("\n", $lines));
    echo "Fixed dean_requests.php - replaced lines $startLine to $endLine\n";
} else {
    echo "Could not find the problematic section\n";
}
?>
