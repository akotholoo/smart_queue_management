<?php

session_start();

require_once __DIR__ . "/../config/database.php";

/**
 * Allow hospital administrators only.
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

/**
 * Escape values before displaying them in HTML.
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
 * Verify that the administrator's hospital is active.
 */
$stmt = $pdo->prepare(
    "SELECT hospital_name, status
     FROM hospitals
     WHERE hospital_id = ?
     LIMIT 1"
);

$stmt->execute([$hospitalId]);

$hospital = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hospital || $hospital["status"] !== "active") {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

/**
 * Read and validate filters.
 */
$search = trim($_GET["search"] ?? "");
$date = trim($_GET["date"] ?? "");
$status = trim($_GET["status"] ?? "");

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
    $status = "";
}

/**
 * Accept a date only if it uses YYYY-MM-DD and is a real date.
 */
if ($date !== "") {
    $parsedDate = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $date
    );

    if (
        !$parsedDate ||
        $parsedDate->format("Y-m-d") !== $date
    ) {
        $date = "";
    }
}

/**
 * Build a hospital-scoped appointments query.
 */
$sql = "
    SELECT
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.reason_for_visit,
        a.status AS appointment_status,
        patient_user.full_name AS patient_name,
        patient_user.email AS patient_email,
        patient_user.phone AS patient_phone,
        d.department_name,
        doctor_user.full_name AS doctor_name,
        q.queue_number,
        q.queue_label,
        q.status AS queue_status
    FROM appointments a

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

    LEFT JOIN queues q
        ON a.appointment_id = q.appointment_id

    WHERE a.hospital_id = ?
";

$params = [$hospitalId];

/**
 * Search patient name, email, or phone number.
 */
