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
require_once __DIR__ . "/../includes/hospital_access_check.php";

$userId = (int) $_SESSION["user_id"];
$today = date("Y-m-d");

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
}

/*
|--------------------------------------------------------------------------
| Verify doctor and hospital
|--------------------------------------------------------------------------
*/

$doctorStmt = $pdo->prepare(
    "SELECT
        d.doctor_id,
        d.hospital_id,
        d.department_id,
        u.account_status,
        h.status AS hospital_status
     FROM doctors d
     INNER JOIN users u ON d.user_id = u.user_id
     INNER JOIN hospitals h ON d.hospital_id = h.hospital_id
     WHERE d.user_id = ?
     LIMIT 1"
);

$doctorStmt->execute([$userId]);
$doctor = $doctorStmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    http_response_code(403);
    exit("Your doctor profile could not be found.");
}

if (
    $doctor["account_status"] !== "active" ||
    $doctor["hospital_status"] !== "active"
) {
    http_response_code(403);
    exit("Your account or hospital is not active.");
}

$doctorId = (int) $doctor["doctor_id"];
$hospitalId = (int) $doctor["hospital_id"];
$departmentId = (int) $doctor["department_id"];

/*
|--------------------------------------------------------------------------
| Handle queue actions
|--------------------------------------------------------------------------
*/

