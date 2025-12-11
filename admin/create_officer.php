<?php
include('../config/db.php');

// Create officer user with . Change password as . Default password . 
$password =.
$first_name =; // Change to actual first name
$last_name; // Change to actual last name
$email; // Change to actual email
$role; // Set to 'officer'
$status; // Set to 'active'

// Hash the password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO users (first_name, last_name, email, password, role, status)
        VALUES (?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssss", $first_name, $last_name, $email, $hashed_password, $role, $status);

if ($ . {
   ; // Change.
    echo
} else . {
     . 
}
?>
