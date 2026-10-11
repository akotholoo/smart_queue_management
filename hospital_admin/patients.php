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
| Helper function
|--------------------------------------------------------------------------
*/

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
| Get and validate search term
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

/*
|--------------------------------------------------------------------------
| Retrieve patients associated with this hospital
|--------------------------------------------------------------------------
|
| Only patients who have at least one appointment at this hospital
| are displayed.
|
*/

$sql = "
    SELECT
        p.patient_id,
        u.full_name,
        u.email,
        u.phone,
        COUNT(DISTINCT a.appointment_id) AS appointment_count,
        MAX(a.appointment_date) AS latest_appointment_date

    FROM patients p

    INNER JOIN users u
        ON p.user_id = u.user_id

    INNER JOIN appointments a
        ON a.patient_id = p.patient_id

    WHERE a.hospital_id = :hospital_id
";

$params = [
    ':hospital_id' => $hospitalId
];

/*
|--------------------------------------------------------------------------
| Search by name, email, or phone
|--------------------------------------------------------------------------
*/

if ($search !== '') {
    $sql .= "
        AND (
            u.full_name LIKE :search_name
            OR u.email LIKE :search_email
            OR u.phone LIKE :search_phone
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[':search_name'] = $searchTerm;
    $params[':search_email'] = $searchTerm;
    $params[':search_phone'] = $searchTerm;
}

/*
|--------------------------------------------------------------------------
| Group and order results
|--------------------------------------------------------------------------
*/

$sql .= "
    GROUP BY
        p.patient_id,
        u.full_name,
        u.email,
        u.phone

    ORDER BY
        MAX(a.appointment_date) DESC,
        u.full_name ASC
";

$patientStatement = $pdo->prepare($sql);
$patientStatement->execute($params);

$patients = $patientStatement->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Calculate summary information
|--------------------------------------------------------------------------
*/

$totalPatients = count($patients);

$totalAppointments = 0;

foreach ($patients as $patient) {
    $totalAppointments += (int) $patient['appointment_count'];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Patient Management | Hospital Admin</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .patients-page {
            max-width: 1300px;
            margin: 0 auto;
            padding: 25px;
        }

        .patients-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .patients-header h1 {
            margin-bottom: 8px;
        }

        .patients-header p {
            color: #666;
            margin: 0;
        }

        .patients-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
        }

        .patients-nav a {
            display: inline-block;
            padding: 10px 15px;
            background: #f0f0f0;
            color: #333;
            text-decoration: none;
            border-radius: 7px;
            font-weight: 600;
        }

        .patients-nav a:hover {
            background: #e2e2e2;
        }

        .patients-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .patients-stat {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 10px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .patients-stat p {
            margin: 0 0 10px;
            color: #666;
            font-size: 14px;
        }

        .patients-stat h2 {
            margin: 0;
            font-size: 28px;
        }

        .patients-search {
            background: #fff;
            padding: 20px;
            border: 1px solid #e5e5e5;
            border-radius: 10px;
            margin-bottom: 25px;
        }

        .patients-search h2 {
            margin-top: 0;
        }

        .search-form {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .search-field {
            flex: 1;
            min-width: 230px;
        }

        .search-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .search-field input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        .search-form button,
        .search-form a {
            padding: 11px 16px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .search-form button {
            background: #176b55;
            color: white;
        }

        .search-form a {
            background: #eee;
            color: #333;
        }

        .patients-table-container {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 10px;
            overflow-x: auto;
        }

        .patients-table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        .patients-table th,
        .patients-table td {
            text-align: left;
            padding: 14px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .patients-table th {
            background: #f7f8f8;
            font-size: 13px;
        }

        .patients-table tbody tr:hover {
            background: #fafafa;
        }

        .patient-name {
            font-weight: 700;
        }

        .patient-id {
            color: #777;
            font-size: 12px;
            margin-top: 4px;
        }

        .appointment-count {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            background: #e0f2fe;
            color: #075985;
            font-weight: 700;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .results-heading {
            margin-bottom: 15px;
        }

        @media (max-width: 600px) {
            .patients-page {
                padding: 15px;
            }

            .search-form button,
            .search-form a {
                text-align: center;
            }
        }
    </style>
</head>

<body>

<div class="patients-page">

    <header class="patients-header">
        <div>
            <h1>Patient Management</h1>

            <p>
                <?= escapeHtml($hospital['hospital_name']) ?>
            </p>

            <p>
                View patients who have appointments at this hospital.
            </p>
        </div>
    </header>

    <nav class="patients-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="appointments.php">Appointments</a>
        <a href="queue_management.php">Queue Management</a>
        <a href="departments.php">Departments</a>
        <a href="doctors.php">Doctors</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>

    <section class="patients-stats">

        <div class="patients-stat">
            <p>Patients Found</p>
            <h2><?= $totalPatients ?></h2>
        </div>

        <div class="patients-stat">
            <p>Appointments for These Patients</p>
            <h2><?= $totalAppointments ?></h2>
        </div>

    </section>

    <section class="patients-search">

        <h2>Search Patients</h2>

        <form
            method="GET"
            action="patients.php"
            class="search-form"
        >

            <div class="search-field">
                <label for="search">
                    Name, email, or phone number
                </label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    maxlength="100"
                    placeholder="Enter patient details..."
                    value="<?= escapeHtml($search) ?>"
                >
            </div>

            <button type="submit">Search</button>

            <a href="patients.php">Reset</a>

        </form>

    </section>

    <section>

        <h2 class="results-heading">Registered Patients</h2>

        <div class="patients-table-container">

            <?php if (empty($patients)): ?>

                <div class="empty-state">

                    <h3>No patients found</h3>

                    <p>
                        <?php if ($search !== ''): ?>
                            No patients matched your search.
                            Try another name, email, or phone number.
                        <?php else: ?>
                            Patients will appear here when they have
                            appointments at this hospital.
                        <?php endif; ?>
                    </p>

                </div>

            <?php else: ?>

                <table class="patients-table">

                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Total Appointments</th>
                            <th>Latest Appointment</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($patients as $patient): ?>

                            <tr>

                                <td>
                                    <div class="patient-name">
                                        <?= escapeHtml($patient['full_name']) ?>
                                    </div>

                                    <div class="patient-id">
                                        Patient ID:
                                        <?= (int) $patient['patient_id'] ?>
                                    </div>
                                </td>

                                <td>
                                    <?= escapeHtml($patient['email']) ?>
                                </td>

                                <td>
                                    <?= escapeHtml(
                                        $patient['phone'] ?: 'Not provided'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="appointment-count">
                                        <?= (int) $patient['appointment_count'] ?>
                                    </span>
                                </td>

                                <td>
                                    <?= escapeHtml(
                                        $patient['latest_appointment_date']
                                            ?: 'N/A'
                                    ) ?>
                                </td>
                                <td>
    <a
        href="patient_profile.php?patient_id=<?= (int) $patient['patient_id'] ?>"
        class="dashboard-button"
    >
        View Profile
    </a>
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