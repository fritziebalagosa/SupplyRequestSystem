<?php
echo "<h2>Directory Structure Test</h2>";

echo "<h3>Current Working Directory:</h3>";
echo getcwd() . "<br>";

echo "<h3>Server Info:</h3>";
echo "PHP_SELF: " . $_SERVER['PHP_SELF'] . "<br>";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "<br>";

echo "<h3>Directory Checks:</h3>";
echo "uploads/ exists: " . (is_dir('uploads') ? 'YES' : 'NO') . "<br>";
echo "../uploads/ exists: " . (is_dir('../uploads') ? 'YES' : 'NO') . "<br>";

if (is_dir('uploads')) {
    echo "<h3>Files in uploads/:</h3>";
    $files = scandir('uploads');
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "- $file (exists: " . (file_exists('uploads/' . $file) ? 'YES' : 'NO') . ")<br>";
        }
    }
}

if (is_dir('../uploads')) {
    echo "<h3>Files in ../uploads/:</h3>";
    $files = scandir('../uploads');
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "- $file (exists: " . (file_exists('../uploads/' . $file) ? 'YES' : 'NO') . ")<br>";
        }
    }
}

echo "<h3>Test URLs:</h3>";
echo "<a href='uploads/'>uploads/</a><br>";
echo "<a href='../uploads/'>../uploads/</a><br>";
echo "<a href='/SupplyRequestSystem/uploads/'>/SupplyRequestSystem/uploads/</a><br>";
?>
