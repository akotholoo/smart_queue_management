<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// Ensure the user is logged in as a patient.
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'patient'
) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Retrieve the patient's patient_id.
$stmt = $pdo->prepare(
    "SELECT patient_id
     FROM patients
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->execute([$userId]);

$patient = $stmt->fetch();

if (!$patient) {
    http_response_code(403);
    exit('Patient profile not found.');
}

$patientId = (int) $patient['patient_id'];

// Retrieve the patient's appointments and queue information.
$stmt = $pdo->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.status AS appointment_status,

        h.hospital_name,
        dep.department_name,

        u.full_name AS doctor_name,

        q.queue_id,
        q.queue_number,
        q.queue_label,
        q.queue_date,
        q.status AS queue_status,

        CASE
            WHEN q.queue_id IS NULL THEN 0

            WHEN q.status <> 'waiting' THEN 0

            ELSE (
                SELECT COUNT(*)
                FROM queues q2

                INNER JOIN appointments a2
                    ON a2.appointment_id = q2.appointment_id

                WHERE q2.hospital_id = q.hospital_id
                  AND q2.department_id = q.department_id
                  AND q2.queue_date = q.queue_date
                  AND q2.queue_number < q.queue_number

                  AND a2.doctor_id = a.doctor_id
                  AND a2.hospital_id = a.hospital_id
                  AND a2.department_id = a.department_id
                  AND a2.appointment_date = a.appointment_date

                  AND q2.status IN (
                      'waiting',
                      'called',
                      'in_progress'
                  )

                  AND a2.status IN (
                      'booked',
                      'checked_in',
                      'waiting',
                      'in_progress'
                  )
            )
        END AS patients_ahead

     FROM appointments a

     INNER JOIN hospitals h
        ON h.hospital_id = a.hospital_id

     INNER JOIN departments dep
        ON dep.department_id = a.department_id
       AND dep.hospital_id = a.hospital_id

     INNER JOIN doctors d
        ON d.doctor_id = a.doctor_id
       AND d.hospital_id = a.hospital_id
       AND d.department_id = a.department_id

     INNER JOIN users u
        ON u.user_id = d.user_id

     LEFT JOIN queues q
        ON q.appointment_id = a.appointment_id

     WHERE a.patient_id = ?

     ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC,
        a.appointment_id DESC"
);

$stmt->execute([$patientId]);

$appointments = $stmt->fetchAll();

// Escape output before displaying it in HTML.
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Convert queue statuses into readable labels.
function queueStatusLabel($status): string
{
    $labels = [
        'waiting' => 'Waiting',
        'called' => 'Your turn has been called',
        'in_progress' => 'Consultation in progress',
        'almost_over' => 'Almost your turn',
        'completed' => 'Completed',
        'absent' => 'Marked absent',
        'cancelled' => 'Cancelled'
    ];

    return $labels[$status] ?? ucfirst((string) $status);
}

