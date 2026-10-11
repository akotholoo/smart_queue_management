```php
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// Only hospital administrators and doctors need this check.
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    !in_array(
        $_SESSION['role'],
        ['hospital_admin', 'doctor'],
        true
    )
) {
    return;
}

try {
    // Check the current database status, not just the session.
    $stmt = $pdo->prepare(
        "SELECT
            u.account_status,
            u.hospital_id,
            h.status AS hospital_status
         FROM users u
         LEFT JOIN hospitals h
            ON h.hospital_id = u.hospital_id
         WHERE u.user_id = ?
           AND u.role = ?
         LIMIT 1"
    );

    $stmt->execute([
        $_SESSION['user_id'],
        $_SESSION['role']
    ]);

    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$account ||
        $account['account_status'] !== 'active' ||
        $account['hospital_status'] !== 'active' ||
        empty($account['hospital_id']) ||
        (int) $account['hospital_id'] !==
            (int) ($_SESSION['hospital_id'] ?? 0)
    ) {
        // Remove the authenticated session while retaining a message.
        $_SESSION = [
            'access_error' =>
                'Access denied. Your account or hospital is not active.'
        ];

        session_regenerate_id(true);

        header('Location: ../auth/login.php');
        exit;
    }

} catch (PDOException $e) {
    error_log('Hospital access check failed: ' . $e->getMessage());

    // Fail closed if the status cannot be verified.
    $_SESSION = [
        'access_error' =>
            'We could not verify your access. Please log in again.'
    ];

    session_regenerate_id(true);

    header('Location: ../auth/login.php');
    exit;
}

