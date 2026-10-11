```php
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// --------------------------------------------------
// 1. CHECK PATIENT LOGIN
// --------------------------------------------------

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'patient'
) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = '';

// --------------------------------------------------
// 2. GET THE PATIENT ID
// --------------------------------------------------

$stmt = $pdo->prepare(
    "SELECT patient_id
     FROM patients
     WHERE user_id = ?"
);

$stmt->execute([$userId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    exit('Patient profile not found. Please contact the administrator.');
}

$patientId = (int) $patient['patient_id'];

// --------------------------------------------------
// 3. GET SELECTED HOSPITAL AND DEPARTMENT
// --------------------------------------------------

$hospitalId = (int) (
    $_POST['hospital_id']
    ?? $_GET['hospital_id']
    ?? 0
);

$departmentId = (int) (
    $_POST['department_id']
    ?? $_GET['department_id']
    ?? 0
);

$doctorId = (int) ($_POST['doctor_id'] ?? 0);

$appointmentDate = trim(
    $_POST['appointment_date'] ?? ''
);

$appointmentTime = trim(
    $_POST['appointment_time'] ?? ''
);

$reason = trim(
    $_POST['reason_for_visit'] ?? ''
);

// --------------------------------------------------
// 4. LOAD ACTIVE HOSPITALS
// --------------------------------------------------

$stmt = $pdo->query(
    "SELECT hospital_id, hospital_name, address
     FROM hospitals
     WHERE status = 'active'
     ORDER BY hospital_name"
);

$hospitals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 5. LOAD ACTIVE DEPARTMENTS FOR THE SELECTED HOSPITAL
// --------------------------------------------------

$departments = [];

if ($hospitalId > 0) {
    $stmt = $pdo->prepare(
        "SELECT d.department_id, d.department_name
         FROM departments d
         INNER JOIN hospitals h
             ON h.hospital_id = d.hospital_id
         WHERE d.hospital_id = ?
           AND d.status = 'active'
           AND h.status = 'active'
         ORDER BY d.department_name"
    );

    $stmt->execute([$hospitalId]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $validDepartmentIds = array_map(
        'intval',
        array_column($departments, 'department_id')
    );

    if (
        $departmentId > 0 &&
        !in_array($departmentId, $validDepartmentIds, true)
    ) {
        $departmentId = 0;
    }
}

// --------------------------------------------------
// 6. LOAD AVAILABLE DOCTORS
// --------------------------------------------------

$doctors = [];

if ($hospitalId > 0 && $departmentId > 0) {
    $stmt = $pdo->prepare(
        "SELECT
            d.doctor_id,
            d.specialization,
            u.full_name
         FROM doctors d
         INNER JOIN users u
             ON u.user_id = d.user_id
         INNER JOIN departments dep
             ON dep.department_id = d.department_id
             AND dep.hospital_id = d.hospital_id
         INNER JOIN hospitals h
             ON h.hospital_id = d.hospital_id
         WHERE d.hospital_id = ?
           AND d.department_id = ?
           AND d.availability_status = 'available'
           AND u.account_status = 'active'
           AND dep.status = 'active'
           AND h.status = 'active'
         ORDER BY u.full_name"
    );

    $stmt->execute([
        $hospitalId,
        $departmentId
    ]);

    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --------------------------------------------------
// 7. PROCESS APPOINTMENT BOOKING
// --------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['book_appointment'])
) {
    // Validate hospital.

    $stmt = $pdo->prepare(
        "SELECT hospital_id
         FROM hospitals
         WHERE hospital_id = ?
           AND status = 'active'"
    );

    $stmt->execute([$hospitalId]);

    if (!$stmt->fetch()) {
        $errors[] = 'Please select a valid active hospital.';
    }

    // Validate department.

    if ($hospitalId > 0 && $departmentId > 0) {
        $stmt = $pdo->prepare(
            "SELECT dep.department_id
             FROM departments dep
             INNER JOIN hospitals h
                 ON h.hospital_id = dep.hospital_id
             WHERE dep.department_id = ?
               AND dep.hospital_id = ?
               AND dep.status = 'active'
               AND h.status = 'active'"
        );

        $stmt->execute([
            $departmentId,
            $hospitalId
        ]);

        if (!$stmt->fetch()) {
            $errors[] = 'Please select a valid active department.';
        }
    } else {
        $errors[] = 'Please select a hospital and department.';
    }

    // Validate doctor, department and hospital together.

    if ($doctorId > 0) {
        $stmt = $pdo->prepare(
            "SELECT d.doctor_id
             FROM doctors d
             INNER JOIN users u
                 ON u.user_id = d.user_id
             INNER JOIN hospitals h
                 ON h.hospital_id = d.hospital_id
             INNER JOIN departments dep
                 ON dep.department_id = d.department_id
                 AND dep.hospital_id = d.hospital_id
             WHERE d.doctor_id = ?
               AND d.hospital_id = ?
               AND d.department_id = ?
               AND d.availability_status = 'available'
               AND u.account_status = 'active'
               AND h.status = 'active'
               AND dep.status = 'active'"
        );

        $stmt->execute([
            $doctorId,
            $hospitalId,
            $departmentId
        ]);

        if (!$stmt->fetch()) {
            $errors[] = 'The selected doctor, department, or hospital is no longer available.';
        }
    } else {
        $errors[] = 'Please select a doctor.';
    }

    // Validate appointment date.

    $dateObject = DateTime::createFromFormat(
        '!Y-m-d',
        $appointmentDate
    );

    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $appointmentDate
    ) {
        $errors[] = 'Please enter a valid appointment date.';
    } elseif ($appointmentDate < date('Y-m-d')) {
        $errors[] = 'The appointment date cannot be in the past.';
    }

    // Validate appointment time.

    $timeObject = DateTime::createFromFormat(
        '!H:i',
        $appointmentTime
    );

    if (
        !$timeObject ||
        $timeObject->format('H:i') !== $appointmentTime
    ) {
        $errors[] = 'Please enter a valid appointment time.';
    }

    // Validate reason for visit.

    if ($reason === '') {
        $errors[] = 'Please enter the reason for your visit.';
    } elseif (strlen($reason) > 1000) {
        $errors[] = 'Your reason for the visit is too long.';
    }

    // --------------------------------------------------
    // 8. SAVE APPOINTMENT AND GENERATE QUEUE NUMBER
    // --------------------------------------------------

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock the hospital row and confirm it is active.

            $stmt = $pdo->prepare(
                "SELECT hospital_id
                 FROM hospitals
                 WHERE hospital_id = ?
                   AND status = 'active'
                 FOR UPDATE"
            );

            $stmt->execute([$hospitalId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'The selected hospital is no longer available.'
                );
            }

            // Recheck the department and hospital status.

            $stmt = $pdo->prepare(
                "SELECT dep.department_id
                 FROM departments dep
                 INNER JOIN hospitals h
                     ON h.hospital_id = dep.hospital_id
                 WHERE dep.department_id = ?
                   AND dep.hospital_id = ?
                   AND dep.status = 'active'
                   AND h.status = 'active'
                 FOR UPDATE"
            );

            $stmt->execute([
                $departmentId,
                $hospitalId
            ]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'The selected department or hospital is no longer available.'
                );
            }

            // Recheck doctor, user account, department and hospital.

            $stmt = $pdo->prepare(
                "SELECT d.doctor_id
                 FROM doctors d
                 INNER JOIN users u
                     ON u.user_id = d.user_id
                 INNER JOIN hospitals h
                     ON h.hospital_id = d.hospital_id
                 INNER JOIN departments dep
                     ON dep.department_id = d.department_id
                     AND dep.hospital_id = d.hospital_id
                 WHERE d.doctor_id = ?
                   AND d.hospital_id = ?
                   AND d.department_id = ?
                   AND d.availability_status = 'available'
                   AND u.account_status = 'active'
                   AND h.status = 'active'
                   AND dep.status = 'active'
                 FOR UPDATE"
            );

            $stmt->execute([
                $doctorId,
                $hospitalId,
                $departmentId
            ]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'The selected doctor, department, or hospital is no longer available.'
                );
            }

            // Insert the appointment.

            $stmt = $pdo->prepare(
                "INSERT INTO appointments (
                    hospital_id,
                    patient_id,
                    doctor_id,
                    department_id,
                    appointment_date,
                    appointment_time,
                    reason_for_visit,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'booked')"
            );

            $stmt->execute([
                $hospitalId,
                $patientId,
                $doctorId,
                $departmentId,
                $appointmentDate,
                $appointmentTime,
                $reason
            ]);

            $appointmentId = (int) $pdo->lastInsertId();

            // Get the latest queue number for this hospital,
            // department and appointment date.

            $stmt = $pdo->prepare(
                "SELECT MAX(queue_number) AS last_number
                 FROM queues
                 WHERE hospital_id = ?
                   AND department_id = ?
                   AND queue_date = ?"
            );

            $stmt->execute([
                $hospitalId,
                $departmentId,
                $appointmentDate
            ]);

            $queueResult = $stmt->fetch(PDO::FETCH_ASSOC);

            $lastNumber = (int) (
                $queueResult['last_number'] ?? 0
            );

            $nextQueueNumber = $lastNumber + 1;

            $queueLabel = 'Q' . str_pad(
                (string) $nextQueueNumber,
                3,
                '0',
                STR_PAD_LEFT
            );

            // Insert the queue record.

            $stmt = $pdo->prepare(
                "INSERT INTO queues (
                    appointment_id,
                    hospital_id,
                    department_id,
                    queue_date,
                    queue_number,
                    queue_label,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, 'waiting')"
            );

            $stmt->execute([
                $appointmentId,
                $hospitalId,
                $departmentId,
                $appointmentDate,
                $nextQueueNumber,
                $queueLabel
            ]);

            // Create the in-app notification.

            $stmt = $pdo->prepare(
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

            $notificationMessage =
                'Your appointment is booked for '
                . $appointmentDate
                . ' at '
                . $appointmentTime
                . '. Your queue number is '
                . $queueLabel
                . '.';

            $stmt->execute([
                $userId,
                $hospitalId,
                $appointmentId,
                'Appointment and queue confirmed',
                $notificationMessage
            ]);

            // Commit all records together.

            $pdo->commit();

            $success =
                'Appointment booked successfully! '
                . 'Your queue number is '
                . $queueLabel
                . '.';

            // Clear the form after successful booking.

            $hospitalId = 0;
            $departmentId = 0;
            $doctorId = 0;
            $appointmentDate = '';
            $appointmentTime = '';
            $reason = '';
            $departments = [];
            $doctors = [];

            // Reload active hospitals in case their status changed.

            $stmt = $pdo->query(
                "SELECT hospital_id, hospital_name, address
                 FROM hospitals
                 WHERE status = 'active'
                 ORDER BY hospital_name"
            );

            $hospitals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } else {
                error_log(
                    'Appointment booking error: ' . $e->getMessage()
                );

                $errors[] = 'We could not complete your booking. Please try again.';
            }
        }
    }
}

// --------------------------------------------------
// 9. HELPER FOR SAFE HTML OUTPUT
// --------------------------------------------------

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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Book Appointment | Smart Queue Management</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .booking-container {
            max-width: 850px;
            margin: 35px auto;
            padding: 25px;
        }

        .booking-card {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
        }

        .booking-card h1 {
            margin-bottom: 10px;
        }

        .booking-card p {
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cccccc;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .booking-button {
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            background: #176b5b;
            color: white;
            cursor: pointer;
            font-size: 16px;
        }

        .booking-button:hover {
            background: #105044;
        }

        .booking-button:disabled {
            background: #999999;
            cursor: not-allowed;
        }

        .message {
            padding: 14px;
            margin-bottom: 20px;
            border-radius: 6px;
        }

        .success-message {
            background: #e5f7eb;
            color: #176b36;
        }

        .error-message {
            background: #fdeaea;
            color: #9b2020;
        }

        .selection-section {
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eeeeee;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>

<div class="booking-container">

    <a
        class="back-link"
        href="dashboard.php"
    >
        &larr; Back to Patient Dashboard
    </a>

    <div class="booking-card">

        <h1>Book an Appointment</h1>

        <p>
            Choose your hospital, department and doctor,
            then select a suitable appointment date and time.
            Your queue number will be generated after booking.
        </p>

        <?php if ($success !== ''): ?>
            <div class="message success-message">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="message error-message">
                <strong>Please correct the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- HOSPITAL AND DEPARTMENT SELECTION -->

        <form
            method="GET"
            action="book_appointment.php"
            class="selection-section"
            id="selectionForm"
        >

            <div class="form-group">

                <label for="hospital_id">
                    Select Hospital
                </label>

                <select
                    name="hospital_id"
                    id="hospital_id"
                    required
                    onchange="document.getElementById('selectionForm').submit();"
                >

                    <option value="">
                        -- Choose a hospital --
                    </option>

                    <?php foreach ($hospitals as $hospital): ?>

                        <option
                            value="<?= (int) $hospital['hospital_id'] ?>"
                            <?= $hospitalId === (int) $hospital['hospital_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($hospital['hospital_name']) ?>

                            <?= !empty($hospital['address'])
                                ? ' - ' . e($hospital['address'])
                                : '' ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-group">

                <label for="department_id">
                    Select Department
                </label>

                <select
                    name="department_id"
                    id="department_id"
                    <?= $hospitalId > 0 ? 'required' : 'disabled' ?>
                    onchange="document.getElementById('selectionForm').submit();"
                >

                    <option value="">
                        -- Choose a department --
                    </option>

                    <?php foreach ($departments as $department): ?>

                        <option
                            value="<?= (int) $department['department_id'] ?>"
                            <?= $departmentId === (int) $department['department_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($department['department_name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        </form>

        <!-- APPOINTMENT BOOKING FORM -->

        <form
            method="POST"
            action="book_appointment.php"
        >

            <input
                type="hidden"
                name="hospital_id"
                value="<?= $hospitalId ?>"
            >

            <input
                type="hidden"
                name="department_id"
                value="<?= $departmentId ?>"
            >

            <div class="form-group">

                <label for="doctor_id">
                    Select Doctor
                </label>

                <select
                    name="doctor_id"
                    id="doctor_id"
                    required
                    <?= empty($doctors) ? 'disabled' : '' ?>
                >

                    <option value="">
                        -- Choose a doctor --
                    </option>

                    <?php foreach ($doctors as $doctor): ?>

                        <option
                            value="<?= (int) $doctor['doctor_id'] ?>"
                            <?= $doctorId === (int) $doctor['doctor_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($doctor['full_name']) ?>

                            <?php if (!empty($doctor['specialization'])): ?>
                                (<?= e($doctor['specialization']) ?>)
                            <?php endif; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <?php if (
                    $hospitalId > 0 &&
                    $departmentId > 0 &&
                    empty($doctors)
                ): ?>

                    <p>
                        No available doctors were found in this department.
                        Please choose another department.
                    </p>

                <?php endif; ?>

            </div>

            <div class="form-group">

                <label for="appointment_date">
                    Appointment Date
                </label>

                <input
                    type="date"
                    name="appointment_date"
                    id="appointment_date"
                    min="<?= date('Y-m-d') ?>"
                    value="<?= e($appointmentDate) ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label for="appointment_time">
                    Appointment Time
                </label>

                <input
                    type="time"
                    name="appointment_time"
                    id="appointment_time"
                    value="<?= e($appointmentTime) ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label for="reason_for_visit">
                    Reason for Visit
                </label>

                <textarea
                    name="reason_for_visit"
                    id="reason_for_visit"
                    maxlength="1000"
                    required
                    placeholder="Briefly explain why you need the appointment."
                ><?= e($reason) ?></textarea>

            </div>

            <button
                type="submit"
                name="book_appointment"
                value="1"
                class="booking-button"
                <?= $hospitalId <= 0 ||
                    $departmentId <= 0 ||
                    empty($doctors)
                    ? 'disabled'
                    : '' ?>
            >
                Book Appointment
            </button>

        </form>

    </div>

</div>

</body>
</html>
```