if ($search !== "") {
    $sql .= "
        AND (
            patient_user.full_name LIKE ?
            OR patient_user.email LIKE ?
            OR patient_user.phone LIKE ?
        )
    ";

    $searchTerm = "%" . $search . "%";

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

/**
 * Filter by appointment date.
 */
if ($date !== "") {
    $sql .= " AND a.appointment_date = ? ";
    $params[] = $date;
}

/**
 * Filter by appointment status.
 */
if ($status !== "") {
    $sql .= " AND a.status = ? ";
    $params[] = $status;
}

$sql .= "
    ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC,
        a.appointment_id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Count appointments using the same filters.
 */
$totalFiltered = count($appointments);

/**
 * Display-friendly appointment statuses.
 */
function formatStatus($value): string
{
    return ucfirst(
        str_replace("_", " ", (string) $value)
    );
}

function statusClass($value): string
{
    $allowed = [
        "booked",
        "checked_in",
        "waiting",
        "in_progress",
        "almost_over",
        "completed",
        "cancelled",
        "no_show"
    ];

    if (!in_array($value, $allowed, true)) {
        return "status-default";
    }

    return "status-" . $value;
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

    <title>Appointments | SmartQueue</title>

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

        .admin-header h1 {
            margin: 0;
            font-size: 22px;
        }

        .header-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .header-links a {
            color: white;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: 6px;
            padding: 9px 12px;
            font-size: 14px;
        }

        .header-links a:hover {
            background: rgba(255,255,255,0.12);
        }

        .container {
            max-width: 1250px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h2 {
            color: #164e63;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #607d8b;
            line-height: 1.6;
        }

        .panel {
            background: white;
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
        }

        .filter-form {
            display: grid;
            grid-template-columns:
                minmax(200px, 2fr)
                minmax(150px, 1fr)
                minmax(170px, 1fr)
                auto auto;
            gap: 14px;
            align-items: end;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 7px;
            color: #164e63;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: white;
            font-size: 14px;
        }

        .button {
            display: inline-block;
            padding: 11px 15px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .button-primary {
            background: #087f8c;
            color: white;
        }

        .button-secondary {
            background: #e2e8f0;
            color: #334155;
        }

        .results-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .results-heading h3 {
            color: #164e63;
            margin: 0;
        }

        .result-count {
            color: #607d8b;
            font-size: 14px;
        }

        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        th,
        td {
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }

        th {
            background: #f0f5f6;
            color: #164e63;
        }

        tbody tr:hover {
            background: #f8fbfc;
        }

        .patient-name {
            font-weight: bold;
            color: #164e63;
            margin-bottom: 5px;
        }

        .patient-contact {
            color: #607d8b;
            font-size: 12px;
            line-height: 1.6;
            overflow-wrap: anywhere;
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
        .status-no_show {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-default {
            background: #e2e8f0;
            color: #334155;
        }

        .empty-state {
            padding: 35px 20px;
            text-align: center;
            color: #607d8b;
            background: #f8fbfc;
            border-radius: 8px;
            margin-top: 20px;
        }

        .empty-state h3 {
            color: #164e63;
        }

        .footer {
            padding: 25px;
            text-align: center;
            color: #607d8b;
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .admin-header {
                align-items: flex-start;
                flex-direction: column;
                padding: 18px 20px;
            }

            .container {
                padding: 0 12px;
                margin: 25px auto;
            }

            .panel {
                padding: 16px;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .filter-form .button {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>

</head>

<body>

<header class="admin-header">

    <h1>SmartQueue | Appointments</h1>

    <nav class="header-links">

        <a href="dashboard.php">
            Dashboard
        </a>

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

<main class="container">

    <section class="page-heading">

        <h2>Appointment Management 📅</h2>

        <p>
            View and search appointments booked at
            <?= escape($hospital["hospital_name"]) ?>.
            Use the filters to find specific appointments.
        </p>

    </section>

    <section class="panel">

        <h3>Search and Filter Appointments</h3>

        <form
            method="GET"
            action="appointments.php"
            class="filter-form"
        >

            <div class="form-group">

                <label for="search">
                    Patient Search
                </label>

                <input
                    type="search"
                    id="search"
                    name="search"
                    placeholder="Name, email, or phone"
                    value="<?= escape($search) ?>"
                >

            </div>

            <div class="form-group">

                <label for="date">
                    Appointment Date
                </label>

                <input
                    type="date"
                    id="date"
                    name="date"
                    value="<?= escape($date) ?>"
                >

            </div>

            <div class="form-group">

                <label for="status">
                    Appointment Status
                </label>

                <select id="status" name="status">

                    <option value="">
                        All statuses
                    </option>

                    <?php foreach ($allowedStatuses as $option): ?>

                        <option
                            value="<?= escape($option) ?>"
                            <?= $status === $option ? "selected" : "" ?>
                        >
                            <?= escape(formatStatus($option)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <button
                type="submit"
                class="button button-primary"
            >
                Apply Filters
            </button>

            <a
                href="appointments.php"
                class="button button-secondary"
            >
                Clear
            </a>

        </form>

    </section>

    <section class="panel">

        <div class="results-heading">

            <h3>Appointment Records</h3>

            <span class="result-count">
                <?= $totalFiltered ?>
                record<?= $totalFiltered === 1 ? "" : "s" ?> found
            </span>

        </div>

        <?php if (empty($appointments)): ?>

            <div class="empty-state">

                <h3>No appointments found</h3>

                <p>
                    Try changing your search terms or filters.
                    Appointments for this hospital will appear here
                    when available.
                </p>

            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>
                            <th>Patient</th>
                            <th>Department</th>
                            <th>Doctor</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Queue Number</th>
                            <th>Appointment Status</th>
                            <th>Queue Status</th>
                            <th>Reason for Visit</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($appointments as $appointment): ?>

                            <tr>

                                <td>

                                    <div class="patient-name">
                                        <?= escape($appointment["patient_name"]) ?>
                                    </div>

                                    <div class="patient-contact">
                                        <?= escape(
                                            $appointment["patient_email"]
                                            ?: "No email"
                                        ) ?>

                                        <br>

                                        <?= escape(
                                            $appointment["patient_phone"]
                                            ?: "No phone"
                                        ) ?>
                                    </div>

                                </td>

                                <td>
                                    <?= escape($appointment["department_name"]) ?>
                                </td>

                                <td>
                                    <?= escape(
                                        $appointment["doctor_name"]
                                        ?: "Not assigned"
                                    ) ?>
                                </td>

                                <td>
                                    <?= escape($appointment["appointment_date"]) ?>
                                </td>

                                <td>
                                    <?= escape($appointment["appointment_time"]) ?>
                                </td>

                                <td>
                                    <?= escape(
                                        $appointment["queue_label"]
                                        ?: $appointment["queue_number"]
                                        ?: "Not assigned"
                                    ) ?>
                                </td>

                                <td>

                                    <span class="status <?= escape(
                                        statusClass(
                                            $appointment["appointment_status"]
                                        )
                                    ) ?>">

                                        <?= escape(
                                            formatStatus(
                                                $appointment["appointment_status"]
                                            )
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if ($appointment["queue_status"]): ?>

                                        <span class="status <?= escape(
                                            statusClass(
                                                $appointment["queue_status"]
                                            )
                                        ) ?>">

                                            <?= escape(
                                                formatStatus(
                                                    $appointment["queue_status"]
                                                )
                                            ) ?>

                                        </span>

                                    <?php else: ?>

                                        Not assigned

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= escape(
                                        $appointment["reason_for_visit"]
                                        ?: "Not provided"
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<footer class="footer">

    <p>
        &copy; <?= date("Y") ?> SmartQueue
    </p>

</footer>

</body>

</html>