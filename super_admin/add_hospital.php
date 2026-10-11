<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "super_admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$errors = [];
$success = "";

$hospitalName = "";
$registrationNumber = "";
$address = "";
$hospitalPhone = "";
$hospitalEmail = "";
$adminName = "";
$adminEmail = "";
$adminPhone = "";
$hospitalStatus = "active";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $hospitalName = trim($_POST["hospital_name"] ?? "");
    $registrationNumber = trim($_POST["registration_number"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $hospitalPhone = trim($_POST["hospital_phone"] ?? "");
    $hospitalEmail = strtolower(trim($_POST["hospital_email"] ?? ""));

    $adminName = trim($_POST["admin_name"] ?? "");
    $adminEmail = strtolower(trim($_POST["admin_email"] ?? ""));
    $adminPhone = trim($_POST["admin_phone"] ?? "");
    $password = $_POST["admin_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $hospitalStatus = $_POST["hospital_status"] ?? "active";

    if ($hospitalName === "") {
        $errors[] = "Hospital name is required.";
    }

    if ($registrationNumber === "") {
        $errors[] = "Hospital registration number is required.";
    }

    if ($address === "") {
        $errors[] = "Hospital address is required.";
    }

    if (
        $hospitalEmail === "" ||
        !filter_var($hospitalEmail, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = "Enter a valid hospital email address.";
    }

    if ($hospitalPhone === "") {
        $errors[] = "Hospital phone number is required.";
    }

    if ($adminName === "") {
        $errors[] = "Administrator's full name is required.";
    }

    if (
        $adminEmail === "" ||
        !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = "Enter a valid administrator email address.";
    }

    if ($adminPhone === "") {
        $errors[] = "Administrator's phone number is required.";
    }

    if (strlen($password) < 8) {
        $errors[] = "The administrator's password must contain at least 8 characters.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "The passwords do not match.";
    }

    if (!in_array($hospitalStatus, ["active", "pending"], true)) {
        $errors[] = "Select a valid hospital status.";
    }

    if (empty($errors)) {
        try {
            $checkHospital = $pdo->prepare(
                "SELECT hospital_id
                 FROM hospitals
                 WHERE registration_number = ?
                    OR email = ?
                 LIMIT 1"
            );

            $checkHospital->execute([
                $registrationNumber,
                $hospitalEmail
            ]);

            if ($checkHospital->fetch()) {
                $errors[] =
                    "A hospital with this registration number or email already exists.";
            }

            $checkUser = $pdo->prepare(
                "SELECT user_id
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            $checkUser->execute([$adminEmail]);

            if ($checkUser->fetch()) {
                $errors[] =
                    "An account already exists with the administrator's email address.";
            }

            if (empty($errors)) {
                $pdo->beginTransaction();

                $hospitalStmt = $pdo->prepare(
                    "INSERT INTO hospitals (
                        hospital_name,
                        registration_number,
                        address,
                        phone,
                        email,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?)"
                );

                $hospitalStmt->execute([
                    $hospitalName,
                    $registrationNumber,
                    $address,
                    $hospitalPhone,
                    $hospitalEmail,
                    $hospitalStatus
                ]);

                $hospitalId = (int) $pdo->lastInsertId();

                $adminStatus = $hospitalStatus === "active"
                    ? "active"
                    : "pending";

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $adminStmt = $pdo->prepare(
                    "INSERT INTO users (
                        hospital_id,
                        full_name,
                        email,
                        phone,
                        password_hash,
                        role,
                        account_status
                    ) VALUES (?, ?, ?, ?, ?, 'hospital_admin', ?)"
                );

                $adminStmt->execute([
                    $hospitalId,
                    $adminName,
                    $adminEmail,
                    $adminPhone,
                    $passwordHash,
                    $adminStatus
                ]);

                $pdo->commit();

                $_SESSION["hospital_success"] =
                    "Hospital and administrator account created successfully.";

                header("Location: manage_hospital.php");
                exit;
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                "Add hospital error: " . $exception->getMessage()
            );

            $errors[] =
                "The hospital could not be registered. Check that the details are unique and try again.";
        }
    }
}
?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">

```
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Add Hospital | SmartQueue</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

<style>
    .add-hospital-page {
        padding: 40px 0;
    }

    .form-card {
        max-width: 850px;
        margin: 0 auto;
        padding: 30px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
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
        line-height: 1.6;
    }

    .form-section {
        margin-top: 28px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }

    .form-section h2 {
        color: #164e63;
        font-size: 20px;
        margin-bottom: 18px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 7px;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-group label {
        color: #334155;
        font-weight: 600;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #cbd5e1;
        border-radius: 7px;
        font: inherit;
        box-sizing: border-box;
    }

    .form-group textarea {
        min-height: 85px;
        resize: vertical;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: 2px solid #a5f3fc;
        border-color: #0891b2;
    }

    .form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 28px;
    }

    .submit-button,
    .cancel-button {
        display: inline-block;
        padding: 12px 18px;
        border-radius: 7px;
        text-decoration: none;
        font-weight: 600;
        cursor: pointer;
        border: none;
        font-size: 15px;
    }

    .submit-button {
        background: #0e7490;
        color: white;
    }

    .cancel-button {
        background: #e2e8f0;
        color: #334155;
    }

    .error-box {
        background: #fee2e2;
        color: #991b1b;
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .error-box li {
        margin-bottom: 5px;
    }

    .status-note {
        color: #64748b;
        font-size: 13px;
        line-height: 1.5;
    }

    .back-link {
        display: inline-block;
        margin-bottom: 20px;
        color: #0e7490;
        text-decoration: none;
        font-weight: 600;
    }

    @media (max-width: 650px) {
        .form-grid {
            grid-template-columns: 1fr;
        }

        .form-card {
            padding: 20px;
        }
    }
</style>
```

</head>

<body>

<header class="navbar">
    <div class="container navbar-content">
        <a href="../index.php" class="logo">
            SmartQueue
        </a>

```
    <nav class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_hospital.php">Manage Hospitals</a>
        <a href="../auth/logout.php">Log Out</a>
    </nav>
</div>
```

</header>

<main class="add-hospital-page">
    <div class="container">

```
    <a href="dashboard.php" class="back-link">
        ← Back to Super Admin Dashboard
    </a>

    <section class="page-heading">
        <h1>Register a Hospital</h1>

        <p>
            Add a hospital and create its hospital administrator
            account. Both records will be saved together.
        </p>
    </section>

    <section class="form-card">

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <strong>Please correct the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST">

            <section class="form-section" style="margin-top: 0; padding-top: 0; border-top: none;">
                <h2>Hospital Information</h2>

                <div class="form-grid">

                    <div class="form-group">
                        <label for="hospital_name">
                            Hospital Name
                        </label>

                        <input
                            type="text"
                            id="hospital_name"
                            name="hospital_name"
                            value="<?= e($hospitalName) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="registration_number">
                            Registration Number
                        </label>

                        <input
                            type="text"
                            id="registration_number"
                            name="registration_number"
                            value="<?= e($registrationNumber) ?>"
                            required
                        >
                    </div>

                    <div class="form-group full-width">
                        <label for="address">
                            Hospital Address
                        </label>

                        <textarea
                            id="address"
                            name="address"
                            required
                        ><?= e($address) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="hospital_phone">
                            Hospital Phone
                        </label>

                        <input
                            type="tel"
                            id="hospital_phone"
                            name="hospital_phone"
                            value="<?= e($hospitalPhone) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="hospital_email">
                            Hospital Email
                        </label>

                        <input
                            type="email"
                            id="hospital_email"
                            name="hospital_email"
                            value="<?= e($hospitalEmail) ?>"
                            required
                        >
                    </div>

                    <div class="form-group full-width">
                        <label for="hospital_status">
                            Hospital Status
                        </label>

                        <select
                            id="hospital_status"
                            name="hospital_status"
                            required
                        >
                            <option
                                value="active"
                                <?= $hospitalStatus === "active" ? "selected" : "" ?>
                            >
                                Active
                            </option>

                            <option
                                value="pending"
                                <?= $hospitalStatus === "pending" ? "selected" : "" ?>
                            >
                                Pending Approval
                            </option>
                        </select>

                        <p class="status-note">
                            Active hospitals can use the system immediately.
                            Pending hospitals and their administrators remain
                            pending until approval.
                        </p>
                    </div>

                </div>
            </section>

            <section class="form-section">
                <h2>Hospital Administrator</h2>

                <div class="form-grid">

                    <div class="form-group full-width">
                        <label for="admin_name">
                            Administrator's Full Name
                        </label>

                        <input
                            type="text"
                            id="admin_name"
                            name="admin_name"
                            value="<?= e($adminName) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="admin_email">
                            Administrator's Email
                        </label>

                        <input
                            type="email"
                            id="admin_email"
                            name="admin_email"
                            value="<?= e($adminEmail) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="admin_phone">
                            Administrator's Phone
                        </label>

                        <input
                            type="tel"
                            id="admin_phone"
                            name="admin_phone"
                            value="<?= e($adminPhone) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="admin_password">
                            Initial Password
                        </label>

                        <input
                            type="password"
                            id="admin_password"
                            name="admin_password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">
                            Confirm Password
                        </label>

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                </div>
            </section>

            <div class="form-actions">
                <button
                    type="submit"
                    class="submit-button"
                >
                    Register Hospital
                </button>

                <a
                    href="dashboard.php"
                    class="cancel-button"
                >
                    Cancel
                </a>
            </div>

        </form>

    </section>
</div>
```

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
