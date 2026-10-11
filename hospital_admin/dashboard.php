<?php

session_start();

require_once __DIR__ . "/../config/database.php";

/**
 * Only allow logged-in hospital administrators.
 */
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "hospital_admin" ||
    empty($_SESSION["hospital_id"])
) {
    header("Location: ../auth/login.php");
    exit;
}

$hospitalId = (int) $_SESSION["hospital_id"];
$userId = (int) $_SESSION["user_id"];

/**
 * Escape output to prevent HTML injection.
 */
function escape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

/**
 * Confirm that this administrator belongs to an active hospital.
 */
$stmt = $pdo->prepare(
    "SELECT
        hospital_name,
        address,
        phone,
        email,
        status
     FROM hospitals
     WHERE hospital_id = ?
     LIMIT 1"
);

$stmt->execute([$hospitalId]);

$hospital = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hospital || $hospital["status"] !== "active") {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $cookieParams = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $cookieParams["path"],
            $cookieParams["domain"],
            $cookieParams["secure"],
            $cookieParams["httponly"]
        );
    }

    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

/**
 * Count all departments belonging to this hospital.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM departments
     WHERE hospital_id = ?"
);

$stmt->execute([$hospitalId]);

$totalDepartments = (int) $stmt->fetchColumn();

/**
 * Count active departments.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM departments
     WHERE hospital_id = ?
       AND status = 'active'"
);

$stmt->execute([$hospitalId]);

$activeDepartments = (int) $stmt->fetchColumn();

/**
 * Count doctors belonging to this hospital.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM doctors
     WHERE hospital_id = ?"
);

$stmt->execute([$hospitalId]);

$totalDoctors = (int) $stmt->fetchColumn();

/**
 * Count doctors currently marked as available.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM doctors
     WHERE hospital_id = ?
       AND availability_status = 'available'"
);

$stmt->execute([$hospitalId]);

$availableDoctors = (int) $stmt->fetchColumn();

/**
 * Count all appointments belonging to this hospital.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM appointments
     WHERE hospital_id = ?"
);

$stmt->execute([$hospitalId]);

$totalAppointments = (int) $stmt->fetchColumn();

/**
 * Count today's appointments, excluding cancelled and no-show
 * appointments.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM appointments
     WHERE hospital_id = ?
       AND appointment_date = CURDATE()
       AND status NOT IN ('cancelled', 'no_show')"
);

$stmt->execute([$hospitalId]);

$todaysAppointments = (int) $stmt->fetchColumn();

/**
 * Count appointments that are still waiting in today's queue.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM queues q
     INNER JOIN appointments a
        ON q.appointment_id = a.appointment_id
     WHERE q.hospital_id = ?
       AND q.queue_date = CURDATE()
       AND a.appointment_date = CURDATE()
       AND q.status = 'waiting'
       AND a.status NOT IN ('cancelled', 'no_show')"
);

$stmt->execute([$hospitalId]);

$waitingPatients = (int) $stmt->fetchColumn();

/**
 * Count completed appointments today.
 */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM appointments
     WHERE hospital_id = ?
       AND appointment_date = CURDATE()
       AND status = 'completed'"
);

$stmt->execute([$hospitalId]);

$completedToday = (int) $stmt->fetchColumn();

/**
 * Retrieve the ten most recently created appointments.
 */
$stmt = $pdo->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.status,
        patient_user.full_name AS patient_name,
        d.department_name
     FROM appointments a
     INNER JOIN patients p
        ON a.patient_id = p.patient_id
     INNER JOIN users patient_user
        ON p.user_id = patient_user.user_id
     INNER JOIN departments d
        ON a.department_id = d.department_id
     WHERE a.hospital_id = ?
     ORDER BY a.created_at DESC, a.appointment_id DESC
     LIMIT 10"
);

$stmt->execute([$hospitalId]);

$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Format appointment status for display.
 */
function formatStatus($status): string
{
    return ucfirst(
        str_replace("_", " ", (string) $status)
    );
}

/**
 * Return a CSS class corresponding to an appointment status.
 */
