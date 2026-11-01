<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor & Student Grading System
 * File: logout.php
 * Terminates user sessions securely and redirects to index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

logoutUser();

// Start fresh session to pass redirect notice to homepage
startSecureSession();
$_SESSION['flash'] = [
    'type'    => 'success',
    'message' => 'Matagumpay kang naka-sign out sa Global Reciprocal Colleges portal.'
];

header('Location: index.php');
exit;
