<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| Authentication and authorization
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'hospital_admin' ||
    !isset($_SESSION['hospital_id'])
) {
    header('Location: ../auth/login.php');
    exit;
}

$hospitalId = (int) $_SESSION['hospital_id'];

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function queueStatusClass($status)
{
    switch ($status) {
        case 'waiting':
            return 'status-waiting';

        case 'called':
            return 'status-called';

        case 'in_progress':
            return 'status-progress';

        case 'almost_over':
            return 'status-almost';

        case 'completed':
            return 'status-completed';

        case 'absent':
            return 'status-absent';

        case 'cancelled':
            return 'status-cancelled';

        default:
            return 'status-default';
    }
}

function appointmentStatusClass($status)
{
    switch ($status) {
        case 'booked':
            return 'status-booked';

        case 'checked_in':
            return 'status-called';

        case 'waiting':
            return 'status-waiting';

        case 'in_progress':
            return 'status-progress';

        case 'almost_over':
            return 'status-almost';

        case 'completed':
            return 'status-completed';

        case 'cancelled':
            return 'status-cancelled';

        case 'no_show':
            return 'status-absent';

        default:
            return 'status-default';
    }
}

/*
|--------------------------------------------------------------------------
| Verify hospital status
|--------------------------------------------------------------------------
*/

$hospitalStatement = $pdo->prepare(
    "SELECT hospital_name, status
     FROM hospitals
     WHERE hospital_id = ?"
);

$hospitalStatement->execute([$hospitalId]);
$hospital = $hospitalStatement->fetch(PDO::FETCH_ASSOC);

if (!$hospital || $hospital['status'] !== 'active') {
    session_unset();
    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get filter values
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$selectedDate = $_GET['date'] ?? $today;
$selectedDepartment = $_GET['department_id'] ?? '';
$selectedStatus = $_GET['status'] ?? '';

$allowedQueueStatuses = [
    'waiting',
    'called',
    'in_progress',
    'almost_over',
    'completed',
    'absent',
    'cancelled'
];

/*
|--------------------------------------------------------------------------
| Validate date
|--------------------------------------------------------------------------
*/

$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $selectedDate
) {
    $selectedDate = $today;
}

/*
|--------------------------------------------------------------------------
| Validate department filter
|--------------------------------------------------------------------------
*/

if (
    $selectedDepartment !== '' &&
    (
        !ctype_digit((string) $selectedDepartment) ||
        (int) $selectedDepartment < 1
    )
) {
    $selectedDepartment = '';
}

if ($selectedDepartment !== '') {
    $selectedDepartment = (int) $selectedDepartment;

    $departmentCheck = $pdo->prepare(
        "SELECT department_id
         FROM departments
         WHERE department_id = ?
           AND hospital_id = ?"
    );

    $departmentCheck->execute([
        $selectedDepartment,
        $hospitalId
    ]);

    if (!$departmentCheck->fetchColumn()) {
        $selectedDepartment = '';
    }
}

/*
|--------------------------------------------------------------------------
| Validate queue status filter
|--------------------------------------------------------------------------
*/

if (
    $selectedStatus !== '' &&
    !in_array($selectedStatus, $allowedQueueStatuses, true)
) {
    $selectedStatus = '';
}

/*
|--------------------------------------------------------------------------
| Load hospital departments
|--------------------------------------------------------------------------
*/

$departmentStatement = $pdo->prepare(
    "SELECT department_id, department_name
     FROM departments
     WHERE hospital_id = ?
     ORDER BY department_name ASC"
);

$departmentStatement->execute([$hospitalId]);
$departments = $departmentStatement->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Build queue query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        q.queue_id,
        q.queue_number,
        q.queue_label,
        q.queue_date,
        q.status AS queue_status,
        q.updated_at,

        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.reason_for_visit,
        a.status AS appointment_status,

        patient_user.full_name AS patient_name,

        d.department_name,

        doctor_user.full_name AS doctor_name

    FROM queues q

    INNER JOIN appointments a
        ON q.appointment_id = a.appointment_id

    INNER JOIN patients p
        ON a.patient_id = p.patient_id

    INNER JOIN users patient_user
        ON p.user_id = patient_user.user_id

    INNER JOIN departments d
        ON a.department_id = d.department_id

    LEFT JOIN doctors doc
        ON a.doctor_id = doc.doctor_id

    LEFT JOIN users doctor_user
        ON doc.user_id = doctor_user.user_id

    WHERE q.hospital_id = :hospital_id
      AND q.queue_date = :queue_date
