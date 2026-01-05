<?php
session_start();
    // ===================================================
    // 
    // JUNE 2025
    // FANSOF / FAUZI'S FINAL PROJECT FOR COURSE : DATABASE 
    // 
    // 
    // 1. Pengguna yang mengisikan Login dan Password merupakan user MySql ✅ sudah terimplementasi sesuai userAccount MySQL
    // 2. Jika Login dan Password BENAR, selanjutnya terserah program anda  ✅ sudah terimplementasi, penggunaan dashboard sederhana
    // 3. Jika Login dan/atau Password user SALAH, ulangi maksimal 3 kali, termasuk yang pertama ✅ sudah terimplemetnasi
    // 4. Juka Login dan/atau Password salah 3 kali, user akan di-blokir selama-lamanya  ✅ sudah terimplementasi, ban permanent
    // ===================================================

// Konfigurasi database untuk sistem tracking login attempts
$admin_host = 'localhost';
$admin_user = 'PASSWORD'; // User admin untuk mengakses tabel tracking
$admin_pass = ''; // Ganti dengan password Admin MySQL Anda ---- Default XAMPP password. DO NOT use this in production.
$database = 'login_system';

// Buat koneksi admin dan database jika belum ada
try {
    // Koneksi tanpa database untuk membuat database
    $admin_pdo = new PDO("mysql:host=$admin_host", $admin_user, $admin_pass);
    $admin_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buat database jika belum ada
    $admin_pdo->exec("CREATE DATABASE IF NOT EXISTS $database");
    
    // Koneksi ke database yang sudah dibuat
    $admin_pdo = new PDO("mysql:host=$admin_host;dbname=$database", $admin_user, $admin_pass);
    $admin_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// Buat tabel tracking jika belum ada
$create_table = "CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    failed_attempts INT DEFAULT 0,
    blocked_until DATETIME NULL,
    last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_username (username)
)";

try {
    $admin_pdo->exec($create_table);
} catch(PDOException $e) {
    // Tabel mungkin sudah ada
}

// Fungsi untuk cek apakah user MySQL exist
function checkMySQLUserExists($username, $host = 'localhost') {
    global $admin_pdo;
    try {
        // Query untuk cek apakah user exist di MySQL
        $stmt = $admin_pdo->prepare("SELECT User FROM mysql.user WHERE User = ? AND Host = ?");
        $stmt->execute([$username, $host]);
        return $stmt->rowCount() > 0;
    } catch(PDOException $e) {
        // Jika tidak bisa akses mysql.user table, coba metode alternatif
        try {
            $test_pdo = new PDO("mysql:host=$host", $username, 'wrong_password_test');
            return false; // Tidak akan sampai sini jika user tidak exist
        } catch(PDOException $e) {
            // Cek error code untuk membedakan user tidak exist vs password salah
            $error_code = $e->getCode();
            if ($error_code == 1045) { // Access denied - user exist tapi password salah
                return true;
            } elseif ($error_code == 1044 || $error_code == 1049) { // User exist tapi tidak punya akses
                return true;
            } else {
                return false; // User tidak exist
            }
        }
    }
}

// Fungsi untuk cek apakah user diblokir
function isUserBlocked($username, $pdo) {
    $stmt = $pdo->prepare("SELECT blocked_until, failed_attempts FROM login_attempts WHERE username = ?");
    $stmt->execute([$username]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['blocked_until']) {
        $blocked_until = new DateTime($result['blocked_until']);
        $now = new DateTime();
        
        if ($now < $blocked_until) {
            $time_left = $blocked_until->diff($now);
            return [
                'blocked' => true, 
                'time_left' => $time_left->format('%Y tahun %m bulan %d hari %h jam %i menit %s detik')
            ];
        } else {
            // Reset blokir jika waktu sudah habis
            $reset_stmt = $pdo->prepare("UPDATE login_attempts SET blocked_until = NULL, failed_attempts = 0 WHERE username = ?");
            $reset_stmt->execute([$username]);
            return ['blocked' => false];
        }
    }
    
    return ['blocked' => false];
}

// Fungsi untuk update failed attempts
function updateFailedAttempts($username, $pdo) {
    $stmt = $pdo->prepare("SELECT failed_attempts FROM login_attempts WHERE username = ?");
    $stmt->execute([$username]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $failed_attempts = $result['failed_attempts'] + 1;
        if ($failed_attempts >= 3) {
            // Blokir user selama x menit setelah 3 kali gagal
            $blocked_until = new DateTime();
            // $blocked_until->add(new DateInterval('PT30M')); // 30 menit
            $permanent_block = new DateTime('2099-12-31 23:59:59'); // Blokir permanen
            
            $update_stmt = $pdo->prepare("UPDATE login_attempts SET failed_attempts = ?, blocked_until = ?, last_attempt = NOW() WHERE username = ?");
            $update_stmt->execute([$failed_attempts, $permanent_block->format('Y-m-d H:i:s'), $username]);
            return $failed_attempts;
        } else {
            $update_stmt = $pdo->prepare("UPDATE login_attempts SET failed_attempts = ?, last_attempt = NOW() WHERE username = ?");
            $update_stmt->execute([$failed_attempts, $username]);
            return $failed_attempts;
        }
    } else {
        // Insert new record
        $insert_stmt = $pdo->prepare("INSERT INTO login_attempts (username, failed_attempts, last_attempt) VALUES (?, 1, NOW())");
        $insert_stmt->execute([$username]);
        return 1;
    }
}

