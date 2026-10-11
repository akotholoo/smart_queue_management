<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/hospital_access_check.php';
/*
|--------------------------------------------------------------------------
| 1. Check administrator access
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
| 2. Confirm that the hospital is active
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

$message = $_SESSION['department_message'] ?? '';
$messageType = $_SESSION['department_message_type'] ?? 'success';

unset(
    $_SESSION['department_message'],
    $_SESSION['department_message_type']
);

/*
|--------------------------------------------------------------------------
| 5. Process form submissions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid form submission. Please refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    try {

        /*
        |--------------------------------------------------------------
        | Add a department
        |--------------------------------------------------------------
        */

        if ($action === 'add') {

            $departmentName = trim($_POST['department_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($departmentName === '') {
                throw new RuntimeException(
                    'Please enter a department name.'
                );
            }

            if (mb_strlen($departmentName) > 100) {
                throw new RuntimeException(
                    'The department name cannot exceed 100 characters.'
                );
            }

            if (mb_strlen($description) > 1000) {
                throw new RuntimeException(
                    'The description cannot exceed 1,000 characters.'
                );
            }

            $stmt = $pdo->prepare(
                "INSERT INTO departments
                    (hospital_id, department_name, description, status)
                 VALUES (?, ?, ?, 'active')"
            );

            $stmt->execute([
                $hospitalId,
                $departmentName,
                $description !== '' ? $description : null
            ]);

            $_SESSION['department_message'] =
                'Department added successfully!';

            $_SESSION['department_message_type'] = 'success';

        /*
        |--------------------------------------------------------------
        | Edit a department
        |--------------------------------------------------------------
        */

        } elseif ($action === 'edit') {

            $departmentId = filter_var(
                $_POST['department_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $departmentName = trim($_POST['department_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (!$departmentId || $departmentId < 1) {
                throw new RuntimeException(
                    'Invalid department selected.'
                );
            }

            if ($departmentName === '') {
                throw new RuntimeException(
                    'Please enter a department name.'
                );
            }

            if (mb_strlen($departmentName) > 100) {
                throw new RuntimeException(
                    'The department name cannot exceed 100 characters.'
                );
            }

            if (mb_strlen($description) > 1000) {
                throw new RuntimeException(
                    'The description cannot exceed 1,000 characters.'
                );
            }

            // Confirm the department belongs to this hospital.
            $stmt = $pdo->prepare(
                "SELECT department_id
                 FROM departments
                 WHERE department_id = ?
                   AND hospital_id = ?"
            );

            $stmt->execute([$departmentId, $hospitalId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException(
                    'Department not found in your hospital.'
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE departments
                 SET department_name = ?, description = ?
                 WHERE department_id = ?
                   AND hospital_id = ?"
            );

            $stmt->execute([
                $departmentName,
                $description !== '' ? $description : null,
                $departmentId,
                $hospitalId
            ]);

            $_SESSION['department_message'] =
                'Department updated successfully!';

            $_SESSION['department_message_type'] = 'success';

        /*
        |--------------------------------------------------------------
        | Activate or deactivate a department
        |--------------------------------------------------------------
        */

        } elseif ($action === 'toggle_status') {

            $departmentId = filter_var(
                $_POST['department_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $newStatus = $_POST['new_status'] ?? '';

            if (!$departmentId || $departmentId < 1) {
                throw new RuntimeException(
                    'Invalid department selected.'
                );
            }

            if (!in_array($newStatus, ['active', 'inactive'], true)) {
                throw new RuntimeException(
                    'Invalid department status.'
                );
            }

            // Only allow changes to departments belonging to this hospital.
            $stmt = $pdo->prepare(
                "UPDATE departments
                 SET status = ?
                 WHERE department_id = ?
                   AND hospital_id = ?"
            );

            $stmt->execute([
                $newStatus,
                $departmentId,
                $hospitalId
            ]);

            if ($stmt->rowCount() === 0) {

                $stmt = $pdo->prepare(
                    "SELECT department_id, status
                     FROM departments
                     WHERE department_id = ?
                       AND hospital_id = ?"
                );

                $stmt->execute([$departmentId, $hospitalId]);
                $existingDepartment = $stmt->fetch();

                if (!$existingDepartment) {
                    throw new RuntimeException(
                        'Department not found in your hospital.'
                    );
                }

                if ($existingDepartment['status'] !== $newStatus) {
                    throw new RuntimeException(
                        'Unable to update department status.'
                    );
                }
            }

            $_SESSION['department_message'] =
                $newStatus === 'active'
                    ? 'Department activated successfully!'
                    : 'Department deactivated successfully!';

            $_SESSION['department_message_type'] = 'success';

        } else {
            throw new RuntimeException(
                'Invalid action requested.'
            );
        }

    } catch (RuntimeException $e) {

        $_SESSION['department_message'] = $e->getMessage();
        $_SESSION['department_message_type'] = 'error';

    } catch (PDOException $e) {

        error_log('Department management error: ' . $e->getMessage());

        if ($e->getCode() === '23000') {
            $_SESSION['department_message'] =
                'A department with that name already exists in your hospital.';
        } else {
            $_SESSION['department_message'] =
                'Something went wrong. Please try again.';
        }

        $_SESSION['department_message_type'] = 'error';
    }

    // Prevent the browser from submitting the same form again on refresh.
    header('Location: departments.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 6. Retrieve this hospital's departments
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT department_id,
            department_name,
            description,
            status,
            created_at
     FROM departments
     WHERE hospital_id = ?
     ORDER BY department_name ASC"
);

$stmt->execute([$hospitalId]);
$departments = $stmt->fetchAll();

function escapeHtml($value)
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

    <title>Department Management | SmartQueue</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .department-container {
            max-width: 1100px;
            margin: 35px auto;
            padding: 20px;
        }

        .department-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .department-card {
            background: #ffffff;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.07);
        }

        .department-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
        }

        .department-form label {
            display: block;
            margin-top: 15px;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .department-form input,
        .department-form textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font: inherit;
        }

        .department-form textarea {
            min-height: 90px;
            resize: vertical;
        }

        .department-button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 10px 16px;
            margin-top: 15px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .primary-button {
            background: #2563eb;
            color: white;
        }

        .secondary-button {
            background: #e5e7eb;
            color: #111827;
        }

        .success-button {
            background: #15803d;
            color: white;
        }

        .warning-button {
            background: #b45309;
            color: white;
        }

        .department-alert {
            padding: 14px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .department-alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .department-alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .department-table-wrapper {
            overflow-x: auto;
        }

        .department-table {
            width: 100%;
            border-collapse: collapse;
        }

        .department-table th,
        .department-table td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .department-table th {
            background: #f3f4f6;
        }

        .department-table input,
        .department-table textarea {
            width: 100%;
            min-width: 150px;
            box-sizing: border-box;
            padding: 9px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font: inherit;
        }

        .department-table textarea {
            min-height: 65px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #f3f4f6;
            color: #4b5563;
        }

        .department-actions form {
            margin-bottom: 10px;
        }

        .department-actions button {
            margin-top: 8px;
        }

        .department-muted {
            color: #6b7280;
        }

        @media (max-width: 600px) {
            .department-container {
                padding: 12px;
            }

            .department-card {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<div class="department-container">

    <div class="department-header">

        <div>
            <h1>Department Management</h1>

            <p class="department-muted">
                Hospital:
                <strong>
                    <?= escapeHtml($hospital['hospital_name']) ?>
                </strong>
            </p>
        </div>

        <a
            href="dashboard.php"
            class="department-button secondary-button"
        >
            &larr; Back to Dashboard
        </a>

    </div>

    <?php if ($message !== ''): ?>
        <div
            class="department-alert <?= $messageType === 'error' ? 'error' : 'success' ?>"
            role="status"
        >
            <?= escapeHtml($message) ?>
        </div>
    <?php endif; ?>

    <!-- Add Department Form -->

    <section class="department-card">

        <h2>Add a New Department</h2>

        <form
            method="POST"
            action="departments.php"
            class="department-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHtml($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add"
            >

            <label for="department_name">
                Department Name
            </label>

            <input
                type="text"
                id="department_name"
                name="department_name"
                maxlength="100"
                placeholder="e.g. Pediatrics"
                required
            >

            <label for="description">
                Description
            </label>

            <textarea
                id="description"
                name="description"
                maxlength="1000"
                placeholder="Briefly describe the department"
            ></textarea>

            <button
                type="submit"
                class="department-button primary-button"
            >
                + Add Department
            </button>

        </form>

    </section>

    <!-- Existing Departments -->

    <section class="department-card">

        <h2>Your Hospital's Departments</h2>

        <?php if (empty($departments)): ?>

            <p class="department-muted">
                No departments have been added yet.
                Use the form above to create your first department.
            </p>

        <?php else: ?>

            <div class="department-table-wrapper">

                <table class="department-table">

                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($departments as $department): ?>

                        <tr>

                            <td>

                                <form
                                    method="POST"
                                    action="departments.php"
                                    id="edit-department-<?= (int) $department['department_id'] ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= escapeHtml($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="edit"
                                    >

                                    <input
                                        type="hidden"
                                        name="department_id"
                                        value="<?= (int) $department['department_id'] ?>"
                                    >

                                    <input
                                        type="text"
                                        name="department_name"
                                        maxlength="100"
                                        value="<?= escapeHtml($department['department_name']) ?>"
                                        aria-label="Department name"
                                        required
                                    >

                            </td>

                            <td>

                                    <textarea
                                        name="description"
                                        maxlength="1000"
                                        aria-label="Department description"
                                    ><?= escapeHtml($department['description']) ?></textarea>

                            </td>

                            <td>

                                <span class="status-badge <?= $department['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                    <?= escapeHtml(ucfirst($department['status'])) ?>
                                </span>

                            </td>

                            <td class="department-actions">

                                    <button
                                        type="submit"
                                        class="department-button primary-button"
                                    >
                                        Save Changes
                                    </button>

                                </form>

                                <form
                                    method="POST"
                                    action="departments.php"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= escapeHtml($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="toggle_status"
                                    >

                                    <input
                                        type="hidden"
                                        name="department_id"
                                        value="<?= (int) $department['department_id'] ?>"
                                    >

                                    <?php if ($department['status'] === 'active'): ?>

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="inactive"
                                        >

                                        <button
                                            type="submit"
                                            class="department-button warning-button"
                                        >
                                            Deactivate
                                        </button>

                                    <?php else: ?>

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="active"
                                        >

                                        <button
                                            type="submit"
                                            class="department-button success-button"
                                        >
                                            Activate
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

</div>

</body>
</html>