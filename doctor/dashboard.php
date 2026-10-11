<?php

session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "doctor"
) {
    header("Location: ../auth/login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . '/../includes/hospital_access_check.php';
$userId = (int) $_SESSION["user_id"];

/**
 * Safely escape output before displaying it in HTML.
 */
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

/**
 * Get the doctor's profile and hospital information.
 */
$doctorStmt = $pdo->prepare(
    "SELECT
        d.doctor_id,
        d.hospital_id,
        d.department_id,
        d.specialization,
        d.availability_status,
        u.full_name,
        u.account_status,
        h.hospital_name,
        h.status AS hospital_status,
        dep.department_name
     FROM doctors d
     INNER JOIN users u
        ON d.user_id = u.user_id
     INNER JOIN hospitals h
        ON d.hospital_id = h.hospital_id
     INNER JOIN departments dep
        ON d.department_id = dep.department_id
     WHERE d.user_id = ?
     LIMIT 1"
);

$doctorStmt->execute([$userId]);

$doctor = $doctorStmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    http_response_code(403);

    exit(
        "Your doctor profile could not be found. " .
        "Please contact your hospital administrator."
    );
}

if (
    $doctor["account_status"] !== "active" ||
    $doctor["hospital_status"] !== "active"
) {
    http_response_code(403);

    exit(
        "Your account or hospital is not active. " .
        "Please contact your hospital administrator."
    );
}

$doctorId = (int) $doctor["doctor_id"];
$hospitalId = (int) $doctor["hospital_id"];

/**
 * Count today's appointments assigned to this doctor.
 */
$todayStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM appointments
     WHERE doctor_id = ?
       AND hospital_id = ?
       AND appointment_date = CURDATE()
       AND status NOT IN ('cancelled', 'no_show')"
);

$todayStmt->execute([
    $doctorId,
    $hospitalId
]);

$todayAppointments = (int) $todayStmt->fetchColumn();

/**
 * Count patients waiting in this doctor's queue.
 */
$waitingStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM appointments a
     INNER JOIN queues q
        ON a.appointment_id = q.appointment_id
     WHERE a.doctor_id = ?
       AND a.hospital_id = ?
       AND a.appointment_date = CURDATE()
       AND a.status NOT IN ('cancelled', 'no_show')
       AND q.status = 'waiting'
       AND q.queue_date = CURDATE()"
);

$waitingStmt->execute([
    $doctorId,
    $hospitalId
]);

$waitingPatients = (int) $waitingStmt->fetchColumn();

/**
 * Count completed appointments today.
 */
$completedStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM appointments
     WHERE doctor_id = ?
       AND hospital_id = ?
       AND appointment_date = CURDATE()
       AND status = 'completed'"
);

$completedStmt->execute([
    $doctorId,
    $hospitalId
]);

$completedAppointments = (int) $completedStmt->fetchColumn();

/**
 * Retrieve today's appointments.
 */
$appointmentsStmt = $pdo->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.reason_for_visit,
        a.status AS appointment_status,
        p.patient_id,
        patient_user.full_name AS patient_name,
        q.queue_number,
        q.queue_label,
        q.status AS queue_status
     FROM appointments a
     INNER JOIN patients p
        ON a.patient_id = p.patient_id
     INNER JOIN users patient_user
        ON p.user_id = patient_user.user_id
     LEFT JOIN queues q
        ON a.appointment_id = q.appointment_id
     WHERE a.doctor_id = ?
       AND a.hospital_id = ?
       AND a.appointment_date = CURDATE()
     ORDER BY
        CASE
            WHEN q.status = 'called' THEN 1
            WHEN q.status = 'in_progress' THEN 2
            WHEN q.status = 'waiting' THEN 3
            ELSE 4
        END,
        q.queue_number ASC,
        a.appointment_time ASC"
);

$appointmentsStmt->execute([
    $doctorId,
    $hospitalId
]);

$appointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Doctor Dashboard | SmartQueue</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .doctor-dashboard {
            padding: 40px 0;
        }

        .welcome-card {
            background: white;
            padding: 28px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .welcome-card h1,
        .welcome-card h2 {
            color: #083344;
            margin-bottom: 12px;
        }

        .welcome-card p {
            color: #64748b;
            line-height: 1.6;
        }

        .doctor-details {
            margin-top: 15px;
            display: grid;
            gap: 8px;
        }

        .doctor-details p {
            margin: 0;
        }

        .statistics-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .stat-card h2 {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .stat-number {
            color: #0e7490;
            font-size: 32px;
            font-weight: bold;
        }

        /* Patient queue management section */
        .queue-management-card {
            background: linear-gradient(135deg, #083344, #0e7490);
            color: white;
            padding: 28px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            flex-wrap: wrap;
        }

        .queue-management-card h2 {
            color: white;
            margin-bottom: 10px;
        }

        .queue-management-card p {
            color: #e0f2fe;
            line-height: 1.6;
            margin: 0;
            max-width: 600px;
        }

        .queue-button {
            display: inline-block;
            padding: 13px 20px;
            background: white;
            color: #083344;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            white-space: nowrap;
            transition: background 0.2s ease;
        }

        .queue-button:hover {
            background: #cffafe;
        }

        .appointments-section {
            background: white;
            padding: 25px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .appointments-section h2 {
            color: #083344;
            margin-bottom: 20px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th,
        td {
            padding: 13px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f8fafc;
            color: #164e63;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
            background: #e2e8f0;
            color: #334155;
        }

        .status-waiting,
        .status-booked {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-called,
        .status-in_progress,
        .status-checked_in,
        .status-almost_over {
            background: #fef3c7;
            color: #92400e;
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

        .status-almost_over {
            background: #ffedd5;
            color: #9a3412;
        }

        .empty-state {
            padding: 25px;
            text-align: center;
            color: #64748b;
            background: #f8fafc;
            border-radius: 8px;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #164e63;
        }

        @media (max-width: 768px) {
            .doctor-dashboard {
                padding: 25px 0;
            }

            .statistics-grid {
                grid-template-columns: 1fr;
            }

            .queue-management-card {
                align-items: stretch;
                flex-direction: column;
            }

            .queue-button {
                width: 100%;
                box-sizing: border-box;
            }

            .appointments-section {
                padding: 18px;
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
                Dr. <?= e($doctor["full_name"]) ?>
            </span>

            <a href="manage_queue.php">
                Patient Queue
            </a>

            <a href="../auth/logout.php">
                Log Out
            </a>
        </nav>

    </div>
</header>

<main class="doctor-dashboard">

    <div class="container">

        <!-- Doctor profile and welcome section -->
        <section class="welcome-card">

            <h1>
                Welcome, Dr. <?= e($doctor["full_name"]) ?>!
            </h1>

            <p>
                Welcome to your SmartQueue doctor dashboard.
                Review your appointments, monitor your patients,
                and manage your consultation queue from here.
            </p>

            <div class="doctor-details">

                <p>
                    <strong>Hospital:</strong>
                    <?= e($doctor["hospital_name"]) ?>
                </p>

                <p>
                    <strong>Department:</strong>
                    <?= e($doctor["department_name"]) ?>
                </p>

                <p>
                    <strong>Specialization:</strong>
                    <?= e(
                        $doctor["specialization"] ?: "Not specified"
                    ) ?>
                </p>

                <p>
                    <strong>Availability:</strong>
                    <?= e(
                        ucfirst($doctor["availability_status"])
                    ) ?>
                </p>

            </div>

        </section>

        <!-- Dashboard statistics -->
        <section class="statistics-grid">

            <article class="stat-card">
                <h2>Today's Appointments</h2>

                <div class="stat-number">
                    <?= $todayAppointments ?>
                </div>
            </article>

            <article class="stat-card">
                <h2>Patients Waiting</h2>

                <div class="stat-number">
                    <?= $waitingPatients ?>
                </div>
            </article>

            <article class="stat-card">
                <h2>Completed Today</h2>

                <div class="stat-number">
                    <?= $completedAppointments ?>
                </div>
            </article>

        </section>

        <!-- Queue management: always visible -->
        <section class="queue-management-card">

            <div>
                <h2>🎫 Manage Patient Queue</h2>

                <p>
                    View today's patient queue, call the next eligible
                    patient, start consultations, and update queue
                    statuses.
                </p>
            </div>

            <a
                href="manage_queue.php"
                class="queue-button"
            >
                Open Patient Queue →
            </a>

        </section>

        <!-- Today's appointments -->
        <section class="appointments-section">

            <h2>Today's Appointments 📋</h2>

            <?php if (empty($appointments)): ?>

                <div class="empty-state">

                    <h3>No appointments scheduled for today</h3>

                    <p>
                        Appointments assigned to you for today's date
                        will appear here.
                    </p>

                </div>

            <?php else: ?>

                <div class="table-wrapper">

                    <table>

                        <thead>
                            <tr>
                                <th>Queue No.</th>
                                <th>Patient</th>
                                <th>Appointment Time</th>
                                <th>Reason for Visit</th>
                                <th>Appointment Status</th>
                                <th>Queue Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($appointments as $appointment): ?>

                                <tr>

                                    <td>
                                        <?= e(
                                            $appointment["queue_label"]
                                            ?: $appointment["queue_number"]
                                            ?: "Not assigned"
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e($appointment["patient_name"]) ?>
                                    </td>

                                    <td>
                                        <?= e($appointment["appointment_time"]) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $appointment["reason_for_visit"]
                                            ?: "Not provided"
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="status status-<?= e(
                                            $appointment["appointment_status"]
                                        ) ?>">
                                            <?= e(
                                                str_replace(
                                                    "_",
                                                    " ",
                                                    $appointment["appointment_status"]
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>

                                        <?php if ($appointment["queue_status"]): ?>

                                            <span class="status status-<?= e(
                                                $appointment["queue_status"]
                                            ) ?>">
                                                <?= e(
                                                    str_replace(
                                                        "_",
                                                        " ",
                                                        $appointment["queue_status"]
                                                    )
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            Not assigned

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

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