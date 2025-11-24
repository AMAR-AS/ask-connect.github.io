<?php
// api.php - Main API Handler
require_once 'config.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonInput();
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// Route requests
switch ($action) {
    case 'signup':
        handleSignup($input);
        break;
    
    case 'login':
        handleLogin($input);
        break;
    
    case 'logout':
        handleLogout();
        break;
    
    case 'get_profile':
        getProfile();
        break;
    
    case 'update_profile':
        updateProfile($input);
        break;
    
    case 'create_booking':
        createBooking($input);
        break;
    
    case 'get_bookings':
        getBookings();
        break;
    
    case 'get_booking':
        getBooking($input);
        break;
    
    case 'update_booking':
        updateBooking($input);
        break;
    
    case 'delete_booking':
        deleteBooking($input);
        break;
    
    default:
        sendResponse(false, 'Invalid action');
}

// ==================== AUTHENTICATION FUNCTIONS ====================

function handleSignup($data) {
    global $conn;
    
    $name = sanitize($data['name'] ?? '');
    $username = sanitize($data['username'] ?? '');
    $email = sanitize($data['email'] ?? '');
    $phone = sanitize($data['phone'] ?? '');
    $dob = sanitize($data['dob'] ?? '');
    $password = $data['password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';
    
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
    
    // Check if email already exists
    $check_sql = "SELECT id FROM users WHERE email = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        sendResponse(false, 'Email already registered');
    }
    
    // Check if username already exists
    $check_sql = "SELECT id FROM profiles WHERE username = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        sendResponse(false, 'Username already taken');
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Insert user
        $insert_user = "INSERT INTO users (email, password, email_verified) VALUES (?, ?, FALSE)";
        $stmt = $conn->prepare($insert_user);
        $stmt->bind_param("ss", $email, $hashed_password);
        $stmt->execute();
        $user_id = $conn->insert_id;
        
        // Insert profile
        $insert_profile = "INSERT INTO profiles (user_id, username, full_name, phone, dob) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_profile);
        $stmt->bind_param("issss", $user_id, $username, $name, $phone, $dob);
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        sendResponse(true, 'Account created successfully! You can now login.', [
            'user_id' => $user_id,
            'redirect' => 'index.html'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        sendResponse(false, 'Registration failed: ' . $e->getMessage());
    }
}

function handleLogin($data) {
    global $conn;
    
    $identifier = sanitize($data['identifier'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($identifier) || empty($password)) {
        sendResponse(false, 'All fields are required');
    }
    
    // Check if identifier is email
    $login_sql = "SELECT u.id, u.email, u.password, u.email_verified, p.username, p.full_name 
                  FROM users u 
                  LEFT JOIN profiles p ON u.id = p.user_id 
                  WHERE u.email = ?";
    $stmt = $conn->prepare($login_sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendResponse(false, 'Invalid email or password');
    }
    
    $user = $result->fetch_assoc();
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        sendResponse(false, 'Invalid email or password');
    }
    
    // Create session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    
    // Create session token
    $session_token = generateSessionToken();
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    
    $session_sql = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                    VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($session_sql);
    $stmt->bind_param("issss", $user['id'], $session_token, $ip_address, $user_agent, $expires_at);
    $stmt->execute();
    
    sendResponse(true, 'Login successful!', [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'session_token' => $session_token,
        'redirect' => 'home.html'
    ]);
}

function handleLogout() {
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        
        // Delete all sessions for this user
        global $conn;
        $sql = "DELETE FROM user_sessions WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    
    // Destroy session
    session_destroy();
    sendResponse(true, 'Logged out successfully');
}

// ==================== PROFILE FUNCTIONS ====================

function getProfile() {
    requireLogin();
    $user = getCurrentUser();
    
    if ($user) {
        sendResponse(true, 'Profile retrieved', $user);
    } else {
        sendResponse(false, 'User not found');
    }
}

function updateProfile($data) {
    requireLogin();
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    $full_name = sanitize($data['full_name'] ?? '');
    $phone = sanitize($data['phone'] ?? '');
    $dob = sanitize($data['dob'] ?? '');
    
    $sql = "UPDATE profiles SET full_name = ?, phone = ?, dob = ? WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $full_name, $phone, $dob, $user_id);
    
    if ($stmt->execute()) {
        sendResponse(true, 'Profile updated successfully');
    } else {
        sendResponse(false, 'Failed to update profile');
    }
}

// ==================== BOOKING FUNCTIONS ====================

function createBooking($data) {
    requireLogin();
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    $booking_type = sanitize($data['booking_type'] ?? '');
    $booking_data = json_encode($data['booking_data'] ?? []);
    $total_amount = floatval($data['total_amount'] ?? 0);
    
    if (empty($booking_type)) {
        sendResponse(false, 'Booking type is required');
    }
    
    // Insert into main bookings table
    $sql = "INSERT INTO bookings (user_id, booking_type, booking_data, total_amount, status, payment_status) 
            VALUES (?, ?, ?, ?, 'pending', 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issd", $user_id, $booking_type, $booking_data, $total_amount);
    
    if ($stmt->execute()) {
        $booking_id = $conn->insert_id;
        
        // Create notification
        $notif_sql = "INSERT INTO notifications (user_id, title, message, type) 
                      VALUES (?, 'Booking Created', 'Your booking has been created successfully!', 'success')";
        $notif_stmt = $conn->prepare($notif_sql);
        $notif_stmt->bind_param("i", $user_id);
        $notif_stmt->execute();
        
        sendResponse(true, 'Booking created successfully!', ['booking_id' => $booking_id]);
    } else {
        sendResponse(false, 'Failed to create booking');
    }
}

function getBookings() {
    requireLogin();
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    
    $sql = "SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $row['booking_data'] = json_decode($row['booking_data'], true);
        $bookings[] = $row;
    }
    
    sendResponse(true, 'Bookings retrieved', $bookings);
}

function getBooking($data) {
    requireLogin();
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    $booking_id = intval($data['booking_id'] ?? 0);
    
    $sql = "SELECT * FROM bookings WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $booking = $result->fetch_assoc();
        $booking['booking_data'] = json_decode($booking['booking_data'], true);
        sendResponse(true, 'Booking retrieved', $booking);
    } else {
        sendResponse(false, 'Booking not found');
    }
}

function updateBooking($data) {
    requireLogin();
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    $booking_id = intval($data['booking_id'] ?? 0);
    $status = sanitize($data['status'] ?? '');
    
    $sql = "UPDATE bookings SET status = ? WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $status, $booking_id, $user_id);
    
    if ($stmt->execute()) {
        sendResponse(true, 'Booking updated successfully');
    } else {
        sendResponse(false, 'Failed to update booking');
    }
}

function deleteBooking($data) {
    requireLogin();
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    $booking_id = intval($data['booking_id'] ?? 0);
    
    $sql = "DELETE FROM bookings WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $booking_id, $user_id);
    
    if ($stmt->execute()) {
        sendResponse(true, 'Booking deleted successfully');
    } else {
        sendResponse(false, 'Failed to delete booking');
    }
}

?>