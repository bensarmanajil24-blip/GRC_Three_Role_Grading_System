<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor & Student Grading System
 * File: includes/db.php
 * Central MySQL Database Connection using PHP Data Objects (PDO)
 */

declare(strict_types=1);

// =========================================================================
// MYSQL DATABASE CONNECTION CONFIGURATION
// I-configure ang iyong MySQL server credentials dito:
// =========================================================================
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', '3306');
if (!defined('DB_NAME')) define('DB_NAME', 'grc_grading');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', ''); // Default sa XAMPP ay walang password

/**
 * Returns a shared PDO MySQL connection instance.
 * Automatically configures UTF-8 encoding and PDO Exception error mode.
 *
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $charset = 'utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        // Direct connection attempt to the grc_grading database
        $dsnWithDb = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, $charset);
        $pdo = new PDO($dsnWithDb, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // If database does not exist yet (Error code 1049), attempt auto-creation
        if ($e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
            try {
                $dsnWithoutDb = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, $charset);
                $serverPdo = new PDO($dsnWithoutDb, DB_USER, DB_PASS, $options);
                $serverPdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                
                // Re-attempt connecting now that database is created
                $pdo = new PDO($dsnWithDb, DB_USER, DB_PASS, $options);
            } catch (PDOException $createEx) {
                throw new PDOException(
                    'Hindi makakonekta sa MySQL. Tiyaking tumatakbo ang MySQL sa XAMPP Control Panel at i-import ang database/grc_grading.sql. Error: ' . $createEx->getMessage(),
                    (int)$createEx->getCode()
                );
            }
        } else {
            throw new PDOException(
                'MySQL Connection Error: ' . $e->getMessage() . '. Pakisuri ang MySQL username at password sa includes/db.php.',
                (int)$e->getCode()
            );
        }
    }

    return $pdo;
}