// Convert appointment statuses into readable labels.
function appointmentStatusLabel($status): string
{
    return ucwords(
        str_replace(
            '_',
            ' ',
            (string) $status
        )
    );
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

    <title>My Queue Status | Smart Queue Management</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .queue-container {
            max-width: 1100px;
            margin: 35px auto;
            padding: 20px;
        }

        .queue-heading {
            margin-bottom: 25px;
        }

        .queue-card {
            background: #ffffff;
            padding: 24px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
        }

        .queue-number {
            font-size: 30px;
            font-weight: bold;
            margin: 12px 0;
            color: #083344;
            overflow-wrap: anywhere;
        }

        .queue-details {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(220px, 1fr)
            );
            gap: 18px;
            margin-top: 20px;
        }

        .detail-item {
            padding: 14px;
            background: #f6f8fa;
            border-radius: 8px;
            overflow-wrap: anywhere;
        }

        .detail-item strong {
            display: block;
            margin-bottom: 7px;
        }

        .status-badge {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            font-weight: bold;
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-called,
        .status-in_progress,
        .status-almost_over {
            background: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-absent,
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-message {
            background: #ffffff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
        }

        .refresh-note {
            margin-top: 20px;
            color: #555555;
            font-size: 14px;
        }

        .queue-message {
            margin-top: 16px;
            padding: 14px;
            border-radius: 8px;
            background: #ecfeff;
            color: #155e75;
        }

        @media (max-width: 600px) {
            .queue-container {
                margin: 15px auto;
                padding: 12px;
            }

            .queue-card {
                padding: 18px;
            }

            .queue-number {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

<div class="queue-container">

    <a
        href="dashboard.php"
        class="back-link"
    >
        &larr; Back to Patient Dashboard
    </a>

    <div class="queue-heading">

        <h1>My Queue Status</h1>

        <p>
            View your appointments, queue numbers,
            current queue positions and statuses.
        </p>

    </div>

    <?php if (empty($appointments)): ?>

        <div class="empty-message">

            <h2>No appointments found</h2>

            <p>
                You haven't booked any appointments yet.
            </p>

            <a href="book_appointment.php">
                Book an Appointment
            </a>

        </div>

    <?php else: ?>

        <?php foreach ($appointments as $appointment): ?>

            <?php
            $queueId = $appointment['queue_id'];

            $queueStatus = $appointment['queue_status'];

            $appointmentStatus =
                $appointment['appointment_status'];

            $hasQueue = $queueId !== null;

            $activeQueueStatuses = [
                'waiting',
                'called',
                'in_progress'
            ];

            $patientsAhead =
                (int) $appointment['patients_ahead'];

            $statusClass = $hasQueue
                ? 'status-' . $queueStatus
                : '';

            $appointmentIsCancelled =
                $appointmentStatus === 'cancelled';

            $queueIsActive =
                $hasQueue &&
                in_array(
                    $queueStatus,
                    $activeQueueStatuses,
                    true
                ) &&
                !$appointmentIsCancelled;
            ?>

            <div class="queue-card">

                <h2>
                    <?= e($appointment['hospital_name']) ?>
                </h2>

                <p>
                    <strong>Department:</strong>
                    <?= e($appointment['department_name']) ?>
                </p>

                <p>
                    <strong>Doctor:</strong>
                    <?= e($appointment['doctor_name']) ?>
                </p>

                <?php if ($hasQueue): ?>

                    <div class="queue-number">

                        Queue Number:
                        <?= e(
                            $appointment['queue_label']
                            ?: $appointment['queue_number']
                        ) ?>

                    </div>

                    <span class="status-badge <?= e($statusClass) ?>">
                        <?= e(queueStatusLabel($queueStatus)) ?>
                    </span>

                    <div class="queue-details">

                        <div class="detail-item">

                            <strong>Appointment Date</strong>

                            <?= e($appointment['appointment_date']) ?>

                        </div>

                        <div class="detail-item">

                            <strong>Appointment Time</strong>

                            <?= e(
                                substr(
                                    (string) $appointment['appointment_time'],
                                    0,
                                    5
                                )
                            ) ?>

                        </div>

                        <div class="detail-item">

                            <strong>Patients Ahead</strong>

                            <?php if (
                                $queueStatus === 'waiting' &&
                                !$appointmentIsCancelled
                            ): ?>

                                <?= $patientsAhead ?>

                            <?php elseif (
                                $queueStatus === 'called'
                            ): ?>

                                Your turn has arrived.

                            <?php elseif (
                                $queueStatus === 'in_progress'
                            ): ?>

                                Your consultation has started.

                            <?php else: ?>

                                Not applicable

                            <?php endif; ?>

                        </div>

                        <div class="detail-item">

                            <strong>Appointment Status</strong>

                            <?= e(
                                appointmentStatusLabel(
                                    $appointmentStatus
                                )
                            ) ?>

                        </div>

                    </div>

                    <?php if (
                        $queueStatus === 'waiting' &&
                        !$appointmentIsCancelled
                    ): ?>

                        <div class="queue-message">

                            <?php if ($patientsAhead === 0): ?>

                                You are next in your doctor's
                                eligible queue. Please remain ready.

                            <?php elseif ($patientsAhead === 1): ?>

                                There is 1 eligible patient ahead
                                of you. Please wait for your turn.

                            <?php else: ?>

                                There are <?= $patientsAhead ?>
                                eligible patients ahead of you.

                                Please wait for your turn.

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="queue-details">

                        <div class="detail-item">

                            <strong>Appointment Date</strong>

                            <?= e($appointment['appointment_date']) ?>

                        </div>

                        <div class="detail-item">

                            <strong>Appointment Time</strong>

                            <?= e(
                                substr(
                                    (string) $appointment['appointment_time'],
                                    0,
                                    5
                                )
                            ) ?>

                        </div>

                        <div class="detail-item">

                            <strong>Appointment Status</strong>

                            <?= e(
                                appointmentStatusLabel(
                                    $appointmentStatus
                                )
                            ) ?>

                        </div>

                    </div>

                    <p class="queue-message">

                        Queue information is not available
                        for this appointment yet.

                    </p>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

        <p class="refresh-note">
            Queue information refreshes automatically approximately
            every 20 seconds while this page is visible.
        </p>

    <?php endif; ?>

</div>

<script>
    // Refresh the page periodically while it is visible.
    // This lets the patient see queue changes without
    // manually reloading the page.

    setInterval(function () {

        if (document.visibilityState === 'visible') {
            window.location.reload();
        }

    }, 20000);
</script>

</body>
</html>