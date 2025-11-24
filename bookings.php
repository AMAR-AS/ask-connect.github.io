<?php
// bookings.php - Booking Management Handler

require_once 'config.php';

header('Content-Type: application/json');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Invalid request method');
}

$action = isset($_REQUEST['action']) ? sanitize($_REQUEST['action']) : '';

// ==================== CREATE BOOKING ====================
if ($action === 'create_booking') {
    
    requireAuth();
    
    $user_id = getCurrentUserId();
    $booking_type = sanitize($_POST['booking_type'] ?? '');
    $booking_data = $_POST['booking_data'] ?? '';
    $total_amount = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
    
    // Validation
    if (empty($booking_type) || empty($booking_data)) {
        sendResponse(false, 'Booking type and data are required');
    }
    
    $allowed_types = ['movie', 'bus', 'train', 'flight', 'guide', 'room', 'product', 'bike', 'cab', 'food', 'service'];
    if (!in_array($booking_type, $allowed_types)) {
        sendResponse(false, 'Invalid booking type');
    }
    
    // Convert booking_data to JSON if it's an array
    if (is_array($booking_data)) {
        $booking_data = json_encode($booking_data);
    }
    
    // Insert into main bookings table
    $insert_sql = "INSERT INTO bookings (user_id, booking_type, booking_data, total_amount, status, payment_status, created_at) 
                   VALUES (?, ?, ?, ?, 'pending', 'pending', NOW())";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("issd", $user_id, $booking_type, $booking_data, $total_amount);
    
    if ($insert_stmt->execute()) {
        $booking_id = $conn->insert_id;
        
        // Log activity
        logActivity($user_id, 'booking_created', "Created $booking_type booking #$booking_id");
        
        sendResponse(true, 'Booking created successfully!', [
            'booking_id' => $booking_id,
            'booking_type' => $booking_type,
            'status' => 'pending'
        ]);
    } else {
        sendResponse(false, 'Failed to create booking');
    }
}

// ==================== GET USER BOOKINGS ====================
elseif ($action === 'get_bookings') {
    
    requireAuth();
    
    $user_id = getCurrentUserId();
    
    $bookings_sql = "SELECT id, booking_type, booking_data, total_amount, status, payment_status, created_at, updated_at 
                     FROM bookings WHERE user_id = ? ORDER BY created_at DESC";
    $bookings_stmt = $conn->prepare($bookings_sql);
    $bookings_stmt->bind_param("i", $user_id);
    $bookings_stmt->execute();
    $result = $bookings_stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    
    sendResponse(true, 'Bookings retrieved', ['bookings' => $bookings]);
}

// ==================== GET BOOKING DETAILS ====================
elseif ($action === 'get_booking_details') {
    
    requireAuth();
    
    $user_id = getCurrentUserId();
    $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    
    if ($booking_id <= 0) {
        sendResponse(false, 'Invalid booking ID');
    }
    
    $booking_sql = "SELECT id, booking_type, booking_data, total_amount, status, payment_status, created_at, updated_at 
                    FROM bookings WHERE id = ? AND user_id = ?";
    $booking_stmt = $conn->prepare($booking_sql);
    $booking_stmt->bind_param("ii", $booking_id, $user_id);
    $booking_stmt->execute();
    $result = $booking_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $booking = $result->fetch_assoc();
        sendResponse(true, 'Booking details retrieved', $booking);
    } else {
        sendResponse(false, 'Booking not found');
    }
}

// ==================== UPDATE BOOKING STATUS ====================
elseif ($action === 'update_booking_status') {
    
    requireAuth();
    
    $user_id = getCurrentUserId();
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $status = sanitize($_POST['status'] ?? '');
    
    if ($booking_id <= 0 || empty($status)) {
        sendResponse(false, 'Invalid data');
    }
    
    $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'completed'];
    if (!in_array($status, $allowed_statuses)) {
        sendResponse(false, 'Invalid status');
    }
    
    // Check if booking belongs to user
    $check_sql = "SELECT id FROM bookings WHERE id = ? AND user_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $booking_id, $user_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows === 0) {
        sendResponse(false, 'Booking not found or access denied');
    }
    
    // Update status
    $update_sql = "UPDATE bookings SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sii", $status, $booking_id, $user_id);
    
    if ($update_stmt->execute()) {
        logActivity($user_id, 'booking_updated', "Updated booking #$booking_id to $status");
        sendResponse(true, 'Booking status updated', ['status' => $status]);
    } else {
        sendResponse(false, 'Failed to update booking');
    }
}

// ==================== CANCEL BOOKING ====================
elseif ($action === 'cancel_booking') {
    
    requireAuth();
    
    $user_id = getCurrentUserId();
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    
    if ($booking_id <= 0) {
        sendResponse(false, 'Invalid booking ID');
    }
    
    // Check if booking belongs to user and is cancellable
    $check_sql = "SELECT status FROM bookings WHERE id = ? AND user_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $booking_id, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendResponse(false, 'Booking not found');
    }
    
    $booking = $result->fetch_assoc();
    
    if ($booking['status'] === 'completed' || $booking['status'] === 'cancelled') {
        sendResponse(false, 'This booking cannot be cancelled');
    }
    
    // Cancel booking
    $cancel_sql = "UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND user_id = ?";
    $cancel_stmt = $conn->prepare($cancel_sql);
    $cancel_stmt->bind_param("ii", $booking_id, $user_id);
    
    if ($cancel_stmt->execute()) {
        logActivity($user_id, 'booking_cancelled', "Cancelled booking #$booking_id");
        sendResponse(true, 'Booking cancelled successfully');
    } else {
        sendResponse(false, 'Failed to cancel booking');
    }
}

// ==================== GET BOOKING STATISTICS ====================
elseif ($action === 'get_statistics') {
    
    requireAuth();
    
    $user_id = getCurrentUserId();
    
    // Count bookings by status
    $stats_sql = "SELECT 
                    COUNT(*) as total_bookings,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(total_amount) as total_spent
                  FROM bookings WHERE user_id = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->bind_param("i", $user_id);
    $stats_stmt->execute();
    $result = $stats_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stats = $result->fetch_assoc();
        sendResponse(true, 'Statistics retrieved', $stats);
    } else {
        sendResponse(false, 'No statistics available');
    }
}

// ==================== INVALID ACTION ====================
else {
    sendResponse(false, 'Invalid action');
}

$conn->close();
?>
