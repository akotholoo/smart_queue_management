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

$userId = (int) $_SESSION["user_id"];

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

/*
|--------------------------------------------------------------------------
| Find the logged-in patient's profile
|--------------------------------------------------------------------------
*/

$patientStmt = $pdo->prepare(
    "SELECT patient_id
     FROM patients
     WHERE user_id = ?
     LIMIT 1"
);

$patientStmt->execute([$userId]);
$patient = $patientStmt->fetch();

if (!$patient) {
    http_response_code(403);
    exit("Your patient profile could not be found.");
}

$patientId = (int) $patient["patient_id"];

/*
|--------------------------------------------------------------------------
| Process appointment cancellation
|--------------------------------------------------------------------------
*/

$message = $_SESSION["appointment_message"] ?? "";
unset($_SESSION["appointment_message"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $appointmentId = filter_input(
        INPUT_POST,
        "appointment_id",
        FILTER_VALIDATE_INT
    );

    if (!$appointmentId || $appointmentId < 1) {

        $_SESSION["appointment_message"] =
            "Invalid appointment selected.";

    } else {

        /*
         * Only allow cancellation of this patient's own appointment.
         * Do not allow cancellation after the consultation has started
         * or after the appointment has reached a final state.
         */

        $pdo->beginTransaction();

        $appointmentStmt = $pdo->prepare(
            "SELECT appointment_id, hospital_id, status
             FROM appointments
             WHERE appointment_id = ?
               AND patient_id = ?
             FOR UPDATE"
        );

        $appointmentStmt->execute([
            $appointmentId,
            $patientId
        ]);

        $appointment = $appointmentStmt->fetch();

        if (!$appointment) {

            $pdo->rollBack();

            $_SESSION["appointment_message"] =
                "Appointment not found.";

        } elseif (
            !in_array(
                $appointment["status"],
                ["booked", "checked_in", "waiting"],
                true
            )
        ) {

            $pdo->rollBack();

            $_SESSION["appointment_message"] =
                "This appointment can no longer be cancelled.";

        } else {

            /*
             * Cancel the appointment.
             */

            $cancelStmt = $pdo->prepare(
                "UPDATE appointments
                 SET status = 'cancelled'
                 WHERE appointment_id = ?
                   AND patient_id = ?"
            );

            $cancelStmt->execute([
                $appointmentId,
                $patientId
            ]);

            /*
             * Cancel its queue entry, if one exists.
             */

            $queueStmt = $pdo->prepare(
                "UPDATE queues
                 SET status = 'cancelled'
                 WHERE appointment_id = ?
                   AND status IN ('waiting', 'called')"
            );

            $queueStmt->execute([$appointmentId]);

            /*
             * Add an in-app notification.
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
                ) VALUES (?, ?, ?, ?, ?, ?, 0)"
            );

            $notificationStmt->execute([
                $userId,
                $appointment["hospital_id"],
                $appointmentId,
                "Appointment cancelled",
                "Your appointment has been cancelled successfully.",
                "appointment"
            ]);

            $pdo->commit();

            $_SESSION["appointment_message"] =
                "Your appointment has been cancelled successfully.";
        }
    }

    header("Location: my_appointments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Retrieve this patient's appointments
|--------------------------------------------------------------------------
*/

$appointmentsStmt = $pdo->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.reason_for_visit,
        a.status AS appointment_status,

        h.hospital_name,

        d.department_name,

        doctor_user.full_name AS doctor_name,

        q.queue_number,
        q.queue_label,
        q.status AS queue_status

     FROM appointments a

     INNER JOIN hospitals h
        ON a.hospital_id = h.hospital_id

     INNER JOIN departments d
        ON a.department_id = d.department_id

     INNER JOIN doctors doc
        ON a.doctor_id = doc.doctor_id

     INNER JOIN users doctor_user
        ON doc.user_id = doctor_user.user_id

     LEFT JOIN queues q
        ON a.appointment_id = q.appointment_id

     WHERE a.patient_id = ?

     ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC,
        a.appointment_id DESC"
);

$appointmentsStmt->execute([$patientId]);

$appointments = $appointmentsStmt->fetchAll();

?>

<!DOCTYPE html>

<html lang="en">

<head>

```
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>My Appointments | SmartQueue</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

<style>

    .appointments-page {
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

    .appointments-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
    }

    .appointment-card {
        background: white;
        padding: 24px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }

    .appointment-card h2 {
        color: #164e63;
        font-size: 19px;
        margin-bottom: 15px;
    }

    .appointment-details {
        margin-bottom: 18px;
    }

    .appointment-details p {
        margin-bottom: 10px;
        color: #475569;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 20px;
        background: #e2e8f0;
        color: #334155;
        font-size: 12px;
        font-weight: bold;
        text-transform: capitalize;
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
        background: #f3e8ff;
        color: #6b21a8;
    }

    .cancel-button {
        border: none;
        border-radius: 7px;
        padding: 10px 15px;
        background: #dc2626;
        color: white;
        cursor: pointer;
        font-weight: 600;
    }

    .cancel-button:hover {
        background: #b91c1c;
    }

    .back-link {
        display: inline-block;
        margin-bottom: 20px;
        color: #0e7490;
        text-decoration: none;
        font-weight: 600;
    }

    .empty-state {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
    }

    .empty-state p {
        color: #64748b;
        margin: 12px 0 20px;
    }

    .dashboard-button {
        display: inline-block;
        padding: 10px 16px;
        background: #0e7490;
        color: white;
        text-decoration: none;
        border-radius: 7px;
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .appointments-list {
            grid-template-columns: 1fr;
        }
    }

</style>
```

</head>

<body>

```
<header class="navbar">

    <div class="container navbar-content">

        <a href="../index.php" class="logo">
            SmartQueue
        </a>

        <nav class="nav-links">

            <a href="dashboard.php">
                Dashboard
            </a>

            <a href="../auth/logout.php">
                Log Out
            </a>

        </nav>

    </div>

</header>

<main class="appointments-page">

    <div class="container">

        <a href="dashboard.php" class="back-link">
            ← Back to Dashboard
        </a>

        <section class="page-heading">

            <h1>My Appointments 📅</h1>

            <p>
                View your hospital bookings, appointment
                details, and queue information.
            </p>

        </section>

        <?php if ($message !== ""): ?>

            <div class="message">
                <?= e($message) ?>
            </div>

        <?php endif; ?>

        <?php if (empty($appointments)): ?>

            <section class="empty-state">

                <h2>No appointments yet</h2>

                <p>
                    You have not booked any appointments.
                    Book one to see your details here.
                </p>

                <a
                    href="book_appointment.php"
                    class="dashboard-button"
                >
                    Book an Appointment
                </a>

            </section>

        <?php else: ?>

            <section class="appointments-list">

                <?php foreach ($appointments as $appointment): ?>

                    <?php

                    $appointmentStatus =
                        $appointment["appointment_status"];

                    $canCancel = in_array(
                        $appointmentStatus,
                        ["booked", "checked_in", "waiting"],
                        true
                    );

                    ?>

                    <article class="appointment-card">

                        <h2>
                            <?= e($appointment["hospital_name"]) ?>
                        </h2>

                        <div class="appointment-details">

                            <p>
                                <strong>Department:</strong>
                                <?= e($appointment["department_name"]) ?>
                            </p>

                            <p>
                                <strong>Doctor:</strong>
                                <?= e($appointment["doctor_name"]) ?>
                            </p>

                            <p>
                                <strong>Date:</strong>
                                <?= e($appointment["appointment_date"]) ?>
                            </p>

                            <p>
                                <strong>Time:</strong>
                                <?= e($appointment["appointment_time"]) ?>
                            </p>

                            <p>
                                <strong>Reason for visit:</strong>
                                <?= e($appointment["reason_for_visit"] ?: "Not provided") ?>
                            </p>

                            <p>
                                <strong>Appointment status:</strong>

                                <span class="status status-<?= e($appointmentStatus) ?>">
                                    <?= e(str_replace("_", " ", $appointmentStatus)) ?>
                                </span>
                            </p>

                            <p>
                                <strong>Queue number:</strong>

                                <?php if (!empty($appointment["queue_label"])): ?>

                                    <?= e($appointment["queue_label"]) ?>

                                <?php elseif ($appointment["queue_number"] !== null): ?>

                                    <?= e($appointment["queue_number"]) ?>

                                <?php else: ?>

                                    Not assigned

                                <?php endif; ?>

                            </p>

                            <?php if (!empty($appointment["queue_status"])): ?>

                                <p>
                                    <strong>Queue status:</strong>

                                    <span class="status status-<?= e($appointment["queue_status"]) ?>">
                                        <?= e(str_replace("_", " ", $appointment["queue_status"])) ?>
                                    </span>
                                </p>

                            <?php endif; ?>

                        </div>

                        <?php if ($canCancel): ?>

                            <form
                                method="POST"
                                action="my_appointments.php"
                                onsubmit="return confirm('Are you sure you want to cancel this appointment?');"
                            >

                                <input
                                    type="hidden"
                                    name="appointment_id"
                                    value="<?= (int) $appointment["appointment_id"] ?>"
                                >

                                <button
                                    type="submit"
                                    class="cancel-button"
                                >
                                    Cancel Appointment
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

        <p>
            &copy; <?= date("Y") ?> SmartQueue
        </p>

    </div>

</footer>
```

</body>

</html>
