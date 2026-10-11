```php
<?php

session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "patient"
) {
    header("Location: ../auth/login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$full_name = $_SESSION["full_name"] ?? "Patient";

/*
|--------------------------------------------------------------------------
| Verify patient profile
|--------------------------------------------------------------------------
*/

$patientStmt = $pdo->prepare(
    "SELECT patient_id
     FROM patients
     WHERE user_id = ?
     LIMIT 1"
);

$patientStmt->execute([$_SESSION["user_id"]]);
$patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    http_response_code(403);
    exit("Your patient profile could not be found.");
}

/*
|--------------------------------------------------------------------------
| Count unread notifications
|--------------------------------------------------------------------------
*/

$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM notifications
     WHERE user_id = ?
       AND is_read = 0"
);

$notificationStmt->execute([$_SESSION["user_id"]]);

$unreadNotifications = (int) $notificationStmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Patient Dashboard | SmartQueue</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .dashboard {
            padding: 45px 0;
        }

        .welcome-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
        }

        .welcome-card h1 {
            color: #083344;
            margin-bottom: 10px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .dashboard-card {
            background: white;
            padding: 25px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .dashboard-card h2 {
            font-size: 18px;
            color: #164e63;
            margin-bottom: 10px;
        }

        .dashboard-card p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 18px;
        }

        .dashboard-button {
            display: inline-block;
            margin-top: auto;
            padding: 10px 16px;
            background: #0e7490;
            color: white;
            text-decoration: none;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 600;
        }

        .dashboard-button:hover {
            background: #155e75;
        }

        .notification-badge {
            display: inline-block;
            background: #dc2626;
            color: white;
            border-radius: 20px;
            padding: 3px 9px;
            font-size: 12px;
            margin-left: 5px;
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header class="navbar">
    <div class="container navbar-content">

        <a href="../index.php" class="logo">
            SmartQueue
        </a>

        <nav class="nav-links">

            <span>
                <?= htmlspecialchars(
                    $full_name,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </span>

            <a href="../auth/logout.php">
                Log Out
            </a>

        </nav>

    </div>
</header>

<main class="dashboard">

    <div class="container">

        <section class="welcome-card">

            <h1>
                Welcome,
                <?= htmlspecialchars(
                    $full_name,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>!
            </h1>

            <p>
                Welcome to your SmartQueue patient dashboard.
                Book hospital appointments, monitor your queue
                status, and receive updates about your visits
                in one place.
            </p>

        </section>

        <section class="dashboard-grid">

            <!-- Book an appointment -->

            <article class="dashboard-card">

                <h2>📅 Book an Appointment</h2>

                <p>
                    Choose a hospital, department, and doctor
                    to schedule your hospital visit.
                </p>

                <a
                    href="book_appointment.php"
                    class="dashboard-button"
                >
                    Book Appointment
                </a>

            </article>

            <!-- Queue status -->

            <article class="dashboard-card">

                <h2>🎫 My Queue</h2>

                <p>
                    Check your queue number, appointment status,
                    and estimated number of patients ahead of you.
                </p>

                <a
                    href="queue_status.php"
                    class="dashboard-button"
                >
                    View My Queue
                </a>

            </article>

            <!-- Notifications -->

            <article class="dashboard-card">

                <h2>
                    🔔 Notifications

                    <?php if ($unreadNotifications > 0): ?>

                        <span class="notification-badge">
                            <?= $unreadNotifications ?>
                            unread
                        </span>

                    <?php endif; ?>

                </h2>

                <p>
                    View updates about your appointments
                    and queue. You can also mark notifications
                    as read.
                </p>

                <a
                    href="notifications.php"
                    class="dashboard-button"
                >
                    View Notifications
                </a>

            </article>

            <!-- My appointments -->

            <article class="dashboard-card">

                <h2>📋 My Appointments</h2>

                <p>
                    View your bookings, appointment details,
                    and queue information in one place.
                </p>

                <a
                    href="my_appointments.php"
                    class="dashboard-button"
                >
                    View My Appointments
                </a>

            </article>

        </section>

    </div>

</main>

<footer class="footer">

    <div class="container">

        <p>
            &copy; <?= date("Y") ?> SmartQueue
        </p>

    </div>

</footer>

</body>
</html>

