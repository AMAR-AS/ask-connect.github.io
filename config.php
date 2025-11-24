<?php
// config.php - Database Configuration for XAMPP
session_start();

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Default XAMPP MySQL user
define('DB_PASS', '');              // Default XAMPP MySQL password (empty)
define('DB_NAME', 'ask_connect');   // Your database name

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

// Set charset
$conn->set_charset("utf8mb4");

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Security Headers
header('Content-Type: application/json');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Helper Functions
function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(htmlspecialchars(trim($data)));
}

function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function generateSessionToken() {
    return bin2hex(random_bytes(32));
}

function sendResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Get JSON input for API calls
function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true);
}

// Check if user is logged in
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        sendResponse(false, 'Authentication required');
    }
}

// Get current user
function getCurrentUser() {
    global $conn;
    
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT u.*, p.* FROM users u 
            LEFT JOIN profiles p ON u.id = p.user_id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

?>