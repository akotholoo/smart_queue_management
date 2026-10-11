<?php

session_start();

require_once __DIR__ . "/../config/database.php";

$errors = [];
$full_name = "";
$email = "";
$phone = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "") {
        $errors[] = "Please enter your full name.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (strlen($password) < 8) {
        $errors[] = "Your password must contain at least 8 characters.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Your passwords do not match.";
    }

    if (empty($errors)) {

        try {

            $checkEmail = $pdo->prepare(
                "SELECT user_id FROM users WHERE email = ?"
            );

            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {

                $errors[] = "An account with this email already exists.";

            } else {

                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $pdo->beginTransaction();

                $insertUser = $pdo->prepare(
                    "INSERT INTO users
                    (full_name, email, phone, password_hash, role)
                    VALUES (?, ?, ?, ?, 'patient')"
                );

                $insertUser->execute([
                    $full_name,
                    $email,
                    $phone !== "" ? $phone : null,
                    $password_hash
                ]);

                $user_id = (int) $pdo->lastInsertId();

                $insertPatient = $pdo->prepare(
                    "INSERT INTO patients (user_id)
                    VALUES (?)"
                );

                $insertPatient->execute([$user_id]);

                $pdo->commit();

                $_SESSION["success_message"] =
                    "Your account has been created successfully. You can now log in.";

                header("Location: login.php");
                exit;
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Registration error: " . $e->getMessage());

            $errors[] =
                "We could not create your account. Please try again.";
        }
    }
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

    <title>Patient Registration | SmartQueue</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .form-section {
            padding: 55px 0;
        }

        .form-card {
            max-width: 520px;
            margin: 0 auto;
            background: white;
            padding: 35px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.04);
        }

        .form-card h1 {
            color: #083344;
            margin-bottom: 8px;
        }

        .form-intro {
            color: #64748b;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        .form-group input:focus {
            outline: 2px solid #99f6e4;
            border-color: #0f766e;
        }

        .form-card .btn {
            width: 100%;
            margin-top: 5px;
        }

        .form-footer {
            margin-top: 20px;
            text-align: center;
        }

        .form-footer a {
            color: #0f766e;
            font-weight: bold;
        }

        .error-box {
            background: #fef2f2;
            color: #991b1b;
            padding: 15px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .error-box ul {
            padding-left: 20px;
        }
    </style>

</head>

<body>

    <header class="navbar">

        <div class="container navbar-content">

            <a href="../index.php" class="logo">
                SmartQueue
            </a>

            <nav class="nav-links">
                <a href="../index.php">Home</a>
                <a href="login.php">Log In</a>
            </nav>

        </div>

    </header>


    <main class="form-section">

        <div class="container">

            <div class="form-card">

                <h1>Create Your Account</h1>

                <p class="form-intro">
                    Register to book appointments and track your hospital queue.
                </p>


                <?php if (!empty($errors)): ?>

                    <div class="error-box">

                        <strong>Please correct the following:</strong>

                        <ul>
                            <?php foreach ($errors as $error): ?>

                                <li>
                                    <?= htmlspecialchars(
                                        $error,
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </li>

                            <?php endforeach; ?>
                        </ul>

                    </div>

                <?php endif; ?>


                <form method="POST" action="register.php">

                    <div class="form-group">

                        <label for="full_name">Full Name</label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            value="<?= htmlspecialchars(
                                $full_name,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            autocomplete="name"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="email">Email Address</label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars(
                                $email,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            autocomplete="email"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="phone">Phone Number (Optional)</label>

                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            value="<?= htmlspecialchars(
                                $phone,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            autocomplete="tel"
                        >

                    </div>


                    <div class="form-group">

                        <label for="password">Password</label>

                        <input
                            type="password"
                            id="password"
                            name="password"
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


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Create Account
                    </button>

                </form>


                <div class="form-footer">

                    <p>
                        Already have an account?
                        <a href="login.php">Log in</a>
                    </p>

                </div>

            </div>

        </div>

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