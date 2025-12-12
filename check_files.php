<?php
$output = "=== File Check Results ===\n";
$output .= "Current directory: " . getcwd() . "\n";
$output .= "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Check uploads directory
if (is_dir('uploads')) {
    $output .= "uploads/ directory EXISTS\n";
    $files = scandir('uploads');
    $output .= "Files in uploads/:\n";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $output .= "  - $file\n";
        }
    }
} else {
    $output .= "uploads/ directory DOES NOT EXIST\n";
}

$output .= "\n";

// Check parent uploads directory  
if (is_dir('../uploads')) {
    $output .= "../uploads/ directory EXISTS\n";
    $files = scandir('../uploads');
    $output .= "Files in ../uploads/:\n";
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $output .= "  - $file\n";
        }
    }
} else {
    $output .= "../uploads/ directory DOES NOT EXIST\n";
}

// Write to file
file_put_contents('file_check_results.txt', $output);

echo "Results written to file_check_results.txt\n";
?>