$message = $_SESSION["queue_message"] ?? "";
unset($_SESSION["queue_message"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $appointmentId = filter_input(
        INPUT_POST,
        "appointment_id",
        FILTER_VALIDATE_INT
    );

    $action = $_POST["action"] ?? "";

    $allowedActions = [
        "call",
        "start",
        "almost_over",
        "complete",
        "absent"
    ];

    if (
        !$appointmentId ||
        $appointmentId < 1 ||
        !in_array($action, $allowedActions, true)
    ) {
        $_SESSION["queue_message"] = "Invalid queue action.";
        header("Location: manage_queue.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Lock selected appointment
        |--------------------------------------------------------------------------
        */

        $appointmentStmt = $pdo->prepare(
            "SELECT
                a.appointment_id,
                a.patient_id,
                a.status AS appointment_status,
                a.appointment_date,
                a.hospital_id,
                a.department_id,
                p.user_id AS patient_user_id,
                q.queue_id,
                q.queue_number,
                q.queue_date,
                q.status AS queue_status
             FROM appointments a
             INNER JOIN patients p ON a.patient_id = p.patient_id
             INNER JOIN queues q ON a.appointment_id = q.appointment_id
             WHERE a.appointment_id = ?
               AND a.doctor_id = ?
               AND a.hospital_id = ?
               AND a.department_id = ?
             FOR UPDATE"
        );

        $appointmentStmt->execute([
            $appointmentId,
            $doctorId,
            $hospitalId,
            $departmentId
        ]);

        $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$appointment) {
            throw new RuntimeException(
                "Appointment not found or you are not authorized to manage it."
            );
        }

        if (
            $appointment["appointment_date"] !== $today ||
            $appointment["queue_date"] !== $today
        ) {
            throw new RuntimeException(
                "You can only manage queue entries scheduled for today."
            );
        }

        $currentQueueStatus = $appointment["queue_status"];
        $currentAppointmentStatus = $appointment["appointment_status"];

        $newQueueStatus = "";
        $newAppointmentStatus = "";
        $notificationTitle = "";
        $notificationMessage = "";

        /*
        |--------------------------------------------------------------------------
        | Enforce FIFO when calling a patient
        |--------------------------------------------------------------------------
        */

        if ($action === "call") {
            $nextPatientStmt = $pdo->prepare(
                "SELECT a.appointment_id
                 FROM appointments a
                 INNER JOIN queues q ON a.appointment_id = q.appointment_id
                 WHERE a.doctor_id = ?
                   AND a.hospital_id = ?
                   AND a.department_id = ?
                   AND a.appointment_date = ?
                   AND q.queue_date = ?
                   AND a.status = 'booked'
                   AND q.status = 'waiting'
                 ORDER BY
                    q.queue_number ASC,
                    a.appointment_time ASC,
                    a.appointment_id ASC
                 LIMIT 1
                 FOR UPDATE"
            );

            $nextPatientStmt->execute([
                $doctorId,
                $hospitalId,
                $departmentId,
                $today,
                $today
            ]);

            $nextPatient = $nextPatientStmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$nextPatient ||
                (int) $nextPatient["appointment_id"] !== $appointmentId
            ) {
                throw new RuntimeException(
                    "Please call the next eligible patient in queue first."
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent overlapping consultations
        |--------------------------------------------------------------------------
        */

        if ($action === "start") {
            $activeConsultationStmt = $pdo->prepare(
                "SELECT appointment_id
                 FROM appointments
                 WHERE doctor_id = ?
                   AND hospital_id = ?
                   AND department_id = ?
                   AND appointment_date = ?
                   AND status IN ('in_progress', 'almost_over')
                   AND appointment_id <> ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $activeConsultationStmt->execute([
                $doctorId,
                $hospitalId,
                $departmentId,
                $today,
                $appointmentId
            ]);

            if ($activeConsultationStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException(
                    "Please complete the current consultation before starting another."
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Define permitted status transitions
        |--------------------------------------------------------------------------
        */

        if (
            $action === "call" &&
            $currentQueueStatus === "waiting" &&
            $currentAppointmentStatus === "booked"
        ) {
            $newQueueStatus = "called";
            $newAppointmentStatus = "checked_in";
            $notificationTitle = "Your turn is approaching";
            $notificationMessage =
                "The doctor has called you. Please prepare to be seen.";

        } elseif (
            $action === "start" &&
            $currentQueueStatus === "called" &&
            $currentAppointmentStatus === "checked_in"
        ) {
            $newQueueStatus = "in_progress";
            $newAppointmentStatus = "in_progress";
            $notificationTitle = "Consultation started";
            $notificationMessage =
                "Your consultation has started.";

        } elseif (
            $action === "almost_over" &&
            $currentQueueStatus === "in_progress" &&
            $currentAppointmentStatus === "in_progress"
        ) {
            $newQueueStatus = "almost_over";
            $newAppointmentStatus = "almost_over";
            $notificationTitle = "Consultation nearly complete";
            $notificationMessage =
                "Your consultation is nearly finished.";

        } elseif (
            $action === "complete" &&
            in_array(
                $currentQueueStatus,
                ["in_progress", "almost_over"],
                true
            ) &&
            in_array(
                $currentAppointmentStatus,
                ["in_progress", "almost_over"],
                true
            )
        ) {
            $newQueueStatus = "completed";
            $newAppointmentStatus = "completed";
            $notificationTitle = "Consultation completed";
            $notificationMessage =
                "Your consultation has been marked as completed.";

        } elseif (
            $action === "absent" &&
            in_array(
                $currentQueueStatus,
                ["waiting", "called"],
                true
            ) &&
            in_array(
                $currentAppointmentStatus,
                ["booked", "checked_in", "waiting"],
                true
            )
        ) {
            $newQueueStatus = "absent";
            $newAppointmentStatus = "no_show";
            $notificationTitle = "Appointment marked as absent";
            $notificationMessage =
                "Your appointment has been marked as absent. Contact the hospital if you believe this is incorrect.";
        }

        if ($newQueueStatus === "") {
            throw new RuntimeException(
                "This action is not allowed for the patient's current status."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update queue and appointment
        |--------------------------------------------------------------------------
        */

        $queueUpdateStmt = $pdo->prepare(
            "UPDATE queues
             SET status = ?
             WHERE queue_id = ?
               AND hospital_id = ?
               AND department_id = ?"
        );

        $queueUpdateStmt->execute([
            $newQueueStatus,
            $appointment["queue_id"],
            $hospitalId,
            $departmentId
        ]);

        $appointmentUpdateStmt = $pdo->prepare(
            "UPDATE appointments
             SET status = ?
             WHERE appointment_id = ?
               AND doctor_id = ?
               AND hospital_id = ?
               AND department_id = ?"
        );

        $appointmentUpdateStmt->execute([
            $newAppointmentStatus,
            $appointmentId,
            $doctorId,
            $hospitalId,
            $departmentId
        ]);

        /*
        |--------------------------------------------------------------------------
        | Notify current patient
        |--------------------------------------------------------------------------
        */

        $notificationStmt = $pdo->prepare(
            "INSERT INTO notifications (
                user_id,
                hospital_id,
                appointment_id,
                title,
                message,
                notification_type,
                is_read
             ) VALUES (?, ?, ?, ?, ?, 'queue', 0)"
        );

        $notificationStmt->execute([
            $appointment["patient_user_id"],
            $hospitalId,
            $appointmentId,
            $notificationTitle,
            $notificationMessage
        ]);

        /*
        |--------------------------------------------------------------------------
        | Notify next eligible patient when consultation is almost over
        |--------------------------------------------------------------------------
        */

        if ($action === "almost_over") {
            $nextPrepareStmt = $pdo->prepare(
                "SELECT
                    a.appointment_id,
                    p.user_id AS patient_user_id,
                    q.queue_number
                 FROM appointments a
                 INNER JOIN patients p ON a.patient_id = p.patient_id
                 INNER JOIN queues q ON a.appointment_id = q.appointment_id
                 WHERE a.doctor_id = ?
                   AND a.hospital_id = ?
                   AND a.department_id = ?
                   AND a.appointment_date = ?
                   AND q.queue_date = ?
                   AND a.status = 'booked'
                   AND q.status = 'waiting'
                 ORDER BY
                    q.queue_number ASC,
                    a.appointment_time ASC,
                    a.appointment_id ASC
                 LIMIT 1
                 FOR UPDATE"
            );

            $nextPrepareStmt->execute([
                $doctorId,
                $hospitalId,
                $departmentId,
                $today,
                $today
            ]);

            $nextPatient = $nextPrepareStmt->fetch(PDO::FETCH_ASSOC);

            if ($nextPatient) {
                $nextNotificationStmt = $pdo->prepare(
                    "INSERT INTO notifications (
                        user_id,
                        hospital_id,
                        appointment_id,
                        title,
                        message,
                        notification_type,
                        is_read
                     ) VALUES (?, ?, ?, ?, ?, 'queue', 0)"
                );

                $nextNotificationStmt->execute([
                    $nextPatient["patient_user_id"],
                    $hospitalId,
                    $nextPatient["appointment_id"],
                    "Please prepare for your turn",
                    "The current consultation is nearly finished. Please prepare for your turn. Wait until the doctor calls you before entering."
                ]);
            }
        }

        $pdo->commit();

        $_SESSION["queue_message"] =
            $action === "almost_over"
                ? "Consultation marked as almost over. Notifications sent where applicable."
                : "Queue updated successfully. The patient has been notified.";

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["queue_message"] = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : "An unexpected error occurred while updating the queue.";

        if (!($exception instanceof RuntimeException)) {
            error_log($exception->getMessage());
        }
    }

    header("Location: manage_queue.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Retrieve today's queue
|--------------------------------------------------------------------------
*/

$queueStmt = $pdo->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_time,
        a.appointment_date,
        a.reason_for_visit,
        a.status AS appointment_status,
        patient_user.full_name AS patient_name,
        q.queue_number,
        q.queue_label,
        q.status AS queue_status
     FROM appointments a
     INNER JOIN patients p ON a.patient_id = p.patient_id
     INNER JOIN users patient_user ON p.user_id = patient_user.user_id
     INNER JOIN queues q ON a.appointment_id = q.appointment_id
     WHERE a.doctor_id = ?
       AND a.hospital_id = ?
       AND a.department_id = ?
       AND a.appointment_date = ?
       AND q.queue_date = ?
     ORDER BY
        CASE q.status
            WHEN 'in_progress' THEN 1
            WHEN 'almost_over' THEN 1
            WHEN 'called' THEN 2
            WHEN 'waiting' THEN 3
            ELSE 4
        END,
        q.queue_number ASC,
        a.appointment_time ASC"
);

$queueStmt->execute([
    $doctorId,
    $hospitalId,
    $departmentId,
    $today,
    $today
]);

$queue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Check active consultation
|--------------------------------------------------------------------------
*/

$activeConsultationStmt = $pdo->prepare(
    "SELECT appointment_id
     FROM appointments
     WHERE doctor_id = ?
       AND hospital_id = ?
       AND department_id = ?
       AND appointment_date = ?
       AND status IN ('in_progress', 'almost_over')
     LIMIT 1"
);

$activeConsultationStmt->execute([
    $doctorId,
    $hospitalId,
    $departmentId,
    $today
]);

$hasActiveConsultation = (bool) $activeConsultationStmt->fetch(
    PDO::FETCH_ASSOC
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Queue | SmartQueue</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .queue-page {
            padding: 40px 0;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #083344;
            margin-bottom: 10px;
        }

        .page-heading p {
            color: #64748b;
        }

        .message {
            background: #dcfce7;
            color: #166534;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .queue-list {
            display: grid;
            gap: 18px;
        }

        .queue-card {
            background: white;
            padding: 24px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .queue-card h2 {
            color: #164e63;
            margin-bottom: 14px;
        }

        .queue-details p {
            color: #475569;
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
            background: #e2e8f0;
            color: #334155;
        }

        .status-waiting {
            background: #dbeafe;
            color: #1d4ed8;
        }

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

        .status-absent,
        .status-cancelled,
        .status-no_show {
            background: #fee2e2;
            color: #991b1b;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .action-button {
            border: none;
            border-radius: 7px;
            padding: 10px 15px;
            color: white;
            cursor: pointer;
            font-weight: 600;
        }

        .call-button {
            background: #0e7490;
        }

        .start-button {
            background: #d97706;
        }

        .almost-over-button {
            background: #ea580c;
        }

        .complete-button {
            background: #15803d;
        }

        .absent-button {
            background: #dc2626;
        }

        .action-button:hover {
            opacity: 0.9;
        }

        .action-button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            opacity: 0.8;
        }

        .queue-notice {
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .empty-state {
            background: white;
            padding: 30px;
            text-align: center;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            color: #64748b;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #0e7490;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>

<body>

<header class="navbar">
    <div class="container navbar-content">
        <a href="../index.php" class="logo">SmartQueue</a>

        <nav class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="../auth/logout.php">Log Out</a>
        </nav>
    </div>
</header>

<main class="queue-page">
    <div class="container">

        <a href="dashboard.php" class="back-link">
            ← Back to Doctor Dashboard
        </a>

        <section class="page-heading">
            <h1>Manage Patient Queue 🎫</h1>
            <p>
                Review today's patients and update their queue status
                as you attend to them.
            </p>
        </section>

        <?php if ($message !== ""): ?>
            <div class="message">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($hasActiveConsultation): ?>
            <div class="queue-notice">
                🩺 You have an active consultation.
                Complete it before starting another patient's consultation.
            </div>
        <?php endif; ?>

        <?php if (empty($queue)): ?>

            <section class="empty-state">
                <h2>No queue entries for today</h2>
                <p>
                    Patients with queue entries assigned to you for today
                    will appear here.
                </p>
            </section>

        <?php else: ?>

            <section class="queue-list">

                <?php foreach ($queue as $item): ?>

                    <?php
                    $queueStatus = $item["queue_status"];
                    $appointmentStatus = $item["appointment_status"];
                    ?>

                    <article class="queue-card">

                        <h2>
                            Queue Number:
                            <?= e(
                                $item["queue_label"]
                                ?: $item["queue_number"]
                            ) ?>
                        </h2>

                        <div class="queue-details">

                            <p>
                                <strong>Patient:</strong>
                                <?= e($item["patient_name"]) ?>
                            </p>

                            <p>
                                <strong>Appointment Time:</strong>
                                <?= e($item["appointment_time"]) ?>
                            </p>

                            <p>
                                <strong>Reason for Visit:</strong>
                                <?= e(
                                    $item["reason_for_visit"]
                                    ?: "Not provided"
                                ) ?>
                            </p>

                            <p>
                                <strong>Appointment Status:</strong>
                                <span class="status status-<?= e($appointmentStatus) ?>">
                                    <?= e(str_replace("_", " ", $appointmentStatus)) ?>
                                </span>
                            </p>

                            <p>
                                <strong>Queue Status:</strong>
                                <span class="status status-<?= e($queueStatus) ?>">
                                    <?= e(str_replace("_", " ", $queueStatus)) ?>
                                </span>
                            </p>

                        </div>

                        <?php if (
                            $queueStatus === "waiting" &&
                            $appointmentStatus === "booked"
                        ): ?>

                            <form
                                method="POST"
                                class="action-buttons"
                                onsubmit="return confirm('Call this patient now?');"
                            >
                                <input
                                    type="hidden"
                                    name="appointment_id"
                                    value="<?= (int) $item["appointment_id"] ?>"
                                >

                                <button
                                    type="submit"
                                    name="action"
                                    value="call"
                                    class="action-button call-button"
                                >
                                    📣 Call Patient
                                </button>

                                <button
                                    type="submit"
                                    name="action"
                                    value="absent"
                                    class="action-button absent-button"
                                    onclick="return confirm('Mark this patient as absent?');"
                                >
                                    Mark Absent
                                </button>
                            </form>

                        <?php elseif (
                            $queueStatus === "called" &&
                            $appointmentStatus === "checked_in"
                        ): ?>

                            <form
                                method="POST"
                                class="action-buttons"
                            >
                                <input
                                    type="hidden"
                                    name="appointment_id"
                                    value="<?= (int) $item["appointment_id"] ?>"
                                >

                                <button
                                    type="submit"
                                    name="action"
                                    value="start"
                                    class="action-button start-button"
                                    <?= $hasActiveConsultation ? "disabled" : "" ?>
                                    onclick="return confirm('Start this consultation?');"
                                >
                                    🩺 Start Consultation
                                </button>

                                <button
                                    type="submit"
                                    name="action"
                                    value="absent"
                                    class="action-button absent-button"
                                    onclick="return confirm('Mark this patient as absent?');"
                                >
                                    Mark Absent
                                </button>
                            </form>

                        <?php elseif (
                            in_array(
                                $queueStatus,
                                ["in_progress", "almost_over"],
                                true
                            ) &&
                            in_array(
                                $appointmentStatus,
                                ["in_progress", "almost_over"],
                                true
                            )
                        ): ?>

                            <form method="POST" class="action-buttons">
                                <input
                                    type="hidden"
                                    name="appointment_id"
                                    value="<?= (int) $item["appointment_id"] ?>"
                                >

                                <?php if (
                                    $queueStatus === "in_progress" &&
                                    $appointmentStatus === "in_progress"
                                ): ?>

                                    <button
                                        type="submit"
                                        name="action"
                                        value="almost_over"
                                        class="action-button almost-over-button"
                                        onclick="return confirm('Mark this consultation as almost over and notify the next eligible patient to prepare?');"
                                    >
                                        ⏳ Almost Over
                                    </button>

                                <?php endif; ?>

                                <button
                                    type="submit"
                                    name="action"
                                    value="complete"
                                    class="action-button complete-button"
                                    onclick="return confirm('Mark this consultation as completed?');"
                                >
                                    ✅ Complete Consultation
                                </button>
                            </form>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </section>

        <?php endif; ?>

    </div>
</main>

<footer class="footer">
    <div class="container">
        <p>&copy; <?= date("Y") ?> SmartQueue</p>
    </div>
</footer>

<script>
    let lastActivity = Date.now();

    document.addEventListener("mousemove", () => {
        lastActivity = Date.now();
    });

    document.addEventListener("keydown", () => {
        lastActivity = Date.now();
    });

    document.addEventListener("click", () => {
        lastActivity = Date.now();
    });

    document.addEventListener("touchstart", () => {
        lastActivity = Date.now();
    });

    setInterval(() => {
        const idleTime = Date.now() - lastActivity;

        if (idleTime >= 15000) {
            window.location.reload();
        }
    }, 15000);
</script>

</body>
</html>