// Fungsi untuk reset failed attempts setelah login berhasil
function resetFailedAttempts($username, $pdo) {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = ?");
    $stmt->execute([$username]);
}

// Fungsi untuk test koneksi MySQL dengan kredensial user
function testMySQLConnection($username, $password, $host = 'localhost') {
    try {
        $test_pdo = new PDO("mysql:host=$host", $username, $password);
        $test_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return true;
    } catch(PDOException $e) {
        return false;
    }
}

$error_message = '';
$success_message = '';

// Proses login
if ($_POST && isset($_POST['username']) && isset($_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error_message = 'Username dan password harus diisi!';
    } else {
        // Cek apakah user MySQL exist
        if (!checkMySQLUserExists($username)) {
            $error_message = 'User tidak dikenali dalam sistem MySQL.';
        } else {
            // Cek apakah user diblokir
            $block_status = isUserBlocked($username, $admin_pdo);
            if ($block_status['blocked']) {
                $error_message = 'Akun Anda diblokir karena terlalu banyak percobaan login yang gagal. Silakan coba lagi dalam: ' . $block_status['time_left'];
            } else {
                // Test koneksi MySQL dengan kredensial user
                if (testMySQLConnection($username, $password)) {
                    // Login berhasil
                    resetFailedAttempts($username, $admin_pdo);
                    $_SESSION['logged_in'] = true;
                    $_SESSION['username'] = $username;
                    $success_message = 'Login berhasil! Selamat datang, ' . htmlspecialchars($username);
                } else {
                    // Login gagal - password salah
                    $failed_attempts = updateFailedAttempts($username, $admin_pdo);
                    
                    if ($failed_attempts >= 3) {
                        $error_message = 'Login gagal 3 kali. Akun Anda telah diblokir PERMANEN!.';
                    } else {
                        $remaining = 3 - $failed_attempts;
                        $error_message = "Password salah! Sisa percobaan: $remaining kali.";
                    }
                }
            }
        }
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Login MySQL User</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #096B68 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0;
            transition: background-color 0.4s, color 0.4s;
        }
        
        /* Login page container */
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        /* Dashboard full screen */
        .dashboard-container {
            background: #f8f9fa;
            padding: 0;
            border-radius: 0;
            box-shadow: none;
            width: 100vw;
            height: 100vh;
            max-width: 100vw;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            transition: background-color 0.4s;
        }
        
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            color: #333;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s, background-color 0.4s, color 0.4s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #667eea 0%, #096B68 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        
        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }
        
        .dashboard {
            padding: 0;
            margin: 0;
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background: #f8f9fa;
        }
        
        .header-bar {
            background: linear-gradient(135deg, #667eea 0%, #096B68 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .welcome-text {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .datetime-display {
            text-align: center;
            flex-grow: 1;
        }
        
        .datetime-display .date {
            font-size: 1.2rem;
            font-weight: 500;
        }
        
        .datetime-display .time {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            text-decoration: none;
            padding: 0.7rem 1.5rem;
            color: white;
            border-radius: 25px;
            transition: all 0.3s;
            border: 2px solid rgba(255,255,255,0.3);
            font-weight: 500;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .main-content {
            display: flex;
            flex: 1;
            height: calc(100vh - 80px); /* Adjust based on header bar height */
        }
        
        .sidebar {
            width: 300px;
            background: white;
            border-right: 1px solid #e0e0e0;
            padding: 2rem;
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            transition: background-color 0.4s, border-color 0.4s;
        }
        
        .profile-card {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #096B68 100%);
            border-radius: 15px;
            color: white;
            margin-bottom: 2rem;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }
        
        .profile-name {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .profile-role {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .nav-menu {
            list-style: none;
        }
        
        .nav-menu li {
            margin-bottom: 0.5rem;
        }
        
        .nav-menu a {
            display: flex;
            align-items: center;
            padding: 0.8rem 1rem;
            text-decoration: none;
            color: #555;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .nav-menu a:hover, .nav-menu a.active {
            background: linear-gradient(135deg, #667eea 0%, #096B68 100%);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-menu a i {
            margin-right: 0.8rem;
            font-size: 1.1rem;
        }
        
        .content-area {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #096B68 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .welcome-banner h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .welcome-banner p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
            transition: background-color 0.4s, border-color 0.4s;
        }
        
        .stat-card h3 {
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .activity-section {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: background-color 0.4s;
        }
        
        .activity-section h2 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: border-color 0.4s;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            background: #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 1rem;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.2rem;
        }
        
        .activity-time {
            font-size: 0.9rem;
            color: #666;
        }
        
        .info-box {
            background: #f0f8ff;
            border-left: 4px solid #007bff;
            padding: 1rem;
            margin-top: 2rem;
            border-radius: 0 5px 5px 0;
        }
        
        .info-box h3 {
            color: #007bff;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }
        
        .info-box p, .info-box ul {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
            padding-left: 1rem;
        }
        .info-box ul li {
            margin-bottom: 0.5rem;
        }
        
        /* Settings Page Styles */
        .settings-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }
        .settings-item label {
            color: #555;
            font-weight: 500;
        }
        .settings-select {
            padding: 0.5rem;
            border-radius: 5px;
            border: 1px solid #ddd;
            min-width: 150px;
        }
        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        /* Toggle Switch CSS */
        .switch {
        position: relative;
        display: inline-block;
        width: 60px;
        height: 34px;
        }
        .switch input { 
        opacity: 0;
        width: 0;
        height: 0;
        }
        .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        }
        .slider:before {
        position: absolute;
        content: "";
        height: 26px;
        width: 26px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
        }
        input:checked + .slider {
        background-color: #096B68;
        }
        input:focus + .slider {
        box-shadow: 0 0 1px #096B68;
        }
        input:checked + .slider:before {
        transform: translateX(26px);
        }
        .slider.round {
        border-radius: 34px;
        }
        .slider.round:before {
        border-radius: 50%;
        }
        
        /* ======================================== */
        /* ENHANCED DARK MODE THEME                 */
        /* ======================================== */
        body.dark-mode {
            background: #121212;
        }
        body.dark-mode .dashboard-container {
            background: #121212;
            color: #e0e0e0;
        }
        body.dark-mode .sidebar {
            background: #1e1e1e;
            border-right-color: #333;
        }
        body.dark-mode .content-area {
            background: #121212;
        }
        body.dark-mode .stat-card,
        body.dark-mode .activity-section,
        body.dark-mode .settings-select {
            background: #1e1e1e;
            color: #e0e0e0;
            border-color: #333;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        body.dark-mode h1,
        body.dark-mode h2,
        body.dark-mode h3,
        body.dark-mode .profile-name,
        body.dark-mode .nav-menu a,
        body.dark-mode .settings-item label,
        body.dark-mode .activity-title,
        body.dark-mode .form-group label {
            color: #f5f5f5;
        }
        body.dark-mode p,
        body.dark-mode .stat-label,
        body.dark-mode .activity-time,
        body.dark-mode .profile-role,
        body.dark-mode .header p,
        body.dark-mode .info-box p, 
        body.dark-mode .info-box ul {
            color: #b0b0b0;
        }
        body.dark-mode .nav-menu a:hover,
        body.dark-mode .nav-menu a.active {
            background: linear-gradient(135deg, #667eea 0%, #096B68 100%);
            color: white;
        }
        body.dark-mode .stat-card {
            border-left: 4px solid #667eea;
        }
        body.dark-mode .info-box {
            background: #1e1e1e;
            border-left-color: #007bff;
        }
        body.dark-mode .info-box h3 {
            color: #00aaff;
        }
        body.dark-mode .form-group input {
            background-color: #333;
            border-color: #555;
            color: #f5f5f5;
        }
        body.dark-mode .activity-item {
            border-bottom-color: #333;
        }

        .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1.5rem;
        }
        .data-table th, .data-table td {
            padding: 0.8rem 1rem;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        .data-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        body.dark-mode .data-table th {
            background-color: #2c2c2c;
            color: #f5f5f5;
        }
        body.dark-mode .data-table th, body.dark-mode .data-table td {
            border-bottom-color: #333;
        }
        .data-table tr:last-child td {
            border-bottom: none;
        }
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 15px;
            font-size: 0.8rem;
            color: white;
            font-weight: 500;
        }
        .status-fail {
            background-color: #dc3545;
        }

    </style>
</head>
<body>
    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']):?>
        <!-- Dashboard setelah login berhasil -->
         <?$activity_logs = [];

        try {
            // --- Fetch Activity Log ---
            $log_stmt = $admin_pdo->prepare("SELECT failed_attempts, blocked_until, last_attempt FROM login_attempts WHERE username = ? ORDER BY last_attempt DESC");
            $log_stmt->execute([$_SESSION['username']]);
            $activity_logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            // Silently fail if there's an issue fetching extra data
        }
    ?>
        <div class="dashboard-container">
                <!-- Header Bar -->
                <div class="header-bar">
                    <div class="welcome-text">
                        🎉 Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!
                    </div>
                    
                    <div class="datetime-display">
                        <div class="date" id="current-date"></div>
                        <div class="time" id="current-time"></div>
                    </div>
                    
                    <a href="?logout=1" class="logout-btn">🚪 Logout</a>
                </div>
                
                <!-- Main Content -->
                <div class="main-content">
                    <!-- Sidebar -->
                    <aside class="sidebar">
                        <div class="profile-card">
                            <div class="profile-avatar">👤</div>
                            <div class="profile-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                            <div class="profile-role">MySQL Database User</div>
                        </div>
                        
                        <nav>
                            <ul class="nav-menu">
                                <li><a href="#" class="active" onclick="showSection('home')">🏠 Homepage</a></li>
                                <li><a href="#" onclick="showSection('profile')">👤 My Profile</a></li>
                                <!-- <li><a href="#" onclick="showSection('database')">🗄️ Database Info</a></li> -->
                                <li><a href="#" onclick="showSection('activity')">📈 Activity Log</a></li>
                                <li><a href="#" onclick="showSection('settings')">⚙️ Settings</a></li>
                            </ul>
                        </nav>
                    </aside>
                    
                    <!-- Content Area -->
                    <main class="content-area">
                        <div id="home-section" class="content-section">
                            <div class="welcome-banner">
                                <h1 data-lang="en:🚀 Dashboard|id:🚀 Dasbor">🚀 Dashboard</h1>
                                <p data-lang="en:Welcome to your MySQL database management system|id:Selamat datang di sistem manajemen database MySQL Anda">Welcome to your MySQL database management system</p>
                            </div>
                            
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <h3 data-lang="en:📊 Connection Status|id:📊 Status Koneksi">📊 Connection Status</h3>
                                    <div class="stat-value">✅</div>
                                    <div class="stat-label" data-lang="en:Connected Successfully|id:Terhubung dengan Sukses">Connected Successfully</div>
                                </div>
                                
                                <div class="stat-card">
                                    <h3 data-lang="en:🕒 Login Time|id:🕒 Waktu Login">🕒 Login Time</h3>
                                    <div class="stat-value" id="login-time"></div>
                                    <div class="stat-label" data-lang="en:Session Started|id:Sesi Dimulai">Session Started</div>
                                </div>
                                
                                <div class="stat-card">
                                    <h3 data-lang="en:🔐 Security Status|id:🔐 Status Keamanan">🔐 Security Status</h3>
                                    <div class="stat-value">🛡️</div>
                                    <div class="stat-label" data-lang="en:Secure Connection|id:Koneksi Aman">Secure Connection</div>
                                </div>
                                
                                <div class="stat-card">
                                    <h3 data-lang="en:🏆 Access Level|id:🏆 Tingkat Akses">🏆 Access Level</h3>
                                    <div class="stat-value">👑</div>
                                    <div class="stat-label" data-lang="en:MySQL User|id:Pengguna MySQL">MySQL User</div>
                                </div>
                            </div>
                            
                            <div class="activity-section">
                                <h2 data-lang="en:📈 Recent Activity|id:📈 Aktivitas Terkini">📈 Recent Activity</h2>
                                <div class="activity-item">
                                    <div class="activity-icon">✅</div>
                                    <div class="activity-content">
                                        <div class="activity-title" data-lang="en:Successful Login|id:Login Berhasil">Successful Login</div>
                                        <div class="activity-time" data-lang="en:Just now|id:Baru saja">Just now</div>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon">🔗</div>
                                    <div class="activity-content">
                                        <div class="activity-title" data-lang="en:Database Connection Established|id:Koneksi Database Dibuat">Database Connection Established</div>
                                        <div class="activity-time" data-lang="en:Just now|id:Baru saja">Just now</div>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon">🛡️</div>
                                    <div class="activity-content">
                                        <div class="activity-title" data-lang="en:Security Check Passed|id:Pemeriksaan Keamanan Lulus">Security Check Passed</div>
                                        <div class="activity-time" data-lang="en:Just now|id:Baru saja">Just now</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="profile-section" class="content-section" style="display: none;">
                            <div class="welcome-banner">
                                <h1 data-lang="en:👤 User Profile|id:👤 Profil Pengguna">👤 User Profile</h1>
                                <p data-lang="en:Detailed information for your MySQL account|id:Informasi detail untuk akun MySQL Anda">Detailed information for your MySQL account</p>
                            </div>
                            
                            <div class="activity-section">
                                <h2 data-lang="en:📋 Profile Information|id:📋 Informasi Profil">📋 Profile Information</h2>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                                    <div>
                                        <h3 style="color: #667eea; margin-bottom: 1rem;" data-lang="en:🆔 Account Details|id:🆔 Detail Akun">🆔 Account Details</h3>
                                        <p><strong data-lang="en:Username:|id:Nama Pengguna:">Username:</strong> <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                                        <p><strong data-lang="en:User Type:|id:Tipe Pengguna:">User Type:</strong> <span data-lang="en:MySQL Database User|id:Pengguna Database MySQL">MySQL Database User</span></p>
                                        <p><strong data-lang="en:Connection Host:|id:Host Koneksi:">Connection Host:</strong> localhost</p>
                                        <p><strong data-lang="en:Login Status:|id:Status Login:">Login Status:</strong> <span style="color: green;" data-lang="en:✅ Active|id:✅ Aktif">✅ Active</span></p>
                                    </div>
                                    <div>
                                        <h3 style="color: #667eea; margin-bottom: 1rem;" data-lang="en:🔐 Security Info|id:🔐 Info Keamanan">🔐 Security Info</h3>
                                        <p><strong data-lang="en:Authentication:|id:Autentikasi:">Authentication:</strong> <span data-lang="en:MySQL Native|id:Native MySQL">MySQL Native</span></p>
                                        <p><strong data-lang="en:Last Login:|id:Login Terakhir:">Last Login:</strong> <span id="last-login"></span></p>
                                        <p><strong data-lang="en:Session Duration:|id:Durasi Sesi:">Session Duration:</strong> <span id="session-duration"></span></p>
                                        <p><strong data-lang="en:Security Level:|id:Tingkat Keamanan:">Security Level:</strong> <span style="color: green;" data-lang="en:🛡️ High|id:🛡️ Tinggi">🛡️ High</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="activity-section" class="content-section" style="display: none;">
                            <div class="welcome-banner">
                                <h1 data-lang="en:📈 Activity Log|id:📈 Log Aktivitas">📈 Activity Log</h1>
                                <p data-lang="en:History of failed login attempts for your account|id:Riwayat percobaan login gagal untuk akun Anda">History of failed login attempts for your account</p>
                            </div>
                            <div class="activity-section">
                                <h2 data-lang="en:Login Attempt History|id:Riwayat Percobaan Login">Login Attempt History</h2>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th data-lang="en:Timestamp|id:Waktu">Timestamp</th>
                                            <th data-lang="en:Event|id:Kejadian">Event</th>
                                            <th data-lang="en:Blocked Until|id:Diblokir Hingga">Blocked Until</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($activity_logs)): ?>
                                            <?php foreach ($activity_logs as $log): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($log['last_attempt']); ?></td>
                                                <td>
                                                    <span class="status-badge status-fail" data-lang="en:Failed Login Attempt|id:Percobaan Login Gagal">Failed Login Attempt</span>
                                                    (<?php echo htmlspecialchars($log['failed_attempts']); ?>x)
                                                </td>
                                                <td>
                                                    <?php 
                                                        echo $log['blocked_until'] ? htmlspecialchars($log['blocked_until']) : 'N/A'; 
                                                    ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" style="text-align: center;" data-lang="en:No failed login attempts recorded for this user.|id:Tidak ada catatan percobaan login gagal untuk pengguna ini.">No failed login attempts recorded for this user.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div id="settings-section" class="content-section" style="display: none;">
                            <div class="welcome-banner">
                                <h1 data-lang="en:⚙️ Settings|id:⚙️ Pengaturan">⚙️ Settings</h1>
                                <p data-lang="en:System settings and user preferences|id:Pengaturan sistem dan preferensi pengguna">System settings and user preferences</p>
                            </div>
                            
                            <div class="activity-section">
                                <h2 data-lang="en:Appearance|id:Tampilan">Appearance</h2>
                                <div class="settings-item">
                                    <label for="theme-switcher" data-lang="en:Theme Mode|id:Mode Tema">Theme Mode</label>
                                    <div class="toggle-switch">
                                        <span data-lang="en:Light|id:Terang">Light</span>
                                        <label class="switch">
                                            <input type="checkbox" id="theme-switcher">
                                            <span class="slider round"></span>
                                        </label>
                                        <span data-lang="en:Dark|id:Gelap">Dark</span>
                                    </div>
                                </div>

                                <h2 style="margin-top: 2rem;" data-lang="en:Language|id:Bahasa">Language</h2>
                                <div class="settings-item">
                                    <label for="language-selector" data-lang="en:Interface Language|id:Bahasa Antarmuka">Interface Language</label>
                                    <select id="language-selector" class="settings-select">
                                        <option value="en">English (US)</option>
                                        <option value="id">Bahasa Indonesia</option>
                                    </select>
                                </div>

                                <div style="margin-top: 3rem; text-align: center;">
                                    <button id="reset-settings-btn" class="logout-btn" style="background: #dc3545; border-color: #dc3545;" data-lang="en:Reset All Settings|id:Atur Ulang Semua Pengaturan">Reset All Settings</button>
                                </div>
                            </div>
                        </div>
                    </main>
                </div>
            </div>
            
            <script>
                // Function to get current language from localStorage
                function getCurrentLanguage() {
                    return localStorage.getItem('language') || 'en';
                }

                // --- Dashboard Functions ---
                
                // Update date and time with language support
                function updateDateTime() {
                    const now = new Date();
                    const lang = getCurrentLanguage() === 'id' ? 'id-ID' : 'en-US';
                    
                    const options = { 
                        weekday: 'long', 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric' 
                    };
                    const dateString = now.toLocaleDateString(lang, options);
                    const timeString = now.toLocaleTimeString(lang);
                    
                    const dateEl = document.getElementById('current-date');
                    const timeEl = document.getElementById('current-time');
                    if (dateEl) dateEl.textContent = dateString;
                    if (timeEl) timeEl.textContent = timeString;
                }
                
                // Update login time with language support
                function updateLoginTime() {
                    const now = new Date();
                    const lang = getCurrentLanguage() === 'id' ? 'id-ID' : 'en-US';
                    const timeString = now.toLocaleTimeString(lang, {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    const fullTimeString = now.toLocaleString(lang);

                    const loginTimeEl = document.getElementById('login-time');
                    const lastLoginEl = document.getElementById('last-login');
                    if (loginTimeEl) loginTimeEl.textContent = timeString;
                    if (lastLoginEl) lastLoginEl.textContent = fullTimeString;
                }
                
                // Update session duration
                let sessionStart = new Date();
                function updateSessionDuration() {
                    const now = new Date();
                    const duration = Math.floor((now - sessionStart) / 1000);
                    const minutes = Math.floor(duration / 60);
                    const seconds = duration % 60;
                    
                    const durationEl = document.getElementById('session-duration');
                    if (durationEl) durationEl.textContent = `${minutes}m ${seconds}s`;
                }
                
                // Show different sections
                function showSection(sectionName) {
                    // Hide all sections
                    const sections = document.querySelectorAll('.content-section');
                    sections.forEach(section => section.style.display = 'none');
                    
                    // Show selected section
                    document.getElementById(sectionName + '-section').style.display = 'block';
                    
                    // Update active nav
                    const navLinks = document.querySelectorAll('.nav-menu a');
                    navLinks.forEach(link => link.classList.remove('active'));
                    event.currentTarget.classList.add('active'); // Use event.currentTarget for reliability
                }

                // --- Settings Functions ---
                document.addEventListener('DOMContentLoaded', () => {
                    const themeSwitcher = document.getElementById('theme-switcher');
                    const languageSelector = document.getElementById('language-selector');
                    const resetBtn = document.getElementById('reset-settings-btn');

                    const translations = {
                        en: {
                            "Welcome, ": "🎉 Welcome, ",
                            "Homepage": "🏠 Homepage",
                            "My Profile": "👤 My Profile",
                            "Activity Log": "📈 Activity Log",
                            "Settings": "⚙️ Settings",
                            "🚀 Dashboard": "🚀 Dashboard",
                            "Welcome to your MySQL database management system": "Welcome to your MySQL database management system",
                            "📊 Connection Status": "📊 Connection Status",
                            "Connected Successfully": "Connected Successfully",
                            "🕒 Login Time": "🕒 Login Time",
                            "Session Started": "Session Started",
                            "🔐 Security Status": "🔐 Security Status",
                            "Secure Connection": "Secure Connection",
                            "🏆 Access Level": "🏆 Access Level",
                            "MySQL User": "MySQL User",
                            "📈 Recent Activity": "📈 Recent Activity",
                            "Successful Login": "Successful Login",
                            "Just now": "Just now",
                            "Database Connection Established": "Database Connection Established",
                            "Security Check Passed": "Security Check Passed",
                            "👤 User Profile": "👤 User Profile",
                            "Detailed information for your MySQL account": "Detailed information for your MySQL account",
                            "📋 Profile Information": "📋 Profile Information",
                            "🆔 Account Details": "🆔 Account Details",
                            "Username:": "Username:",
                            "User Type:": "User Type:",
                            "MySQL Database User": "MySQL Database User",
                            "Connection Host:": "Connection Host:",
                            "Login Status:": "Login Status:",
                            "✅ Active": "✅ Active",
                            "🔐 Security Info": "🔐 Security Info",
                            "Authentication:": "Authentication:",
                            "MySQL Native": "MySQL Native",
                            "Last Login:": "Last Login:",
                            "Session Duration:": "Session Duration:",
                            "Security Level:": "Security Level:",
                            "🛡️ High": "🛡️ High",
                            "📈 Activity Log": "📈 Activity Log",
                            "History of failed login attempts for your account": "History of failed login attempts for your account",
                            "Login Attempt History": "Login Attempt History",
                            "Timestamp": "Timestamp",
                            "Event": "Event",
                            "Blocked Until": "Blocked Until",
                            "Failed Login Attempt": "Failed Login Attempt",
                            "No failed login attempts recorded for this user.": "No failed login attempts recorded for this user.",
                            "⚙️ Settings": "⚙️ Settings",
                            "System settings and user preferences": "System settings and user preferences",
                            "Appearance": "Appearance",
                            "Theme Mode": "Theme Mode",
                            "Light": "Light",
                            "Dark": "Dark",
                            "Language": "Language",
                            "Interface Language": "Interface Language",
                            "Reset All Settings": "Reset All Settings"
                        },
                        id: {
                            "Welcome, ": "🎉 Selamat Datang, ",
                            "Homepage": "🏠 Halaman Utama",
                            "My Profile": "👤 Profil Saya",
                            "Activity Log": "📈 Log Aktivitas",
                            "Settings": "⚙️ Pengaturan",
                            "🚀 Dashboard": "🚀 Dasbor",
                            "Welcome to your MySQL database management system": "Selamat datang di sistem manajemen database MySQL Anda",
                            "📊 Connection Status": "📊 Status Koneksi",
                            "Connected Successfully": "Terhubung dengan Sukses",
                            "🕒 Login Time": "🕒 Waktu Login",
                            "Session Started": "Sesi Dimulai",
                            "🔐 Security Status": "🔐 Status Keamanan",
                            "Secure Connection": "Koneksi Aman",
                            "🏆 Access Level": "🏆 Tingkat Akses",
                            "MySQL User": "Pengguna MySQL",
                            "📈 Recent Activity": "📈 Aktivitas Terkini",
                            "Successful Login": "Login Berhasil",
                            "Just now": "Baru saja",
                            "Database Connection Established": "Koneksi Database Dibuat",
                            "Security Check Passed": "Pemeriksaan Keamanan Lulus",
                            "👤 User Profile": "👤 Profil Pengguna",
                            "Detailed information for your MySQL account": "Informasi detail untuk akun MySQL Anda",
                            "📋 Profile Information": "📋 Informasi Profil",
                            "🆔 Account Details": "🆔 Detail Akun",
                            "Username:": "Nama Pengguna:",
                            "User Type:": "Tipe Pengguna:",
                            "MySQL Database User": "Pengguna Database MySQL",
                            "Connection Host:": "Host Koneksi:",
                            "Login Status:": "Status Login:",
                            "✅ Active": "✅ Aktif",
                            "🔐 Security Info": "🔐 Info Keamanan",
                            "Authentication:": "Autentikasi:",
                            "MySQL Native": "Native MySQL",
                            "Last Login:": "Login Terakhir:",
                            "Session Duration:": "Durasi Sesi:",
                            "Security Level:": "Tingkat Keamanan:",
                            "🛡️ High": "🛡️ Tinggi",
                            "📈 Activity Log": "📈 Log Aktivitas",
                            "History of failed login attempts for your account": "Riwayat percobaan login gagal untuk akun Anda",
                            "Login Attempt History": "Riwayat Percobaan Login",
                            "Timestamp": "Waktu",
                            "Event": "Kejadian",
                            "Blocked Until": "Diblokir Hingga",
                            "Failed Login Attempt": "Percobaan Login Gagal",
                            "No failed login attempts recorded for this user.": "Tidak ada catatan percobaan login gagal untuk pengguna ini.",
                            "⚙️ Settings": "⚙️ Pengaturan",
                            "System settings and user preferences": "Pengaturan sistem dan preferensi pengguna",
                            "Appearance": "Tampilan",
                            "Theme Mode": "Mode Tema",
                            "Light": "Terang",
                            "Dark": "Gelap",
                            "Language": "Bahasa",
                            "Interface Language": "Bahasa Antarmuka",
                            "Reset All Settings": "Atur Ulang Semua Pengaturan"
                        }
                    };

                    const applySettings = () => {
                        const currentTheme = localStorage.getItem('theme') || 'light';
                        document.body.className = currentTheme === 'dark' ? 'dark-mode' : '';
                        if(themeSwitcher) themeSwitcher.checked = currentTheme === 'dark';

                        const currentLang = getCurrentLanguage();
                        if(languageSelector) languageSelector.value = currentLang;
                        translateUI(currentLang);
                        
                        updateDateTime();
                        updateLoginTime();
                    };

                    const translateUI = (lang) => {
                        document.querySelectorAll('[data-lang]').forEach(el => {
                            const keyEntry = el.dataset.lang.split('|').find(s => s.startsWith(lang + ':'));
                            if (keyEntry) {
                                const key = keyEntry.substring(keyEntry.indexOf(':') + 1);
                                el.textContent = key;
                            }
                        });
                    };
                    
                    if (themeSwitcher) {
                        themeSwitcher.addEventListener('change', (e) => {
                            const theme = e.target.checked ? 'dark' : 'light';
                            localStorage.setItem('theme', theme);
                            document.body.className = theme === 'dark' ? 'dark-mode' : '';
                        });
                    }

                    if (languageSelector) {
                        languageSelector.addEventListener('change', (e) => {
                            const lang = e.target.value;
                            localStorage.setItem('language', lang);
                            translateUI(lang);
                            updateDateTime();
                            updateLoginTime();
                        });
                    }
                    
                    if (resetBtn) {
                        resetBtn.addEventListener('click', () => {
                            if (confirm('Are you sure you want to reset all settings to their defaults?')) {
                                localStorage.removeItem('theme');
                                localStorage.removeItem('language');
                                applySettings();
                                alert('Settings have been reset to default.');
                            }
                        });
                    }

                    applySettings();
                    setInterval(updateDateTime, 1000);
                    setInterval(updateSessionDuration, 1000);
                });
            </script>
            <script>
            // ===================================================
            // SCRIPT FOR NEW SETTINGS PAGE
            // ===================================================
            document.addEventListener('DOMContentLoaded', () => {
                const themeSwitcher = document.getElementById('theme-switcher');
                const languageSelector = document.getElementById('language-selector');
                const resetBtn = document.getElementById('reset-settings-btn');

                const translations = {
                    en: {
                        "⚙️ Settings": "⚙️ Settings",
                        "System settings and user preferences": "System settings and user preferences",
                        "Appearance": "Appearance",
                        "Theme Mode": "Theme Mode",
                        "Light": "Light",
                        "Dark": "Dark",
                        "Language": "Language",
                        "Interface Language": "Interface Language",
                        // "Session & Security": "Session & Security",
                        // "Enable session timeout warning": "Enable session timeout warning",
                        "Reset All Settings": "Reset All Settings",
                        "Welcome, ": "🎉 Welcome, ",
                        "Homepage": "🏠 Homepage",
                        "My Profile": "👤 My Profile",
                        "Database Info": "🗄️ Database Info",
                        "Activity Log": "📈 Activity Log",
                        "Settings": "⚙️ Settings",
                        "Dashboard": "🚀 Dashboard",
                        "Welcome to your MySQL database management system": "Welcome to your MySQL database management system",
                        "User Profile": "👤 User Profile",
                        "Details of your MySQL account": "Details of your MySQL account"
                    },
                    id: {
                        "⚙️ Settings": "⚙️ Pengaturan",
                        "System settings and user preferences": "Pengaturan sistem dan preferensi pengguna",
                        "Appearance": "Tampilan",
                        "Theme Mode": "Mode Tema",
                        "Light": "Terang",
                        "Dark": "Gelap",
                        "Language": "Bahasa",
                        "Interface Language": "Bahasa Tampilan",
                        // "Session & Security": "Sesi & Keamanan",
                        // "Enable session timeout warning": "Aktifkan peringatan batas waktu sesi",
                        "Reset All Settings": "Atur Ulang Semua Pengaturan",
                        "Welcome, ": "🎉 Selamat Datang, ",
                        "Homepage": "🏠 Halaman Utama",
                        "My Profile": "👤 Profil Saya",
                        "Database Info": "🗄️ Info Database",
                        "Activity Log": "📈 Log Aktivitas",
                        "Settings": "⚙️ Pengaturan",
                        "Dashboard": "🚀 Dasbor",
                        "Welcome to your MySQL database management system": "Selamat datang di sistem manajemen database MySQL Anda",
                        "User Profile": "👤 Profil Pengguna",
                        "Details of your MySQL account": "Informasi detail akun MySQL Anda"
                    }
                };

                // Function to apply settings
                const applySettings = () => {
                    // Apply Theme
                    const currentTheme = localStorage.getItem('theme') || 'light';
                    document.body.className = currentTheme === 'dark' ? 'dark-mode' : '';
                    themeSwitcher.checked = currentTheme === 'dark';

                    // Apply Language
                    const currentLang = localStorage.getItem('language') || 'en';
                    languageSelector.value = currentLang;
                    translateUI(currentLang);
                };

                // Function to translate UI
                const translateUI = (lang) => {
                    document.querySelectorAll('[data-lang]').forEach(el => {
                        const key = el.dataset.lang.split('|').find(s => s.startsWith(lang === 'id' ? 'id:' : 'en:')).split(':')[1];
                        if (key) {
                            el.textContent = key;
                        }
                    });
                };
                
                // Theme switcher event
                themeSwitcher.addEventListener('change', (e) => {
                    const theme = e.target.checked ? 'dark' : 'light';
                    localStorage.setItem('theme', theme);
                    document.body.className = theme === 'dark' ? 'dark-mode' : '';
                });

                // Language selector event
                languageSelector.addEventListener('change', (e) => {
                    const lang = e.target.value;
                    localStorage.setItem('language', lang);
                    translateUI(lang);
                });
                
                // Reset settings event
                resetBtn.addEventListener('click', () => {
                    if (confirm('Are you sure you want to reset all settings to their defaults?')) {
                        localStorage.removeItem('theme');
                        localStorage.removeItem('language');
                        applySettings();
                        alert('Settings have been reset to default.');
                    }
                });

                // Load settings on page load
                applySettings();
            });
            </script>
        <?php else: ?>
            <div class="login-container">
            <!-- Form Login -->
                <div class="header">
                    <h1>🔐 Login MySQL User</h1>
                    <p>Masukkan kredensial MySQL Anda</p>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">👤 Username MySQL:</label>
                        <input type="text" id="username" name="username" 
                            value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                            required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">🔑 Password MySQL:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <button type="submit" class="btn">Masuk</button>
                </form>
                
                <div class="info-box">
                    <h3>Informasi Sistem</h3>
                    <p>
                        • Gunakan kredensial user MySQL yang valid</br>
                        • User yang tidak terdaftar akan ditolak</br>
                        • Maksimal 3 kali percobaan login</br>
                        • Setelah 3 kali gagal, akun akan diblokir secara PERMANEN!</br>
                        • Sistem menggunakan autentikasi MySQL langsung</br>
                </p>
                </div>
            
            </div>
        <?php endif; ?>
    </body>
</html>
