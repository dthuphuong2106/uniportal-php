<?php
/**
 * UniPortal – Database Connection
 * Returns a PDO instance connected to the uniportal MySQL database.
 * Used by all models via require_once.
 *
 * Settings are read from environment variables (Render / production) and
 * fall back to the XAMPP defaults for local development.
 */

require_once __DIR__ . '/env.php';

define('DB_HOST', env_str('DB_HOST', '127.0.0.1'));
define('DB_PORT', env_str('DB_PORT', '3306'));
define('DB_NAME', env_str('DB_NAME', 'uniportal'));
define('DB_USER', env_str('DB_USER', 'root'));   // XAMPP default
define('DB_PASS', env_str('DB_PASS', ''));       // XAMPP default (empty)
define('DB_CHARSET', 'utf8mb4');
// Set DB_SSL=1 for cloud databases that require TLS (e.g. TiDB Cloud).
define('DB_SSL', env_bool('DB_SSL', false));

function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (DB_SSL) {
            // System CA bundle (Debian/Ubuntu path used by the Docker image).
            $options[PDO::MYSQL_ATTR_SSL_CA] = env_str('DB_SSL_CA', '/etc/ssl/certs/ca-certificates.crt');
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Keep NOW()/CURDATE() aligned with PHP's timezone so stored
            // timestamps and PHP date() comparisons agree.
            $pdo->exec("SET time_zone = '" . (new DateTimeImmutable())->format('P') . "'");
        } catch (PDOException $e) {
            // Show a clean error; never expose $e->getMessage() in production
            error_log('UniPortal DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('<p style="font-family:sans-serif;color:#c00;">Database connection failed. '
               . 'Please check the database settings (DB_HOST, DB_USER, DB_PASS, DB_NAME).</p>');
        }
    }

    return $pdo;
}
