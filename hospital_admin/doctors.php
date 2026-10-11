<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/hospital_access_check.php';
/*
|--------------------------------------------------------------------------
| 1. Check hospital administrator access
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'hospital_admin' ||
    empty($_SESSION['hospital_id'])
) {
    header('Location: ../auth/login.php');
    exit;
}

$hospitalId = (int) $_SESSION['hospital_id'];

/*
|--------------------------------------------------------------------------
| 2. Confirm the hospital is active
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT hospital_name, status
     FROM hospitals
     WHERE hospital_id = ?"
);

$stmt->execute([$hospitalId]);
$hospital = $stmt->fetch();

if (!$hospital || $hospital['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 3. Create a CSRF token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| 4. Flash messages
|--------------------------------------------------------------------------
*/

$message = $_SESSION['doctor_message'] ?? '';
$messageType = $_SESSION['doctor_message_type'] ?? 'success';

unset(
    $_SESSION['doctor_message'],
    $_SESSION['doctor_message_type']
);

/*
|--------------------------------------------------------------------------
| 5. Process submitted forms
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    try {

        /*
        |--------------------------------------------------------------
        | Register a doctor
        |--------------------------------------------------------------
        */

        if ($action === 'add') {

            $fullName = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $specialization = trim($_POST['specialization'] ?? '');
            $departmentId = filter_var(
                $_POST['department_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $fullName === '' ||
                $email === '' ||
                $phone === '' ||
                !is_string($password) ||
                $password === '' ||
                $specialization === '' ||
                !$departmentId ||
                $departmentId < 1
            ) {
                throw new RuntimeException(
                    'Please complete all required fields.'
                );
            }

            if (mb_strlen($fullName) > 150) {
                throw new RuntimeException(
                    'The doctor name cannot exceed 150 characters.'
                );
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'Please enter a valid email address.'
                );
            }

            if (mb_strlen($email) > 254) {
                throw new RuntimeException(
                    'The email address is too long.'
                );
            }

            if (mb_strlen($phone) > 30) {
                throw new RuntimeException(
                    'The phone number cannot exceed 30 characters.'
                );
            }

            if (strlen($password) < 12) {
                throw new RuntimeException(
                    'Use a password with at least 12 characters.'
                );
            }

            if (strlen($password) > 4096) {
                throw new RuntimeException(
                    'The password is too long.'
                );
            }

            if (mb_strlen($specialization) > 150) {
                throw new RuntimeException(
                    'The specialization cannot exceed 150 characters.'
                );
            }

            /*
             * Confirm the selected department belongs to this hospital
             * and is currently active.
             */

            $stmt = $pdo->prepare(
                "SELECT department_id
                 FROM departments
                 WHERE department_id = ?
                   AND hospital_id = ?
                   AND status = 'active'"
            );

            $stmt->execute([$departmentId, $hospitalId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'Please select an active department belonging to your hospital.'
                );
            }

            /*
             * Check whether the email is already registered.
             */

            $stmt = $pdo->prepare(
                "SELECT user_id
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                throw new RuntimeException(
                    'This email address is already registered.'
                );
            }

            /*
             * Create the user and doctor profile together.
             */

            $pdo->beginTransaction();

            try {

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                if ($passwordHash === false) {
                    throw new RuntimeException(
                        'Unable to secure the password. Please try again.'
                    );
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO users
                        (
                            hospital_id,
                            full_name,
                            email,
                            phone,
                            password_hash,
                            role,
                            account_status
                        )
                     VALUES (?, ?, ?, ?, ?, 'doctor', 'active')"
                );

                $stmt->execute([
                    $hospitalId,
                    $fullName,
                    $email,
                    $phone,
                    $passwordHash
                ]);

                $userId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO doctors
                        (
                            user_id,
                            hospital_id,
                            department_id,
                            specialization,
                            availability_status
                        )
                     VALUES (?, ?, ?, ?, 'available')"
                );

                $stmt->execute([
                    $userId,
                    $hospitalId,
                    $departmentId,
                    $specialization
                ]);

                $pdo->commit();

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

            $_SESSION['doctor_message'] =
                'Doctor registered successfully. They can now log in.';

            $_SESSION['doctor_message_type'] = 'success';

        /*
        |--------------------------------------------------------------
        | Update a doctor's details
        |--------------------------------------------------------------
        */

        } elseif ($action === 'edit') {

            $doctorId = filter_var(
                $_POST['doctor_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $fullName = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $specialization = trim($_POST['specialization'] ?? '');
            $departmentId = filter_var(
                $_POST['department_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                !$doctorId ||
                $doctorId < 1 ||
                $fullName === '' ||
                $email === '' ||
                $phone === '' ||
                $specialization === '' ||
                !$departmentId ||
                $departmentId < 1
            ) {
                throw new RuntimeException(
                    'Please complete all required fields.'
                );
            }

            if (
                mb_strlen($fullName) > 150 ||
                mb_strlen($email) > 254 ||
                mb_strlen($phone) > 30 ||
                mb_strlen($specialization) > 150
            ) {
                throw new RuntimeException(
                    'One or more fields exceed their permitted length.'
                );
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'Please enter a valid email address.'
                );
            }

            /*
             * Find the doctor and verify hospital ownership.
             */

            $stmt = $pdo->prepare(
                "SELECT doctor_id, user_id
                 FROM doctors
                 WHERE doctor_id = ?
                   AND hospital_id = ?"
            );

            $stmt->execute([$doctorId, $hospitalId]);
            $doctor = $stmt->fetch();

            if (!$doctor) {
                throw new RuntimeException(
                    'Doctor not found in your hospital.'
                );
            }

            /*
             * Verify that the selected department is active and belongs
             * to this hospital.
             */

            $stmt = $pdo->prepare(
                "SELECT department_id
                 FROM departments
                 WHERE department_id = ?
                   AND hospital_id = ?
                   AND status = 'active'"
            );

            $stmt->execute([$departmentId, $hospitalId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'Please select an active department belonging to your hospital.'
                );
            }

            /*
             * Make sure the email does not belong to another user.
             */

            $stmt = $pdo->prepare(
                "SELECT user_id
                 FROM users
                 WHERE email = ?
                   AND user_id <> ?
                 LIMIT 1"
            );

            $stmt->execute([$email, $doctor['user_id']]);

            if ($stmt->fetch()) {
                throw new RuntimeException(
                    'That email address is already in use.'
                );
            }

            $pdo->beginTransaction();

            try {

                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET full_name = ?,
                         email = ?,
                         phone = ?
                     WHERE user_id = ?
                       AND hospital_id = ?
                       AND role = 'doctor'"
                );

                $stmt->execute([
                    $fullName,
                    $email,
                    $phone,
                    $doctor['user_id'],
                    $hospitalId
                ]);

                $stmt = $pdo->prepare(
                    "UPDATE doctors
                     SET department_id = ?,
                         specialization = ?
                     WHERE doctor_id = ?
                       AND hospital_id = ?"
                );

                $stmt->execute([
                    $departmentId,
                    $specialization,
                    $doctorId,
                    $hospitalId
                ]);

                $pdo->commit();

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

            $_SESSION['doctor_message'] =
                'Doctor details updated successfully.';

            $_SESSION['doctor_message_type'] = 'success';

        /*
        |--------------------------------------------------------------
        | Change doctor availability
        |--------------------------------------------------------------
        */

        } elseif ($action === 'availability') {

            $doctorId = filter_var(
                $_POST['doctor_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $newAvailability = $_POST['availability_status'] ?? '';

            if (!$doctorId || $doctorId < 1) {
                throw new RuntimeException(
                    'Invalid doctor selected.'
                );
            }

            if (
                !in_array(
                    $newAvailability,
                    ['available', 'unavailable'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid availability status.'
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE doctors
                 SET availability_status = ?
                 WHERE doctor_id = ?
                   AND hospital_id = ?"
            );

            $stmt->execute([
                $newAvailability,
                $doctorId,
                $hospitalId
            ]);

            if ($stmt->rowCount() === 0) {

                $stmt = $pdo->prepare(
                    "SELECT doctor_id, availability_status
                     FROM doctors
                     WHERE doctor_id = ?
                       AND hospital_id = ?"
                );

                $stmt->execute([$doctorId, $hospitalId]);
                $existingDoctor = $stmt->fetch();

                if (!$existingDoctor) {
                    throw new RuntimeException(
                        'Doctor not found in your hospital.'
                    );
                }

                if (
                    $existingDoctor['availability_status']
                    !== $newAvailability
                ) {
                    throw new RuntimeException(
                        'Unable to update doctor availability.'
                    );
                }
            }

            $_SESSION['doctor_message'] =
                'Doctor availability updated successfully.';

            $_SESSION['doctor_message_type'] = 'success';

        } else {

            throw new RuntimeException(
                'Invalid action requested.'
            );
        }

    } catch (RuntimeException $e) {

        $_SESSION['doctor_message'] = $e->getMessage();
        $_SESSION['doctor_message_type'] = 'error';

    } catch (PDOException $e) {

        error_log('Doctor management database error: ' . $e->getMessage());

        if ($e->getCode() === '23000') {
            $_SESSION['doctor_message'] =
                'A duplicate account or conflicting record was detected.';
        } else {
            $_SESSION['doctor_message'] =
                'A database error occurred. Please try again.';
        }

        $_SESSION['doctor_message_type'] = 'error';

    } catch (Throwable $e) {

        error_log('Doctor management error: ' . $e->getMessage());

        $_SESSION['doctor_message'] =
            'Unable to complete the request. Please try again.';

        $_SESSION['doctor_message_type'] = 'error';
    }

    header('Location: doctors.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 6. Retrieve active departments for the registration/edit forms
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT department_id, department_name
     FROM departments
     WHERE hospital_id = ?
       AND status = 'active'
     ORDER BY department_name"
);

$stmt->execute([$hospitalId]);
$departments = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| 7. Retrieve doctors belonging to this hospital
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT d.doctor_id,
            d.user_id,
            d.department_id,
            d.specialization,
            d.availability_status,
            u.full_name,
            u.email,
            u.phone,
            dep.department_name
     FROM doctors d
     INNER JOIN users u
        ON d.user_id = u.user_id
     INNER JOIN departments dep
        ON d.department_id = dep.department_id
       AND d.hospital_id = dep.hospital_id
     WHERE d.hospital_id = ?
       AND u.hospital_id = ?
       AND u.role = 'doctor'
     ORDER BY u.full_name"
);

$stmt->execute([$hospitalId, $hospitalId]);
$doctors = $stmt->fetchAll();

function escapeDoctorHtml($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
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

    <title>Doctor Management | SmartQueue</title>

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

        .doctors-container {
            max-width: 1200px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .doctors-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .doctors-header h1 {
            margin-bottom: 8px;
        }

        .doctors-header a {
            text-decoration: none;
        }

        .doctors-card {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .doctors-card h2 {
            margin-top: 0;
        }

        .doctor-form label {
            display: block;
            margin-top: 15px;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .doctor-form input,
        .doctor-form select {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font: inherit;
        }

        .doctor-form small {
            display: block;
            margin-top: 6px;
            color: #607d8b;
        }

        .doctor-button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 10px 15px;
            margin-top: 12px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .primary-button {
            background: #075b63;
            color: white;
        }

        .secondary-button {
            background: #e5e7eb;
            color: #263238;
        }

        .success-button {
            background: #15803d;
            color: white;
        }

        .warning-button {
            background: #b45309;
            color: white;
        }

        .doctor-alert {
            padding: 14px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .doctor-alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .doctor-alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .doctors-table-wrapper {
            overflow-x: auto;
        }

        .doctors-table {
            width: 100%;
            border-collapse: collapse;
        }

        .doctors-table th,
        .doctors-table td {
            padding: 13px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid #e5e7eb;
        }

        .doctors-table th {
            background: #f0f5f6;
        }

        .doctors-table input,
        .doctors-table select {
            width: 100%;
            min-width: 140px;
            box-sizing: border-box;
            padding: 9px;
            margin-bottom: 5px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font: inherit;
        }

        .doctor-status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .doctor-status.available {
            background: #dcfce7;
            color: #166534;
        }

        .doctor-status.unavailable {
            background: #fef3c7;
            color: #92400e;
        }

        .doctor-actions form {
            margin-bottom: 10px;
        }

        .muted-text {
            color: #607d8b;
        }

        @media (max-width: 600px) {
            .doctors-container {
                padding: 0 12px;
            }

            .doctors-card {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<main class="doctors-container">

    <header class="doctors-header">

        <div>
            <h1>Doctor Management</h1>

            <p class="muted-text">
                Hospital:
                <strong>
                    <?= escapeDoctorHtml($hospital['hospital_name']) ?>
                </strong>
            </p>
        </div>

        <a
            href="dashboard.php"
            class="doctor-button secondary-button"
        >
            &larr; Back to Dashboard
        </a>

    </header>

    <?php if ($message !== ''): ?>

        <div
            class="doctor-alert <?= $messageType === 'error' ? 'error' : 'success' ?>"
            role="status"
        >
            <?= escapeDoctorHtml($message) ?>
        </div>

    <?php endif; ?>

    <!-- Doctor Registration Form -->

    <section class="doctors-card">

        <h2>Register a Doctor</h2>

        <?php if (empty($departments)): ?>

            <p class="muted-text">
                You must create and activate at least one department
                before registering a doctor.
            </p>

            <a
                href="departments.php"
                class="doctor-button primary-button"
            >
                Manage Departments
            </a>

        <?php else: ?>

            <form
                method="POST"
                action="doctors.php"
                class="doctor-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeDoctorHtml($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="add"
                >

                <label for="full_name">
                    Doctor's Full Name
                </label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="150"
                    autocomplete="name"
                    required
                >

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="254"
                    autocomplete="email"
                    required
                >

                <label for="phone">
                    Phone Number
                </label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="30"
                    autocomplete="tel"
                    required
                >

                <label for="password">
                    Temporary Login Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="12"
                    maxlength="4096"
                    autocomplete="new-password"
                    required
                >

                <small>
                    Use at least 12 characters. Share temporary
                    credentials securely and ask the doctor to
                    change the password after signing in.
                </small>

                <label for="specialization">
                    Specialization
                </label>

                <input
                    type="text"
                    id="specialization"
                    name="specialization"
                    maxlength="150"
                    placeholder="e.g. General Practitioner"
                    required
                >

                <label for="department_id">
                    Department
                </label>

                <select
                    id="department_id"
                    name="department_id"
                    required
                >

                    <option value="">
                        Select a department
                    </option>

                    <?php foreach ($departments as $department): ?>

                        <option
                            value="<?= (int) $department['department_id'] ?>"
                        >
                            <?= escapeDoctorHtml($department['department_name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <button
                    type="submit"
                    class="doctor-button primary-button"
                >
                    + Register Doctor
                </button>

            </form>

        <?php endif; ?>

    </section>

    <!-- Existing Doctors -->

    <section class="doctors-card">

        <h2>Registered Doctors</h2>

        <?php if (empty($doctors)): ?>

            <p class="muted-text">
                No doctors have been registered at your hospital yet.
            </p>

        <?php else: ?>

            <div class="doctors-table-wrapper">

                <table class="doctors-table">

                    <thead>
                        <tr>
                            <th>Doctor Details</th>
                            <th>Specialization</th>
                            <th>Department</th>
                            <th>Availability</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($doctors as $doctor): ?>

                        <tr>

                            <td>

                                <form
                                    method="POST"
                                    action="doctors.php"
                                    id="edit-doctor-<?= (int) $doctor['doctor_id'] ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= escapeDoctorHtml($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="edit"
                                    >

                                    <input
                                        type="hidden"
                                        name="doctor_id"
                                        value="<?= (int) $doctor['doctor_id'] ?>"
                                    >

                                    <label>
                                        Full name
                                    </label>

                                    <input
                                        type="text"
                                        name="full_name"
                                        value="<?= escapeDoctorHtml($doctor['full_name']) ?>"
                                        maxlength="150"
                                        required
                                    >

                                    <label>
                                        Email
                                    </label>

                                    <input
                                        type="email"
                                        name="email"
                                        value="<?= escapeDoctorHtml($doctor['email']) ?>"
                                        maxlength="254"
                                        required
                                    >

                                    <label>
                                        Phone
                                    </label>

                                    <input
                                        type="tel"
                                        name="phone"
                                        value="<?= escapeDoctorHtml($doctor['phone']) ?>"
                                        maxlength="30"
                                        required
                                    >

                            </td>

                            <td>

                                    <label>
                                        Specialization
                                    </label>

                                    <input
                                        type="text"
                                        name="specialization"
                                        value="<?= escapeDoctorHtml($doctor['specialization']) ?>"
                                        maxlength="150"
                                        required
                                    >

                            </td>

                            <td>

                                    <label>
                                        Department
                                    </label>

                                    <select
                                        name="department_id"
                                        required
                                    >

                                        <?php foreach ($departments as $department): ?>

                                            <option
                                                value="<?= (int) $department['department_id'] ?>"
                                                <?= (int) $department['department_id'] === (int) $doctor['department_id'] ? 'selected' : '' ?>
                                            >
                                                <?= escapeDoctorHtml($department['department_name']) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                            </td>

                            <td>

                                <span class="doctor-status <?= escapeDoctorHtml($doctor['availability_status']) ?>">
                                    <?= escapeDoctorHtml(ucfirst($doctor['availability_status'])) ?>
                                </span>

                            </td>

                            <td class="doctor-actions">

                                    <button
                                        type="submit"
                                        class="doctor-button primary-button"
                                    >
                                        Save Changes
                                    </button>

                                </form>

                                <form
                                    method="POST"
                                    action="doctors.php"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= escapeDoctorHtml($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="availability"
                                    >

                                    <input
                                        type="hidden"
                                        name="doctor_id"
                                        value="<?= (int) $doctor['doctor_id'] ?>"
                                    >

                                    <?php if ($doctor['availability_status'] === 'available'): ?>

                                        <input
                                            type="hidden"
                                            name="availability_status"
                                            value="unavailable"
                                        >

                                        <button
                                            type="submit"
                                            class="doctor-button warning-button"
                                        >
                                            Mark Unavailable
                                        </button>

                                    <?php else: ?>

                                        <input
                                            type="hidden"
                                            name="availability_status"
                                            value="available"
                                        >

                                        <button
                                            type="submit"
                                            class="doctor-button success-button"
                                        >
                                            Mark Available
                                        </button>

                                    <?php endif; ?>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>