";

$params = [
    ':hospital_id' => $hospitalId,
    ':queue_date' => $selectedDate
];

if ($selectedDepartment !== '') {
    $sql .= " AND q.department_id = :department_id";
    $params[':department_id'] = $selectedDepartment;
}

if ($selectedStatus !== '') {
    $sql .= " AND q.status = :queue_status";
    $params[':queue_status'] = $selectedStatus;
}

$sql .= "
    ORDER BY
        d.department_name ASC,
        q.queue_number ASC,
        q.queue_id ASC
";

$queueStatement = $pdo->prepare($sql);
$queueStatement->execute($params);

$queues = $queueStatement->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Calculate statistics for the displayed records
|--------------------------------------------------------------------------
*/

$totalQueues = count($queues);
$waitingCount = 0;
$calledCount = 0;
$inProgressCount = 0;
$completedCount = 0;

foreach ($queues as $queue) {
    switch ($queue['queue_status']) {
        case 'waiting':
            $waitingCount++;
            break;

        case 'called':
            $calledCount++;
            break;

        case 'in_progress':
            $inProgressCount++;
            break;

        case 'completed':
            $completedCount++;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Queue Management | Hospital Admin</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .queue-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 25px;
        }

        .queue-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .queue-header h1 {
            margin-bottom: 8px;
        }

        .queue-header p {
            margin: 0;
            color: #666;
        }

        .queue-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
        }

        .queue-nav a {
            display: inline-block;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 7px;
            background: #f0f0f0;
            color: #333;
            font-weight: 600;
        }

        .queue-nav a:hover {
            background: #e2e2e2;
        }

        .queue-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .queue-stat {
            background: #fff;
            border: 1px solid #e7e7e7;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .queue-stat p {
            color: #666;
            margin: 0 0 10px;
            font-size: 14px;
        }

        .queue-stat h2 {
            margin: 0;
            font-size: 28px;
        }

        .queue-filters {
            background: #fff;
            padding: 20px;
            border: 1px solid #e7e7e7;
            border-radius: 10px;
            margin-bottom: 25px;
        }

        .queue-filters h2 {
            margin-top: 0;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-form label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .filter-form input,
        .filter-form select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: #fff;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-buttons button,
        .filter-buttons a {
            padding: 11px 16px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
            text-align: center;
        }

        .filter-buttons button {
            background: #176b55;
            color: #fff;
        }

        .filter-buttons a {
            background: #eee;
            color: #333;
        }

        .queue-table-container {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e7e7e7;
            border-radius: 10px;
        }

        .queue-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .queue-table th,
        .queue-table td {
            padding: 13px 14px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: middle;
        }

        .queue-table th {
            background: #f7f8f8;
            font-size: 13px;
            white-space: nowrap;
        }

        .queue-table tbody tr:hover {
            background: #fafafa;
        }

        .queue-number {
            font-size: 16px;
            font-weight: 700;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            text-transform: capitalize;
        }

        .status-waiting {
            background: #fff0c2;
            color: #765400;
        }

        .status-called {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-progress {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .status-almost {
            background: #ffedd5;
            color: #9a3412;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-cancelled {
            background: #eeeeee;
            color: #555;
        }

        .status-booked {
            background: #e0f2fe;
            color: #075985;
        }

        .status-default {
            background: #eee;
            color: #444;
        }

        .empty-queue {
            padding: 35px 20px;
            text-align: center;
            color: #666;
        }

        .queue-section-title {
            margin-bottom: 15px;
        }

        @media (max-width: 600px) {
            .queue-page {
                padding: 15px;
            }

            .queue-stat {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="queue-page">

    <header class="queue-header">
        <div>
            <h1>Queue Management</h1>

            <p>
                <?= escapeHtml($hospital['hospital_name']) ?>
            </p>

            <p>
                Monitor patient queues and consultation progress.
            </p>
        </div>
    </header>

    <nav class="queue-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="appointments.php">Appointments</a>
        <a href="departments.php">Departments</a>
        <a href="doctors.php">Doctors</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>

    <section class="queue-stats">

        <div class="queue-stat">
            <p>Total Queue Records</p>
            <h2><?= $totalQueues ?></h2>
        </div>

        <div class="queue-stat">
            <p>Waiting</p>
            <h2><?= $waitingCount ?></h2>
        </div>

        <div class="queue-stat">
            <p>Called</p>
            <h2><?= $calledCount ?></h2>
        </div>

        <div class="queue-stat">
            <p>In Consultation</p>
            <h2><?= $inProgressCount ?></h2>
        </div>

        <div class="queue-stat">
            <p>Completed</p>
            <h2><?= $completedCount ?></h2>
        </div>

    </section>

    <section class="queue-filters">

        <h2>Filter Queues</h2>

        <form method="GET" action="queue_management.php" class="filter-form">

            <div>
                <label for="date">Queue Date</label>

                <input
                    type="date"
                    id="date"
                    name="date"
                    value="<?= escapeHtml($selectedDate) ?>"
                >
            </div>

            <div>
                <label for="department_id">Department</label>

                <select id="department_id" name="department_id">
                    <option value="">All Departments</option>

                    <?php foreach ($departments as $department): ?>
                        <option
                            value="<?= (int) $department['department_id'] ?>"
                            <?= (string) $selectedDepartment ===
                                (string) $department['department_id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= escapeHtml($department['department_name']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div>
                <label for="status">Queue Status</label>

                <select id="status" name="status">

                    <option value="">All Statuses</option>

                    <?php foreach ($allowedQueueStatuses as $status): ?>
                        <option
                            value="<?= escapeHtml($status) ?>"
                            <?= $selectedStatus === $status ? 'selected' : '' ?>
                        >
                            <?= escapeHtml(ucwords(str_replace('_', ' ', $status))) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="filter-buttons">
                <button type="submit">Apply Filters</button>

                <a href="queue_management.php">Reset</a>
            </div>

        </form>

    </section>

    <section>
        <h2 class="queue-section-title">
            Queue Records for <?= escapeHtml($selectedDate) ?>
        </h2>

        <div class="queue-table-container">

            <?php if (empty($queues)): ?>

                <div class="empty-queue">
                    <h3>No queue records found</h3>

                    <p>
                        There are no queue records matching the selected
                        date and filters.
                    </p>
                </div>

            <?php else: ?>

                <table class="queue-table">

                    <thead>
                        <tr>
                            <th>Queue No.</th>
                            <th>Patient</th>
                            <th>Department</th>
                            <th>Doctor</th>
                            <th>Appointment Time</th>
                            <th>Queue Status</th>
                            <th>Appointment Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($queues as $queue): ?>

                            <tr>
                                <td>
                                    <span class="queue-number">
                                        <?= escapeHtml(
                                            $queue['queue_label']
                                            ?: $queue['queue_number']
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= escapeHtml($queue['patient_name']) ?>
                                </td>

                                <td>
                                    <?= escapeHtml($queue['department_name']) ?>
                                </td>

                                <td>
                                    <?= escapeHtml(
                                        $queue['doctor_name'] ?: 'Not assigned'
                                    ) ?>
                                </td>

                                <td>
                                    <?= escapeHtml($queue['appointment_time']) ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= escapeHtml(
                                        queueStatusClass($queue['queue_status'])
                                    ) ?>">
                                        <?= escapeHtml(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $queue['queue_status']
                                                )
                                            )
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status-badge <?= escapeHtml(
                                        appointmentStatusClass(
                                            $queue['appointment_status']
                                        )
                                    ) ?>">
                                        <?= escapeHtml(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $queue['appointment_status']
                                                )
                                            )
                                        ) ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>
    </section>

</div>

</body>
</html>