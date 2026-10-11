<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// 1. Make sure the user is logged in as a patient.
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'patient'
) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$success = '';
$errors = [];

// 2. Handle marking a notification as read.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['mark_read'])
) {
    $notificationId = (int) $_POST['mark_read'];

    if ($notificationId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE notification_id = ?
             AND user_id = ?"
        );

        $stmt->execute([
            $notificationId,
            $userId
        ]);

        if ($stmt->rowCount() > 0) {
            $success = 'Notification marked as read.';
        } else {
            $errors[] = 'Notification was already read or could not be found.';
        }
    } else {
        $errors[] = 'Invalid notification selected.';
    }
}

// 3. Mark all notifications as read.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['mark_all_read'])
) {
    $stmt = $pdo->prepare(
        "UPDATE notifications
         SET is_read = 1
         WHERE user_id = ?
         AND is_read = 0"
    );

    $stmt->execute([$userId]);

    $success = 'All notifications have been marked as read.';
}

// 4. Get the total number of unread notifications.
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS unread_count
     FROM notifications
     WHERE user_id = ?
     AND is_read = 0"
);

$stmt->execute([$userId]);

$unreadCount = (int) $stmt->fetch()['unread_count'];

// 5. Retrieve this patient's notifications.
$stmt = $pdo->prepare(
    "SELECT
        notification_id,
        hospital_id,
        appointment_id,
        title,
        message,
        notification_type,
        is_read,
        created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC, notification_id DESC"
);

$stmt->execute([$userId]);

$notifications = $stmt->fetchAll();

// 6. Safely display text.
function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">

```
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>My Notifications | Smart Queue Management</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

<style>
    .notifications-container {
        max-width: 950px;
        margin: 35px auto;
        padding: 20px;
    }

    .notifications-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 25px;
    }

    .notification-card {
        background: #ffffff;
        padding: 22px;
        margin-bottom: 16px;
        border-radius: 10px;
        border: 1px solid #e5e5e5;
    }

    .notification-card.unread {
        border-left: 5px solid #176b5b;
        background: #f3fbf8;
    }

    .notification-card.read {
        border-left: 5px solid #cccccc;
    }

    .notification-title {
        margin: 0 0 10px;
    }

    .notification-message {
        line-height: 1.6;
        overflow-wrap: anywhere;
    }

    .notification-meta {
        color: #666666;
        font-size: 13px;
        margin-top: 12px;
    }

    .notification-type {
        display: inline-block;
        padding: 5px 10px;
        background: #eeeeee;
        border-radius: 20px;
        font-size: 12px;
        margin-bottom: 10px;
    }

    .unread-badge {
        display: inline-block;
        padding: 6px 10px;
        background: #176b5b;
        color: white;
        border-radius: 20px;
        font-size: 13px;
    }

    .notification-button {
        padding: 9px 14px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        background: #176b5b;
        color: #ffffff;
    }

    .notification-button:hover {
        background: #105044;
    }

    .notification-actions {
        margin-top: 15px;
    }

    .message-box {
        padding: 13px;
        border-radius: 6px;
        margin-bottom: 18px;
    }

    .success-message {
        background: #e5f7eb;
        color: #176b36;
    }

    .error-message {
        background: #fdeaea;
        color: #9b2020;
    }

    .empty-notifications {
        background: #ffffff;
        padding: 25px;
        border-radius: 10px;
        text-align: center;
    }

    .back-link {
        display: inline-block;
        margin-bottom: 20px;
    }
</style>
```

</head>

<body>

<div class="notifications-container">

```
<a
    href="dashboard.php"
    class="back-link"
>
    &larr; Back to Patient Dashboard
</a>

<div class="notifications-header">

    <div>
        <h1>My Notifications</h1>

        <p>
            View your appointment confirmations and queue updates.
        </p>

        <p>
            Unread notifications:
            <span class="unread-badge">
                <?= $unreadCount ?>
            </span>
        </p>
    </div>

    <?php if ($unreadCount > 0): ?>

        <form method="POST">

            <button
                type="submit"
                name="mark_all_read"
                value="1"
                class="notification-button"
            >
                Mark All as Read
            </button>

        </form>

    <?php endif; ?>

</div>

<?php if ($success !== ''): ?>

    <div class="message-box success-message">
        <?= e($success) ?>
    </div>

<?php endif; ?>

<?php if (!empty($errors)): ?>

    <div class="message-box error-message">

        <?php foreach ($errors as $error): ?>

            <p><?= e($error) ?></p>

        <?php endforeach; ?>

    </div>

<?php endif; ?>

<?php if (empty($notifications)): ?>

    <div class="empty-notifications">

        <h2>No notifications yet</h2>

        <p>
            When you book an appointment or receive a queue update,
            your notifications will appear here.
        </p>

        <a href="book_appointment.php">
            Book an Appointment
        </a>

    </div>

<?php else: ?>

    <?php foreach ($notifications as $notification): ?>

        <div class="notification-card <?= (int) $notification['is_read'] === 0 ? 'unread' : 'read' ?>">

            <span class="notification-type">
                <?= e(ucwords(str_replace(
                    '_',
                    ' ',
                    $notification['notification_type']
                ))) ?>
            </span>

            <?php if ((int) $notification['is_read'] === 0): ?>

                <span class="unread-badge">
                    New
                </span>

            <?php endif; ?>

            <h2 class="notification-title">
                <?= e($notification['title']) ?>
            </h2>

            <p class="notification-message">
                <?= nl2br(e($notification['message'])) ?>
            </p>

            <div class="notification-meta">

                Received:
                <?= e($notification['created_at']) ?>

            </div>

            <?php if (
                $notification['appointment_id'] !== null
            ): ?>

                <div class="notification-actions">

                    <a href="queue_status.php">
                        View Queue Status
                    </a>

                </div>

            <?php endif; ?>

            <?php if ((int) $notification['is_read'] === 0): ?>

                <form
                    method="POST"
                    class="notification-actions"
                >

                    <input
                        type="hidden"
                        name="mark_read"
                        value="<?= (int) $notification['notification_id'] ?>"
                    >

                    <button
                        type="submit"
                        class="notification-button"
                    >
                        Mark as Read
                    </button>

                </form>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

<?php endif; ?>
```

</div>

</body>
</html>
