<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;

class SyncUserLastLoginAt implements JobDataLess
{
    public function run(): void
    {
        // Load internal configuration (contains DB credentials)
        $config = include 'data/config-internal.php';
        $db = $config['database'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'] ?? 3306,
            $db['dbname'],
            $db['charset'] ?? 'utf8mb4'
        );

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        // Enforce TLS when database requires secure transport
        if (!empty($db['sslCA'])) {
            $options[\PDO::MYSQL_ATTR_SSL_CA] = $db['sslCA'];
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        $pdo = new \PDO($dsn, $db['user'], $db['password'], $options);

        // Determine last successful login per user from auth logs
        $rows = $pdo->query("
            SELECT username, MAX(created_at) AS last_login
            FROM auth_log_record
            WHERE username IS NOT NULL AND username <> ''
            GROUP BY username
        ")->fetchAll();

        if (!$rows) {
            return;
        }

        // Update stored last login field on User entity
        $update = $pdo->prepare("
            UPDATE user
            SET c_last_login_at = :lastLogin
            WHERE user_name = :username
        ");

        foreach ($rows as $row) {
            $update->execute([
                ':lastLogin' => $row['last_login'],
                ':username'  => $row['username'],
            ]);
        }
    }
}
