<?php

declare(strict_types=1);

const LOGIN_RATE_LIMIT_MAX_ATTEMPTS = 5;
const LOGIN_RATE_LIMIT_WINDOW_SECONDS = 900;

function login_rate_limit_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return 'unknown';
    }

    return substr($ip, 0, 45);
}

function login_rate_limit_normalize_username(string $username): string
{
    return strtolower(trim($username));
}

function login_rate_limit_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS login_attempt (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            username_norm VARCHAR(100) NOT NULL DEFAULT \'\',
            attempted_at DATETIME NOT NULL,
            KEY idx_login_attempt_ip_time (ip, attempted_at),
            KEY idx_login_attempt_user_time (username_norm, attempted_at)
        )
    ');
}

function login_rate_limit_count_recent(PDO $pdo, string $ip, string $usernameNorm): int
{
    login_rate_limit_ensure_schema($pdo);
    $since = date('Y-m-d H:i:s', time() - LOGIN_RATE_LIMIT_WINDOW_SECONDS);

    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM login_attempt
        WHERE attempted_at >= :since
          AND (ip = :ip OR (username_norm <> \'\' AND username_norm = :username_norm))
    ');
    $stmt->execute([
        'since' => $since,
        'ip' => $ip,
        'username_norm' => $usernameNorm,
    ]);

    return (int) $stmt->fetchColumn();
}

function login_rate_limit_is_blocked(PDO $pdo, string $ip, string $username): bool
{
    login_rate_limit_ensure_schema($pdo);
    $usernameNorm = login_rate_limit_normalize_username($username);

    return login_rate_limit_count_recent($pdo, $ip, $usernameNorm) >= LOGIN_RATE_LIMIT_MAX_ATTEMPTS;
}

function login_rate_limit_record_failure(PDO $pdo, string $ip, string $username): void
{
    login_rate_limit_ensure_schema($pdo);
    $usernameNorm = login_rate_limit_normalize_username($username);

    $stmt = $pdo->prepare('
        INSERT INTO login_attempt (ip, username_norm, attempted_at)
        VALUES (:ip, :username_norm, :attempted_at)
    ');
    $stmt->execute([
        'ip' => $ip,
        'username_norm' => $usernameNorm,
        'attempted_at' => date('Y-m-d H:i:s'),
    ]);

    login_rate_limit_prune_old($pdo);
}

function login_rate_limit_clear(PDO $pdo, string $ip, string $username): void
{
    login_rate_limit_ensure_schema($pdo);
    $usernameNorm = login_rate_limit_normalize_username($username);
    $since = date('Y-m-d H:i:s', time() - LOGIN_RATE_LIMIT_WINDOW_SECONDS);

    $stmt = $pdo->prepare('
        DELETE FROM login_attempt
        WHERE attempted_at >= :since
          AND (ip = :ip OR (username_norm <> \'\' AND username_norm = :username_norm))
    ');
    $stmt->execute([
        'since' => $since,
        'ip' => $ip,
        'username_norm' => $usernameNorm,
    ]);
}

function login_rate_limit_prune_old(PDO $pdo): void
{
    static $lastPrune = 0;
    $now = time();
    if ($lastPrune > 0 && ($now - $lastPrune) < 300) {
        return;
    }
    $lastPrune = $now;

    $cutoff = date('Y-m-d H:i:s', $now - (LOGIN_RATE_LIMIT_WINDOW_SECONDS * 4));
    $pdo->prepare('DELETE FROM login_attempt WHERE attempted_at < :cutoff')
        ->execute(['cutoff' => $cutoff]);
}
