<?php
require_once 'config.private.php';
secure_session_start();

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
            session_regenerate_id(true); // anti session-fixation: ID baru tiap login
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
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect x='6' y='6' width='88' height='88' rx='26' fill='%23055ff0'/><rect x='9' y='9' width='82' height='82' rx='23' fill='none' stroke='%23FFD700' stroke-width='5'/><circle cx='50' cy='50' r='19' fill='none' stroke='white' stroke-width='7'/><circle cx='50' cy='50' r='6' fill='%23FFD700'/><path d='M50 21v10M50 69v10M21 50h10M69 50h10' stroke='white' stroke-width='7' stroke-linecap='round'/></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="assets/css/cursor.css">
</head>
<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="bg-grid"></div>
    <div class="particles" id="particles"></div>

    <div class="login-wrap">
        <div class="card">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-vault"></i></div>
                <h1>Private Storage</h1>
                <p>Secure File Vault</p>
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

        // ══ Partikel emas & biru melayang ══
        (function(){
            if(matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const wrap = document.getElementById('particles');
            const COLORS = ['255,215,0','91,157,255','255,215,0','47,123,255']; // dominan gold
            for(let i = 0; i < 26; i++){
                const p = document.createElement('span');
                p.className = 'particle';
                const size = (Math.random() * 3 + 1.5).toFixed(1);
                const c = COLORS[i % COLORS.length];
                p.style.cssText = `
                    left:${(Math.random()*100).toFixed(1)}%;
                    width:${size}px;height:${size}px;
                    background:rgba(${c},.9);
                    box-shadow:0 0 ${size*3}px rgba(${c},.7);
                    --px:${(Math.random()*90-45).toFixed(0)}px;
                    --po:${(Math.random()*.5+.35).toFixed(2)};
                    animation-duration:${(Math.random()*14+9).toFixed(1)}s;
                    animation-delay:-${(Math.random()*20).toFixed(1)}s;`;
                wrap.appendChild(p);
            }
        })();

        // ══ 3D tilt kartu ngikut mouse ══
        (function(){
            if(matchMedia('(pointer: coarse)').matches) return;
            if(matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const card = document.querySelector('.card');
            const wrap = document.querySelector('.login-wrap');
            let raf = null;
            document.addEventListener('mousemove', e => {
                if(raf) return;
                raf = requestAnimationFrame(() => {
                    const r = wrap.getBoundingClientRect();
                    const cx = r.left + r.width/2, cy = r.top + r.height/2;
                    const dx = (e.clientX - cx) / (innerWidth/2);
                    const dy = (e.clientY - cy) / (innerHeight/2);
                    card.style.transform = `rotateY(${(dx*5).toFixed(2)}deg) rotateX(${(-dy*5).toFixed(2)}deg)`;
                    raf = null;
                });
            });
            document.addEventListener('mouseleave', () => { card.style.transform = 'rotateY(0) rotateX(0)'; });
        })();
    </script>
</body>
</html>
