<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Smart Queue Management System</title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >
</head>

<body>

    <!-- Navigation bar -->
    <header class="navbar">
        <div class="container navbar-content">

            <a href="index.php" class="logo">
                SmartQueue
            </a>

            <nav class="nav-links">
                <a href="#home">Home</a>
                <a href="#features">Features</a>
                <a href="#about">About</a>

                <a href="auth/login.php" class="btn btn-outline">
                    Log In
                </a>

                <a href="auth/register.php" class="btn btn-primary">
                    Get Started
                </a>
            </nav>

        </div>
    </header>


    <!-- Main welcome section -->
    <main>

        <section class="hero" id="home">
            <div class="container">

                <div class="hero-content">

                    <span class="hero-label">
                        Smarter Healthcare Queues
                    </span>

                    <h1>
                        Less Waiting.<br>
                        Better Healthcare.
                    </h1>

                    <p>
                        Book hospital appointments, track your queue,
                        and receive notifications when your turn is
                        approaching. A simpler way to manage your
                        hospital visits.
                    </p>

                    <div class="hero-buttons">

                        <a
                            href="auth/register.php"
                            class="btn btn-primary"
                        >
                            Get Started
                        </a>

                        <a
                            href="auth/login.php"
                            class="btn btn-outline"
                        >
                            Log In
                        </a>

                    </div>

                </div>

            </div>
        </section>


        <!-- Features section -->
        <section class="features" id="features">
            <div class="container">

                <div class="section-heading">

                    <h2>Healthcare Queues, Simplified</h2>

                    <p>
                        Designed to make hospital visits
                        more organized and convenient.
                    </p>

                </div>

                <div class="feature-grid">

                    <article class="feature-card">

                        <div class="feature-icon">📅</div>

                        <h3>Online Appointments</h3>

                        <p>
                            Book appointments at participating
                            hospitals without needing to make
                            your booking in person.
                        </p>

                    </article>

                    <article class="feature-card">

                        <div class="feature-icon">🎫</div>

                        <h3>Queue Tracking</h3>

                        <p>
                            View your queue number, check your
                            current status, and see when your
                            turn is approaching.
                        </p>

                    </article>

                    <article class="feature-card">

                        <div class="feature-icon">🔔</div>

                        <h3>In-App Notifications</h3>

                        <p>
                            Receive updates about appointments,
                            queue changes, and upcoming turns
                            through your account.
                        </p>

                    </article>

                </div>

            </div>
        </section>


        <!-- About section -->
        <section class="features" id="about">
            <div class="container">

                <div class="section-heading">

                    <h2>About SmartQueue</h2>

                    <p>
                        A hospital queue management platform
                        designed to improve patient communication,
                        appointment organization, and visibility
                        of waiting times.
                    </p>

                </div>

            </div>
        </section>

    </main>


    <!-- Footer -->
    <footer class="footer">
        <div class="container">

            <p>
                &copy; <?php echo date("Y"); ?>
                Smart Queue Management System.
                All rights reserved.
            </p>

        </div>
    </footer>

</body>
</html>