function statusClass($status): string
{
    $allowedStatuses = [
        "booked",
        "checked_in",
        "waiting",
        "in_progress",
        "almost_over",
        "completed",
        "cancelled",
        "no_show"
    ];

    if (!in_array($status, $allowedStatuses, true)) {
        return "status-default";
    }

    return "status-" . $status;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Hospital Admin Dashboard | SmartQueue</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        body {
            margin: 0;
            background: #f4f7fa;
            font-family: Arial, sans-serif;
            color: #263238;
        }

        .admin-header {
            background: #075b63;
            color: white;
            padding: 18px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-brand h1 {
            margin: 0;
            font-size: 22px;
        }

        .header-brand p {
            margin: 6px 0 0;
            font-size: 13px;
            color: #d9f2f2;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .header-actions a {
            color: white;
            text-decoration: none;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            border-radius: 6px;
            font-size: 14px;
        }

        .header-actions a:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .welcome-section {
            margin-bottom: 30px;
        }

        .welcome-section h2 {
            margin: 0 0 10px;
            color: #164e63;
            font-size: 27px;
        }

        .welcome-section > p {
            color: #607d8b;
            line-height: 1.6;
        }

        .hospital-info {
            margin-top: 18px;
            padding: 18px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .hospital-info strong {
            color: #075b63;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(200px, 1fr)
            );
            gap: 20px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: white;
            padding: 23px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .stat-card h3 {
            margin: 0 0 15px;
            font-size: 14px;
            font-weight: normal;
            color: #607d8b;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #087f8c;
        }

        .stat-description {
            margin-top: 10px;
            font-size: 12px;
            color: #78909c;
        }

        .section-card {
            background: white;
            padding: 25px;
            margin-bottom: 28px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .section-card h2 {
            margin: 0 0 12px;
            color: #164e63;
            font-size: 21px;
        }

        .section-description {
            color: #607d8b;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .quick-links {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(220px, 1fr)
            );
            gap: 16px;
            margin-top: 22px;
        }

        .quick-link {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 20px;
            border: 1px solid #dce5e8;
            border-radius: 9px;
            background: #f8fbfc;
            color: inherit;
            text-decoration: none;
            transition: background 0.2s ease,
                        border-color 0.2s ease,
                        transform 0.2s ease;
        }

        .quick-link:hover {
            background: #eaf5f6;
            border-color: #075b63;
            transform: translateY(-2px);
        }

        .quick-link:focus-visible {
            outline: 3px solid #087f8c;
            outline-offset: 3px;
        }

        .quick-link h3 {
            margin: 0 0 10px;
            color: #075b63;
            font-size: 17px;
        }

        .quick-link p {
            color: #607d8b;
            font-size: 14px;
            line-height: 1.6;
            margin: 0 0 18px;
        }

        .quick-link-label {
            margin-top: auto;
            color: #087f8c;
            font-size: 12px;
            font-weight: bold;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            min-width: 650px;
        }

        th,
        td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }

        th {
            background: #f0f5f6;
            color: #164e63;
        }

        tbody tr:hover {
            background: #f8fbfc;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .status-booked,
        .status-waiting {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-checked_in,
        .status-called,
        .status-in_progress {
            background: #fef3c7;
            color: #92400e;
        }

        .status-almost_over {
            background: #ffedd5;
            color: #9a3412;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-cancelled,
        .status-absent,
        .status-no_show {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-default {
            background: #e2e8f0;
            color: #334155;
        }

        .empty-message {
            color: #607d8b;
            padding: 20px;
            background: #f8fbfc;
            border-radius: 8px;
            line-height: 1.6;
        }

        .view-all-link {
            display: inline-block;
            margin-top: 18px;
            color: #087f8c;
            text-decoration: none;
            font-weight: bold;
        }

        .view-all-link:hover {
            text-decoration: underline;
        }

        .admin-footer {
            padding: 25px 20px;
            text-align: center;
            color: #607d8b;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            .admin-header {
                align-items: flex-start;
                flex-direction: column;
                padding: 18px 20px;
            }

            .header-actions {
                width: 100%;
            }

            .dashboard-container {
                margin: 25px auto;
                padding: 0 15px;
            }

            .welcome-section h2 {
                font-size: 23px;
            }

            .section-card {
                padding: 18px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

</head>

<body>

<header class="admin-header">

    <div class="header-brand">

        <h1>SmartQueue</h1>

        <p>Hospital Administration Portal</p>

    </div>

    <nav class="header-actions">

        <a href="departments.php">
            Departments
        </a>

        <a href="doctors.php">
            Doctors
        </a>

        <a href="../auth/logout.php">
            Log Out
        </a>

    </nav>

</header>

<main class="dashboard-container">

    <!-- Welcome and hospital information -->
    <section class="welcome-section">

        <h2>
            Welcome,
            <?= escape($_SESSION["full_name"] ?? "Administrator") ?>!
        </h2>

        <p>
            Manage your hospital's departments, doctors,
            appointments, and patient queues from one place.
        </p>

        <div class="hospital-info">

            <strong>
                <?= escape($hospital["hospital_name"]) ?>
            </strong>

            <br>

            <strong>Address:</strong>
            <?= escape($hospital["address"] ?: "Not provided") ?>

            <br>

            <strong>Email:</strong>
            <?= escape($hospital["email"] ?: "Not provided") ?>

            <br>

            <strong>Phone:</strong>
            <?= escape($hospital["phone"] ?: "Not provided") ?>

        </div>

    </section>

    <!-- Hospital statistics -->
    <section class="stats-grid">

        <article class="stat-card">

            <h3>Total Departments</h3>

            <div class="stat-number">
                <?= $totalDepartments ?>
            </div>

            <div class="stat-description">
                <?= $activeDepartments ?> active
            </div>

        </article>

        <article class="stat-card">

            <h3>Total Doctors</h3>

            <div class="stat-number">
                <?= $totalDoctors ?>
            </div>

            <div class="stat-description">
                <?= $availableDoctors ?> marked available
            </div>

        </article>

        <article class="stat-card">

            <h3>Total Appointments</h3>

            <div class="stat-number">
                <?= $totalAppointments ?>
            </div>

            <div class="stat-description">
                All appointment records
            </div>

        </article>

        <article class="stat-card">

            <h3>Today's Appointments</h3>

            <div class="stat-number">
                <?= $todaysAppointments ?>
            </div>

            <div class="stat-description">
                Excludes cancelled and no-show appointments
            </div>

        </article>

        <article class="stat-card">

            <h3>Patients Waiting</h3>

            <div class="stat-number">
                <?= $waitingPatients ?>
            </div>

            <div class="stat-description">
                Waiting in today's queue
            </div>

        </article>

        <article class="stat-card">

            <h3>Completed Today</h3>

            <div class="stat-number">
                <?= $completedToday ?>
            </div>

            <div class="stat-description">
                Completed appointments today
            </div>

        </article>

    </section>

    <!-- Management navigation -->
    <section class="section-card">

        <h2>Hospital Management</h2>

        <p class="section-description">
            Select an area below to manage your hospital.
        </p>

        <div class="quick-links">

            <a
                href="departments.php"
                class="quick-link"
            >

                <h3>🏥 Departments</h3>

                <p>
                    Create departments, update department
                    information, and manage their status.
                </p>

                <span class="quick-link-label">
                    MANAGE DEPARTMENTS →
                </span>

            </a>

            <a
                href="doctors.php"
                class="quick-link"
            >

                <h3>👨‍⚕️ Doctors</h3>

                <p>
                    Add doctors, update their details,
                    assign departments, and manage availability.
                </p>

                <span class="quick-link-label">
                    MANAGE DOCTORS →
                </span>

            </a>

            <a
                href="appointments.php"
                class="quick-link"
            >

                <h3>📅 Appointments</h3>

                <p>
                    View appointments booked at your hospital
                    and monitor their current status.
                </p>

                <span class="quick-link-label">
                    VIEW APPOINTMENTS →
                </span>

            </a>

            <a
                href="queue_management.php"
                class="quick-link"
            >

                <h3>🎫 Queue Management</h3>

                <p>
                    Monitor patients waiting, patients called,
                    and consultations in progress.
                </p>

                <span class="quick-link-label">
                    MONITOR QUEUE →
                </span>

            </a>
        
        <a
            href="patients.php"
            class="quick-link"
        >
            <h3>👥 Patient Management</h3>
            <p>
                View patients registered through appointments
                at your hospital and search their contact details.
            </p>
            <span class="quick-link-label">
                VIEW PATIENTS →
            </span>
        </a>
    <a 
            href="reports.php" 
            class="quick-link"
        >
            <h3>📊 Reports & Statistics</h3>
            <p>
                Generate reports and view statistics
                for your hospital's operations.
            </p>
            <span class="quick-link-label">
                VIEW REPORTS →
            </span>
        </a>
    </div>

    </section>

    <!-- Recent appointments -->
    <section class="section-card">

        <h2>Recent Appointments</h2>

        <p class="section-description">
            The ten most recently created appointments
            associated with your hospital.
        </p>

        <?php if (empty($appointments)): ?>

            <div class="empty-message">

                No appointments have been booked at your hospital yet.

                Once patients book appointments, their records
                will appear here.

            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>
                            <th>Patient</th>
                            <th>Department</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($appointments as $appointment): ?>

                            <tr>

                                <td>
                                    <?= escape($appointment["patient_name"]) ?>
                                </td>

                                <td>
                                    <?= escape($appointment["department_name"]) ?>
                                </td>

                                <td>
                                    <?= escape($appointment["appointment_date"]) ?>
                                </td>

                                <td>
                                    <?= escape($appointment["appointment_time"]) ?>
                                </td>

                                <td>

                                    <span class="status <?= escape(
                                        statusClass($appointment["status"])
                                    ) ?>">

                                        <?= escape(
                                            formatStatus($appointment["status"])
                                        ) ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <a
                href="appointments.php"
                class="view-all-link"
            >
                View All Appointments →
            </a>

        <?php endif; ?>

    </section>

</main>

<footer class="admin-footer">

    <p>
        &copy; <?= date("Y") ?> SmartQueue.
        Hospital Administration Portal.
    </p>

</footer>

</body>

</html>