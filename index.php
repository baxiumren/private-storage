<?php
session_start();
require_once 'config.private.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$success = '';
$ip = $_SERVER['REMOTE_ADDR'];

// Tampilkan pesan dari redirect (timeout/hijack/logout)
if (isset($_GET['reason'])) {
    if ($_GET['reason'] === 'timeout')  $error = 'Session expired — please sign in again';
    if ($_GET['reason'] === 'hijack')   $error = 'Session security violation — please sign in again';
}
if (isset($_GET['message']) && $_GET['message'] === 'loggedout') {
    $success = 'You have been signed out successfully';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lockout_until = is_ip_locked($ip);
    if ($lockout_until) {
        $remaining = ceil(($lockout_until - time()) / 60);
        $error = 'Too many failed attempts — try again in ' . $remaining . ' min';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (isset($valid_users[$username]) && password_verify($password, $valid_users[$username])) {
            clear_login_attempts($ip);
            $_SESSION['loggedin']   = true;
            $_SESSION['username']   = $username;
            $_SESSION['ip']         = $ip;
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            $_SESSION['login_time'] = time();
            header('Location: dashboard.php');
            exit;
        } else {
            record_failed_attempt($ip);
            $rec  = get_login_attempts($ip);
            $left = MAX_LOGIN_ATTEMPTS - $rec['count'];
            if ($left > 0) {
                $error = 'Invalid credentials — ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining';
            } else {
                $error = 'Account locked — try again in ' . ceil(LOCKOUT_DURATION / 60) . ' min';
            }
            sleep(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Private Storage — Sign In</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --maroon:        #800000;
            --maroon-dark:   #5a0000;
            --maroon-light:  #a01818;
            --maroon-bright: #c02828;
            --gold:          #c9a84c;
            --gold-light:    #e8c97d;
            --bg:            #07000a;
            --glass:         rgba(160, 10, 10, 0.09);
            --glass-border:  rgba(180, 40, 40, 0.22);
            --text:          #f0e4e4;
            --text-dim:      #b09090;
            --text-muted:    #705060;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* ── Background orbs ── */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(90px);
            pointer-events: none;
            z-index: 0;
            animation: drift 10s ease-in-out infinite alternate;
        }
        .orb-1 {
            width: 520px; height: 520px;
            background: radial-gradient(circle, rgba(128,0,0,0.45) 0%, transparent 70%);
            top: -160px; left: -160px;
        }
        .orb-2 {
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(100,0,25,0.38) 0%, transparent 70%);
            bottom: -120px; right: -120px;
            animation-delay: -5s;
        }
        .orb-3 {
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(160,50,0,0.22) 0%, transparent 70%);
            top: 55%; left: 55%;
            animation-delay: -2.5s;
        }
        @keyframes drift {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(25px,18px) scale(1.06); }
        }

        /* Subtle grid */
        .bg-grid {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background-image:
                linear-gradient(rgba(180,40,40,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(180,40,40,0.04) 1px, transparent 1px);
            background-size: 44px 44px;
        }

        /* ── Card ── */
        .login-wrap {
            position: relative; z-index: 1;
            width: 100%; max-width: 420px; padding: 20px;
        }

        .card {
            background: rgba(18, 2, 8, 0.75);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 48px 40px;
            backdrop-filter: blur(32px);
            -webkit-backdrop-filter: blur(32px);
            box-shadow:
                inset 0 0 0 1px rgba(255,255,255,0.04),
                0 32px 80px rgba(0,0,0,0.65),
                0 0 60px rgba(128,0,0,0.18);
        }

        /* ── Logo ── */
        .logo { text-align: center; margin-bottom: 40px; }

        .logo-icon {
            width: 68px; height: 68px;
            background: linear-gradient(135deg, var(--maroon-dark), var(--maroon-bright));
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 18px;
            font-size: 30px;
            box-shadow: 0 8px 32px rgba(128,0,0,0.55), inset 0 1px 0 rgba(255,255,255,0.1);
        }

        .logo h1 {
            font-size: 22px; font-weight: 700; color: var(--text);
            letter-spacing: -0.4px; margin-bottom: 6px;
        }
        .logo p { font-size: 13px; color: var(--text-dim); }

        /* ── Error box ── */
        .error-box {
            background: rgba(180,0,0,0.13);
            border: 1px solid rgba(200,0,0,0.32);
            border-radius: 12px;
            padding: 13px 16px;
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; color: #ff9090; line-height: 1.4;
        }
        .error-box i { flex-shrink: 0; }

        /* ── Success box ── */
        .success-box {
            background: rgba(0,130,60,0.13);
            border: 1px solid rgba(0,180,80,0.32);
            border-radius: 12px;
            padding: 13px 16px;
            margin-bottom: 24px;
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; color: #7effc0; line-height: 1.4;
        }
        .success-box i { flex-shrink: 0; }

        /* ── Form ── */
        .form-group { margin-bottom: 20px; }

        .form-label {
            display: block;
            font-size: 11px; font-weight: 600;
            color: var(--text-dim);
            text-transform: uppercase; letter-spacing: 0.9px;
            margin-bottom: 8px;
        }

        .input-wrap { position: relative; }

        .input-icon {
            position: absolute; left: 15px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted); font-size: 13px;
            transition: color 0.2s; pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: 13px 15px 13px 42px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(180,40,40,0.2);
            border-radius: 12px;
            color: var(--text);
            font-size: 15px; font-family: 'Inter', sans-serif;
            outline: none; transition: all 0.2s;
        }
        .form-input::placeholder { color: rgba(176,144,144,0.35); }
        .form-input:focus {
            background: rgba(160,15,15,0.1);
            border-color: var(--maroon-bright);
            box-shadow: 0 0 0 3px rgba(128,0,0,0.18);
        }
        .input-wrap:focus-within .input-icon { color: var(--maroon-bright); }

        /* ── Button ── */
        .btn-login {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, var(--maroon-dark) 0%, var(--maroon-light) 100%);
            border: 1px solid rgba(200,40,40,0.35);
            border-radius: 12px;
            color: #fff; font-size: 15px; font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer; transition: all 0.22s; margin-top: 10px;
            box-shadow: 0 4px 22px rgba(128,0,0,0.42);
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-bright) 100%);
            box-shadow: 0 6px 32px rgba(128,0,0,0.62);
            transform: translateY(-1px);
        }
        .btn-login:active  { transform: translateY(0); }
        .btn-login:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

        /* ── Footer note ── */
        .card-footer {
            display: flex; align-items: center; justify-content: center;
            gap: 10px; margin-top: 28px;
            font-size: 11px; color: var(--text-muted);
        }
        .sep { width: 3px; height: 3px; border-radius: 50%; background: rgba(180,80,80,0.45); }

        @media (max-width: 480px) {
            .card { padding: 36px 22px; }
        }
    </style>
</head>
<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="bg-grid"></div>

    <div class="login-wrap">
        <div class="card">
            <div class="logo">
                <div class="logo-icon">🗄️</div>
                <h1>Private Storage</h1>
                <p>Secure personal file vault</p>
            </div>

            <?php if ($success): ?>
            <div class="success-box">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <div class="input-wrap">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="username" class="form-input"
                               placeholder="Enter username"
                               autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" class="form-input"
                               placeholder="Enter password"
                               autocomplete="current-password" required>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div class="card-footer">
                <span>Encrypted</span>
                <div class="sep"></div>
                <span>Session Protected</span>
                <div class="sep"></div>
                <span>Private</span>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
        });
    </script>
</body>
</html>
