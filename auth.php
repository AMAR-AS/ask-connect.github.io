<?php
// auth.php - Authentication Handler

require_once 'config.php';

header('Content-Type: application/json');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$action = isset($_POST['action']) ? sanitize($_POST['action']) : '';

// ==================== SIGNUP ====================
if ($action === 'signup') {
    
    $name = sanitize($_POST['name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $dob = sanitize($_POST['dob'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($name) || empty($username) || empty($email) || empty($phone) || empty($dob) || empty($password)) {
        sendResponse(false, 'All fields are required');
    }
    
    if ($password !== $confirm_password) {
        sendResponse(false, 'Passwords do not match');
    }
    
    if (strlen($password) < 8) {
        sendResponse(false, 'Password must be at least 8 characters');
    }
    
    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, 'Invalid email address');
    }
    
    // Phone validation (10 digits)
    $clean_phone = preg_replace('/\D/', '', $phone);
    if (strlen($clean_phone) != 10) {
        sendResponse(false, 'Phone number must be 10 digits');
    }
    
    // Check if username already exists
    $check_sql = "SELECT id FROM users WHERE username = ? OR email = ? OR phone = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("sss", $username, $email, $clean_phone);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        sendResponse(false, 'Username, email, or phone already exists');
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    
    // Insert user
    $insert_sql = "INSERT INTO users (username, email, phone, password, full_name, dob, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("ssssss", $username, $email, $clean_phone, $hashed_password, $name, $dob);
    
    if ($insert_stmt->execute()) {
        $user_id = $conn->insert_id;
        
        // Generate and save OTP
        $otp = generateOTP();
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        $otp_sql = "INSERT INTO otp_verification (user_id, otp_code, otp_type, expires_at) VALUES (?, ?, 'email', ?)";
        $otp_stmt = $conn->prepare($otp_sql);
        $otp_stmt->bind_param("iss", $user_id, $otp, $expires_at);
        $otp_stmt->execute();
        
        // Log activity
        logActivity($user_id, 'signup', 'New user registered');
        
        // In production, send email with OTP here
        // For development, return OTP in response (REMOVE IN PRODUCTION!)
        
        sendResponse(true, 'Account created successfully! Please verify your email.', [
            'user_id' => $user_id,
            'otp' => $otp, // REMOVE THIS IN PRODUCTION
            'username' => $username
        ]);
    } else {
        sendResponse(false, 'Registration failed. Please try again.');
    }
}

// ==================== LOGIN ====================
elseif ($action === 'login') {
    
    $identifier = sanitize($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($identifier) || empty($password)) {
        sendResponse(false, 'All fields are required');
    }
    
    // Check user by username, email, or phone
    $login_sql = "SELECT id, username, email, password, email_verified, phone_verified, full_name FROM users 
                  WHERE username = ? OR email = ? OR phone = ?";
    $login_stmt = $conn->prepare($login_sql);
    $login_stmt->bind_param("sss", $identifier, $identifier, $identifier);
    $login_stmt->execute();
    $result = $login_stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendResponse(false, 'Invalid credentials');
    }
    
    $user = $result->fetch_assoc();
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        sendResponse(false, 'Invalid credentials');
    }
    
    // Check verification status (optional - can be disabled for testing)
    // if (!$user['email_verified']) {
    //     sendResponse(false, 'Please verify your email first', ['require_verification' => true]);
    // }
    
    // Create session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['full_name'] = $user['full_name'];
    
    // Create session token
    $session_token = generateSessionToken();
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    $session_sql = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                    VALUES (?, ?, ?, ?, ?)";
    $session_stmt = $conn->prepare($session_sql);
    $session_stmt->bind_param("issss", $user['id'], $session_token, $ip_address, $user_agent, $expires_at);
    $session_stmt->execute();
    
    // Log activity
    logActivity($user['id'], 'login', 'User logged in');
    
    sendResponse(true, 'Login successful!', [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'full_name' => $user['full_name'],
        'session_token' => $session_token,
        'redirect' => 'home.html'
    ]);
}

// ==================== VERIFY OTP ====================
elseif ($action === 'verify_otp') {
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $otp_code = sanitize($_POST['otp_code'] ?? '');
    
    if ($user_id <= 0 || empty($otp_code)) {
        sendResponse(false, 'Invalid data');
    }
    
    // Check OTP
    $otp_sql = "SELECT id FROM otp_verification 
                WHERE user_id = ? AND otp_code = ? AND otp_type = 'email' 
                AND expires_at > NOW() AND is_used = 0 
                ORDER BY created_at DESC LIMIT 1";
    $otp_stmt = $conn->prepare($otp_sql);
    $otp_stmt->bind_param("is", $user_id, $otp_code);
    $otp_stmt->execute();
    $otp_result = $otp_stmt->get_result();
    
    if ($otp_result->num_rows === 0) {
        sendResponse(false, 'Invalid or expired OTP');
    }
    
    $otp_record = $otp_result->fetch_assoc();
    
    // Mark OTP as used
    $update_otp_sql = "UPDATE otp_verification SET is_used = 1 WHERE id = ?";
    $update_otp_stmt = $conn->prepare($update_otp_sql);
    $update_otp_stmt->bind_param("i", $otp_record['id']);
    $update_otp_stmt->execute();
    
    // Update user verification status
    $verify_sql = "UPDATE users SET email_verified = 1 WHERE id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("i", $user_id);
    
    if ($verify_stmt->execute()) {
        logActivity($user_id, 'email_verified', 'Email verified successfully');
        sendResponse(true, 'Email verified successfully!', ['redirect' => 'index.html']);
    } else {
        sendResponse(false, 'Verification failed');
    }
}

// ==================== LOGOUT ====================
elseif ($action === 'logout') {
    
    $user_id = getCurrentUserId();
    
    if ($user_id) {
        logActivity($user_id, 'logout', 'User logged out');
    }
    
    // Destroy session
    session_unset();
    session_destroy();
    
    sendResponse(true, 'Logged out successfully', ['redirect' => 'index.html']);
}

// ==================== GET PROFILE ====================
elseif ($action === 'get_profile') {
    
    requireAuth();
    
    $user_id = getCurrentUserId();
    
    $profile_sql = "SELECT id, username, email, phone, full_name, dob, profile_pic, email_verified, phone_verified, created_at 
                    FROM users WHERE id = ?";
    $profile_stmt = $conn->prepare($profile_sql);
    $profile_stmt->bind_param("i", $user_id);
    $profile_stmt->execute();
    $result = $profile_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $profile = $result->fetch_assoc();
        sendResponse(true, 'Profile retrieved', $profile);
    } else {
        sendResponse(false, 'Profile not found');
    }
}

// ==================== INVALID ACTION ====================
else {
    sendResponse(false, 'Invalid action');
}

$conn->close();
?>
