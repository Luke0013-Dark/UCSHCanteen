<?php
// ##########################################
// # 1. DATABASE & SESSION START            #
// ##########################################
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// =============================================
// HANDLE ADD POINTS (WITH REDIRECT)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_points') {
    $phone = trim($_POST['phone']);
    $points_to_add = intval($_POST['points']);
    
    if (empty($phone) || $points_to_add <= 0) {
        $_SESSION['add_points_msg'] = "ဖုန်းနံပါတ်နှင့် Point ပမာဏကို မှန်ကန်စွာ ဖြည့်ပါ။";
        $_SESSION['add_points_type'] = "danger";
    } else {
        $checkUser = $conn->prepare("SELECT userId, username, phoneNumber, points FROM users WHERE phoneNumber = ?");
        $checkUser->bind_param("s", $phone);
        $checkUser->execute();
        $userResult = $checkUser->get_result();
        
        if ($userResult->num_rows > 0) {
            $found_user = $userResult->fetch_assoc();
            $newPoints = $found_user['points'] + $points_to_add;
            
            $updateStmt = $conn->prepare("UPDATE users SET points = ? WHERE userId = ?");
            $updateStmt->bind_param("ii", $newPoints, $found_user['userId']);
            if ($updateStmt->execute()) {
                $_SESSION['add_points_msg'] = $found_user['username'] . " အတွက် " . number_format($points_to_add) . " Points ကို အောင်မြင်စွာ ထည့်သွင်းပြီးပါပြီ။";
                $_SESSION['add_points_type'] = "success";
                $_SESSION['found_user'] = $found_user;
                $_SESSION['found_user']['points'] = $newPoints;
            } else {
                $_SESSION['add_points_msg'] = "Point ထည့်သွင်းရာတွင် အမှားရှိပါသည်။";
                $_SESSION['add_points_type'] = "danger";
            }
            $updateStmt->close();
        } else {
            $_SESSION['add_points_msg'] = "ဤဖုန်းနံပါတ်ဖြင့် User မရှိပါ။";
            $_SESSION['add_points_type'] = "danger";
        }
        $checkUser->close();
    }
    
    // ✅ IMPORTANT: Redirect to prevent resubmission on refresh
    header("Location: admin.php");
    exit();
}

// =============================================
// ✅ GET SESSION MESSAGES
// =============================================
$add_points_msg = $_SESSION['add_points_msg'] ?? "";
$add_points_type = $_SESSION['add_points_type'] ?? "";
$found_user = $_SESSION['found_user'] ?? null;

// Clear session messages
unset($_SESSION['add_points_msg']);
unset($_SESSION['add_points_type']);
unset($_SESSION['found_user']);

// =============================================
// HANDLE ORDER STATUS UPDATE
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $orderId = intval($_POST['orderId']);
    $newStatus = trim($_POST['status']);
    $rejectionReason = trim($_POST['rejectionReason'] ?? '');
    $rejectedItems = trim($_POST['rejected_items'] ?? '');
    
    $allowed_statuses = ['ordered', 'cooking', 'pickup', 'completed', 'rejected', 'partial_rejected'];
    if (in_array($newStatus, $allowed_statuses)) {
        
        if ($newStatus === 'rejected' || $newStatus === 'partial_rejected') {
            
            $itemStmt = $conn->prepare("SELECT COUNT(*) as total FROM order_items WHERE orderId = ?");
            $itemStmt->bind_param("i", $orderId);
            $itemStmt->execute();
            $totalResult = $itemStmt->get_result();
            $totalItems = $totalResult->fetch_assoc()['total'] ?? 0;
            $itemStmt->close();
            
            $rejectedCount = 0;
            $rejectedItemIds = [];
            if (!empty($rejectedItems) && $rejectedItems !== 'all') {
                $rejectedItemIds = explode(',', $rejectedItems);
                $rejectedCount = count($rejectedItemIds);
            } elseif ($rejectedItems === 'all') {
                $rejectedCount = $totalItems;
            }
            
            if ($rejectedCount >= $totalItems && $totalItems > 0) {
                $finalStatus = 'rejected';
            } elseif ($rejectedCount > 0 && $rejectedCount < $totalItems) {
                $finalStatus = 'partial_rejected';
            } else {
                $finalStatus = 'ordered';
            }
            
            // REFUND POINTS - ONLY FOR REJECTED ITEMS
            if ($finalStatus === 'rejected' || $finalStatus === 'partial_rejected') {
                
                $orderStmt = $conn->prepare("SELECT userId, points_used FROM orders WHERE orderId = ?");
                $orderStmt->bind_param("i", $orderId);
                $orderStmt->execute();
                $orderResult = $orderStmt->get_result();
                $orderData = $orderResult->fetch_assoc();
                $orderStmt->close();
                
                if ($orderData && $orderData['userId']) {
                    $userId = $orderData['userId'];
                    $pointsToRefund = 0;
                    
                    if ($rejectedItems === 'all') {
                        $pointsToRefund = $orderData['points_used'];
                    } elseif (!empty($rejectedItemIds)) {
                        $placeholders = implode(',', array_fill(0, count($rejectedItemIds), '?'));
                        $types = str_repeat('i', count($rejectedItemIds));
                        
                        $refundStmt = $conn->prepare("SELECT SUM(price * quantity) as total FROM order_items WHERE orderId = ? AND itemId IN ($placeholders)");
                        $params = array_merge([$orderId], $rejectedItemIds);
                        $refundStmt->bind_param("i" . $types, ...$params);
                        $refundStmt->execute();
                        $refundResult = $refundStmt->get_result();
                        $refundData = $refundResult->fetch_assoc();
                        $pointsToRefund = $refundData['total'] ?? 0;
                        $refundStmt->close();
                    }
                    
                    if ($pointsToRefund > 0) {
                        $refundUpdateStmt = $conn->prepare("UPDATE users SET points = points + ? WHERE userId = ?");
                        $refundUpdateStmt->bind_param("ii", $pointsToRefund, $userId);
                        $refundUpdateStmt->execute();
                        $refundUpdateStmt->close();
                        
                        $newPointsUsed = $orderData['points_used'] - $pointsToRefund;
                        if ($newPointsUsed < 0) $newPointsUsed = 0;
                        
                        $updatePointsStmt = $conn->prepare("UPDATE orders SET points_used = ? WHERE orderId = ?");
                        $updatePointsStmt->bind_param("ii", $newPointsUsed, $orderId);
                        $updatePointsStmt->execute();
                        $updatePointsStmt->close();
                    }
                }
            }
            
            if (empty($rejectionReason)) {
                $rejectionReason = $finalStatus === 'partial_rejected' ? "ပစ္စည်းအချို့ကို ပယ်ချလိုက်ပါသည်။" : "အော်ဒါကို ပယ်ချလိုက်ပါသည်။";
            }
            
            $updateStatusStmt = $conn->prepare("UPDATE orders SET status = ?, rejectionReason = ?, rejected_items = ? WHERE orderId = ?");
            $updateStatusStmt->bind_param("sssi", $finalStatus, $rejectionReason, $rejectedItems, $orderId);
            $updateStatusStmt->execute();
            $updateStatusStmt->close();
            
        } else {
            // NORMAL STATUS UPDATES - DO NOT TOUCH POINTS
            $updateStatusStmt = $conn->prepare("UPDATE orders SET status = ? WHERE orderId = ?");
            $updateStatusStmt->bind_param("si", $newStatus, $orderId);
            $updateStatusStmt->execute();
            $updateStatusStmt->close();
        }
    }
    header("Location: admin.php");
    exit();
}

// =============================================
// STATISTICS - READ ONLY
// =============================================
$points_query = "SELECT SUM(points) as totalPoints FROM users";
$points_result = $conn->query($points_query);
$totalPoints = $points_result->fetch_assoc()['totalPoints'] ?? 0;

$todayQuery = "SELECT SUM(points_used) as todayPoints FROM orders WHERE DATE(createdAt) = CURDATE()";
$todayResult = $conn->query($todayQuery);
$totalPointsUsed = $todayResult->fetch_assoc()['todayPoints'] ?? 0;

$status_query = "SELECT status, COUNT(*) as count FROM orders GROUP BY status";
$status_result = $conn->query($status_query);
$orderStatusCounts = [];
while ($row = $status_result->fetch_assoc()) {
    $orderStatusCounts[strtolower($row['status'])] = $row['count'];
}

$activeOrders = ($orderStatusCounts['ordered'] ?? 0) + 
                ($orderStatusCounts['cooking'] ?? 0) + 
                ($orderStatusCounts['pickup'] ?? 0) +
                ($orderStatusCounts['partial_rejected'] ?? 0);

$top_item_query = "SELECT mi.itemName, SUM(oi.quantity) as totalQty FROM order_items oi JOIN menu_items mi ON oi.itemId = mi.itemId GROUP BY oi.itemId ORDER BY totalQty DESC LIMIT 1";
$top_item_result = $conn->query($top_item_query);
$topSeller = $top_item_result->fetch_assoc()['itemName'] ?? 'မရှိသေးပါ။';

$orders_query = "SELECT o.*, u.username, 
                (SELECT GROUP_CONCAT(CONCAT(m.itemId, ':', m.itemName, ' (', oi.quantity, ')') SEPARATOR '|') 
                 FROM order_items oi 
                 JOIN menu_items m ON oi.itemId = m.itemId 
                 WHERE oi.orderId = o.orderId) as items_with_id,
                (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                 FROM order_items oi 
                 JOIN menu_items m ON oi.itemId = m.itemId 
                 WHERE oi.orderId = o.orderId) as items 
                FROM orders o 
                LEFT JOIN users u ON o.userId = u.userId 
                ORDER BY o.orderId DESC LIMIT 10";
$orders_result = $conn->query($orders_query);
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Canteen - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand-color: #1EAFBD; --brand-hover: #17939F; --brand-light: #EBF8F9; }
        body { font-family: 'Poppins', 'Noto Sans Myanmar', sans-serif; background-color: #F8FAFC; }
        .sidebar { width: 260px; background: #FFFFFF; min-height: 100vh; border-right: 1px solid #E2E8F0; }
        .nav-link-custom { color: #64748B; padding: 12px 20px; border-radius: 10px; font-weight: 500; display: flex; align-items: center; gap: 12px; text-decoration: none; margin-bottom: 5px; transition: all 0.2s; }
        .nav-link-custom:hover, .nav-link-custom.active { background-color: var(--brand-light); color: var(--brand-color); }
        .text-brand { color: var(--brand-color) !important; }
        .bg-brand { background-color: var(--brand-color) !important; }
        .stat-card { background: #FFFFFF; border-radius: 16px; border: 1px solid #E2E8F0; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .stat-card .stat-number { font-size: 2rem; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.75rem; color: #64748B; font-weight: 500; }
        .status-select { min-width: 120px; }
        
        .add-points-box {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #E2E8F0;
        }
        .user-result-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            border-left: 4px solid var(--brand-color);
        }
        .user-result-card.success { border-color: #28a745; }
        .user-result-card.danger { border-color: #dc3545; }
        .user-result-card .user-name { font-weight: 700; color: #1E293B; }
        .user-result-card .user-phone { color: #64748B; font-size: 0.85rem; }
        .user-result-card .user-points { color: #FFC107; font-weight: 700; font-size: 1.1rem; }
        
        .status-actions {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: nowrap;
        }
        .status-actions .btn-reject {
            background: #dc3545;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .status-actions .btn-reject:hover:not(:disabled) {
            background: #c82333;
            transform: scale(1.02);
        }
        .status-actions .btn-reject:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
            transform: none;
        }
        .status-actions .status-select {
            min-width: 110px;
            padding: 3px 8px;
            font-size: 0.75rem;
            border-radius: 6px;
            border: 1px solid #ced4da;
            transition: all 0.2s;
        }
        .status-actions .status-select:disabled {
            background: #e9ecef;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .status-actions .btn-eye {
            background: transparent;
            border: 1px solid #ced4da;
            color: #1EAFBD;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            transition: all 0.2s;
        }
        .status-actions .btn-eye:hover {
            background: #EBF8F9;
            border-color: #1EAFBD;
        }
        
        .reject-items-container {
            max-height: 150px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            border: 1px solid #e9ecef;
        }
        .reject-items-container .form-check {
            padding: 4px 0;
            margin: 0;
        }
        .reject-items-container .form-check-label {
            font-size: 0.85rem;
        }

        .quick-reason-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 0.8rem;
            color: #495057;
            transition: all 0.2s ease;
            cursor: pointer;
            font-weight: 500;
            width: 100%;
            text-align: center;
        }
        .quick-reason-btn:hover {
            background: #e9ecef;
            border-color: #1EAFBD;
            color: #1EAFBD;
        }
        .quick-reason-btn.active {
            background: #EBF8F9;
            border-color: #1EAFBD;
            color: #1EAFBD;
        }
        .quick-reason-btn i {
            margin-right: 6px;
        }
    </style>
</head>
<body>

<div class="d-flex">
    <div class="sidebar p-3 d-flex flex-column" id="sidebar">
        <a href="admin.php" class="d-flex align-items-center gap-2 text-decoration-none text-brand fw-bold fs-4 mb-4 px-2">
            <i class="fa-solid fa-utensils"></i> UCSH Admin
        </a>
        <div class="nav flex-column mb-auto">
            <a href="admin.php" class="nav-link-custom active"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            <a href="menu.php" class="nav-link-custom"><i class="fa-solid fa-bowl-food"></i> Manage Menu</a>
            <a href="users.php" class="nav-link-custom"><i class="fa-solid fa-users"></i> Users</a>
            <a href="announcements.php" class="nav-link-custom"><i class="fa-solid fa-bullhorn"></i> Announcements</a>
        </div>
        <hr class="text-muted">
        <div><a href="../logout.php" class="nav-link-custom text-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></div>
    </div>

    <div class="flex-grow-1 p-3 p-md-4 overflow-hidden">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0 text-dark">Admin Dashboard</h4>
            <span class="text-muted small"><i class="fa-regular fa-calendar-days me-1"></i><?= date('d M Y') ?></span>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="add-points-box">
                    <h6 class="fw-bold mb-3">
                        <i class="fa-solid fa-coins text-warning me-2"></i>Add Points to User
                    </h6>
                    <form method="POST" action="admin.php" id="addPointsForm">
                        <input type="hidden" name="action" value="add_points">
                        <div class="row g-2">
                            <div class="col-12 col-sm-5">
                                <input type="text" name="phone" class="form-control form-control-sm" placeholder="Phone Number" required>
                            </div>
                            <div class="col-12 col-sm-4">
                                <input type="number" name="points" class="form-control form-control-sm" placeholder="Points" required min="1">
                            </div>
                            <div class="col-12 col-sm-3">
                                <button type="submit" class="btn btn-brand btn-sm w-100">Add Points</button>
                            </div>
                        </div>
                    </form>
                    
                    <?php if ($add_points_msg): ?>
                        <div class="user-result-card mt-3 <?= $add_points_type === 'success' ? 'success' : 'danger' ?>">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="fa-solid <?= $add_points_type === 'success' ? 'fa-check-circle text-success' : 'fa-exclamation-circle text-danger' ?> me-2"></i>
                                    <span class="<?= $add_points_type === 'success' ? 'text-success' : 'text-danger' ?>">
                                        <?= $add_points_msg ?>
                                    </span>
                                </div>
                                <?php if ($found_user && $add_points_type === 'success'): ?>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('addPointsForm').reset(); this.closest('.user-result-card').remove();">
                                        <i class="fa-solid fa-times"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($found_user && $add_points_type === 'success'): ?>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="user-name"><?= htmlspecialchars($found_user['username']) ?></div>
                                        <div class="user-phone"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($found_user['phoneNumber']) ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="user-points"><?= number_format($found_user['points']) ?> Points</div>
                                        <small class="text-muted">Updated Balance</small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="add-points-box" style="background: linear-gradient(135deg, #EBF8F9, #FFFFFF);">
                    <h6 class="fw-bold mb-3">
                        <i class="fa-solid fa-bowl-food text-brand me-2"></i>Quick Actions
                    </h6>
                    <div class="d-grid gap-2">
                        <a href="menu.php" class="btn btn-brand rounded-3 fw-medium">
                            <i class="fa-solid fa-plus me-2"></i>Add New Menu Item
                        </a>
                        <a href="users.php" class="btn btn-outline-secondary rounded-3 fw-medium">
                            <i class="fa-solid fa-users me-2"></i>Manage Users
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Total Points (All Users)</div>
                    <div class="stat-number text-warning"><?= number_format($totalPoints) ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">📅 Today's Points Used</div>
                    <div class="stat-number text-brand"><?= number_format($totalPointsUsed) ?></div>
                    <div class="small text-muted mt-1"><?= date('d M Y') ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Active Orders</div>
                    <div class="stat-number text-dark"><?= $activeOrders ?></div>
                    <div class="d-flex gap-2 mt-1 flex-wrap">
                        <span class="badge bg-info text-dark">Ordered: <?= $orderStatusCounts['ordered'] ?? 0 ?></span>
                        <span class="badge bg-primary">Cooking: <?= $orderStatusCounts['cooking'] ?? 0 ?></span>
                        <span class="badge bg-secondary">Pickup: <?= $orderStatusCounts['pickup'] ?? 0 ?></span>
                        <span class="badge bg-warning text-dark">Partial: <?= $orderStatusCounts['partial_rejected'] ?? 0 ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Top Seller</div>
                    <div class="stat-number fs-3 text-dark text-truncate"><i class="fa-solid fa-crown text-warning me-1"></i><?= htmlspecialchars($topSeller) ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold m-0 text-dark"><i class="fa-solid fa-list-check text-brand me-2"></i>Recent Orders</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0 text-secondary small fw-bold">#</th>
                            <th class="border-0 text-secondary small fw-bold">Queue</th>
                            <th class="border-0 text-secondary small fw-bold">Customer</th>
                            <th class="border-0 text-secondary small fw-bold">Order Items</th>
                            <th class="border-0 text-secondary small fw-bold">Type</th>
                            <th class="border-0 text-secondary small fw-bold">Points</th>
                            <th class="border-0 text-secondary small fw-bold">Status</th>
                            <th class="border-0 text-secondary small fw-bold">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders_result && $orders_result->num_rows > 0): ?>
                            <?php $serial = 1; while ($ord = $orders_result->fetch_assoc()): 
                                $st = strtolower($ord['status']);
                                $badgeClass = 'bg-secondary';
                                if ($st === 'ordered' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                                elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                                elseif ($st === 'completed') $badgeClass = 'bg-success';
                                elseif ($st === 'rejected') $badgeClass = 'bg-danger';
                                elseif ($st === 'partial_rejected') $badgeClass = 'bg-warning text-dark';
                                
                                $queueNum = $ord['queue_number'] ?? 'Q-' . str_pad($ord['orderId'], 3, '0', STR_PAD_LEFT);
                                $items = $ord['items'] ?? 'No items';
                                
                                $disableSelect = ($st === 'rejected' || $st === 'completed');
                                
                                $itemsList = [];
                                if (!empty($ord['items_with_id'])) {
                                    $parts = explode('|', $ord['items_with_id']);
                                    foreach ($parts as $part) {
                                        $itemData = explode(':', $part);
                                        if (count($itemData) == 2) {
                                            $itemsList[] = [
                                                'id' => $itemData[0],
                                                'name' => $itemData[1]
                                            ];
                                        }
                                    }
                                }
                            ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= $serial++ ?></td>
                                    <td><span class="badge bg-dark text-white fw-bold"><?= $queueNum ?></span></td>
                                    <td><?= htmlspecialchars($ord['username'] ?? 'Guest') ?></td>
                                    <td><small class="text-truncate d-inline-block" style="max-width: 150px;"><?= htmlspecialchars($items) ?></small></td>
                                    <td><span class="badge bg-light text-dark border"><?= strtoupper($ord['orderType']) ?></span></td>
                                    <td><span class="badge bg-warning text-dark"><?= number_format($ord['points_used'] ?? 0) ?></span></td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?> text-uppercase"><?= $ord['status'] ?></span>
                                    </td>
                                    <td>
                                        <div class="status-actions">
                                            <form method="POST" action="admin.php" class="d-inline" id="statusForm<?= $ord['orderId'] ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="orderId" value="<?= $ord['orderId'] ?>">
                                                <input type="hidden" name="rejectionReason" id="rejectionReason<?= $ord['orderId'] ?>" value="">
                                                <input type="hidden" name="rejected_items" id="rejectedItems<?= $ord['orderId'] ?>" value="">
                                                
                                                <select name="status" class="form-select form-select-sm status-select" 
                                                    <?= $disableSelect ? 'disabled' : '' ?>
                                                    onchange="this.form.submit()">
                                                    <option value="ordered" <?= $st === 'ordered' ? 'selected' : '' ?>>Ordered</option>
                                                    <option value="cooking" <?= $st === 'cooking' ? 'selected' : '' ?>>Cooking</option>
                                                    <option value="pickup" <?= $st === 'pickup' ? 'selected' : '' ?>>Pickup</option>
                                                    <option value="completed" <?= $st === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                </select>
                                            </form>
                                            
                                            <?php if ($st === 'ordered'): ?>
                                                <button class="btn-reject" onclick="showRejectModal(<?= $ord['orderId'] ?>, '<?= addslashes($ord['items_with_id']) ?>')">
                                                    <i class="fa-solid fa-ban me-1"></i>Reject
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-reject" disabled title="<?= $st === 'rejected' ? 'Already Rejected' : ($st === 'completed' ? 'Already Completed' : 'Cannot reject') ?>">
                                                    <i class="fa-solid fa-ban me-1"></i>Reject
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn-eye" data-bs-toggle="modal" data-bs-target="#detailModal<?= $ord['orderId'] ?>" title="See Details">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <div class="modal fade" id="detailModal<?= $ord['orderId'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content rounded-4 border-0 shadow">
                                            <div class="modal-header bg-light border-0">
                                                <h6 class="fw-bold m-0"><i class="fa-solid fa-receipt text-brand me-2"></i>Order Details #<?= $ord['orderId'] ?></h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Queue:</span><span class="fw-bold"><?= $queueNum ?></span></div>
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Customer:</span><span class="fw-bold"><?= htmlspecialchars($ord['username'] ?? 'Guest') ?></span></div>
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Order Items:</span><span class="fw-bold text-end" style="max-width: 60%;"><?= htmlspecialchars($items) ?></span></div>
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Type:</span><span class="fw-bold"><?= strtoupper($ord['orderType']) ?></span></div>
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pickup Time:</span><span class="fw-bold"><?= $ord['pickupTime'] ?? '-' ?></span></div>
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Points Used:</span><span class="fw-bold text-warning"><?= number_format($ord['points_used'] ?? 0) ?></span></div>
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Total:</span><span class="fw-bold text-danger"><?= number_format($ord['totalAmount'] ?? 0) ?></span></div>
                                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Status:</span><span class="badge <?= $badgeClass ?>"><?= $ord['status'] ?></span></div>
                                                
                                                <div class="mt-3 p-3 bg-light rounded-3 border">
                                                    <small class="text-muted d-block fw-bold mb-1"><i class="fa-solid fa-comment-dots text-brand me-1"></i>Special Request (မှတ်ချက်):</small>
                                                    <div class="fw-medium text-dark">
                                                        <?php if (!empty(trim($ord['specialRequest'] ?? ''))): ?>
                                                            <?= htmlspecialchars($ord['specialRequest']) ?>
                                                        <?php else: ?>
                                                            <span class="text-muted fst-italic">မှတ်ချက်မရှိပါ</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <?php if ($st === 'rejected' && !empty($ord['rejectionReason'])): ?>
                                                    <div class="mt-2 p-2 bg-danger-subtle rounded-3">
                                                        <small class="text-danger fw-bold d-block">
                                                            <i class="fa-solid fa-circle-exclamation me-1"></i>Rejection Reason:
                                                        </small>
                                                        <div class="fw-medium"><?= htmlspecialchars($ord['rejectionReason']) ?></div>
                                                        <?php if (!empty($ord['rejected_items'])): ?>
                                                            <div class="mt-1"><small class="text-danger">Rejected Items: <?= htmlspecialchars($ord['rejected_items']) ?></small></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($st === 'partial_rejected' && !empty($ord['rejectionReason'])): ?>
                                                    <div class="mt-2 p-2 bg-warning-subtle rounded-3">
                                                        <small class="text-warning fw-bold d-block">
                                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Partial Rejection:
                                                        </small>
                                                        <div class="fw-medium"><?= htmlspecialchars($ord['rejectionReason']) ?></div>
                                                        <?php if (!empty($ord['rejected_items'])): ?>
                                                            <div class="mt-1"><small class="text-warning">Rejected Items: <?= htmlspecialchars($ord['rejected_items']) ?></small></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer border-0 bg-light">
                                                <button type="button" class="btn btn-secondary btn-sm rounded-3 px-4" data-bs-dismiss="modal">ပိတ်မည်</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Reject Modal -->
                                <div class="modal fade" id="rejectModal" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content rounded-4 border-0 shadow">
                                            <div class="modal-header bg-light border-0">
                                                <h6 class="fw-bold m-0"><i class="fa-solid fa-ban text-danger me-2"></i>Reject Order Items</h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <p class="text-muted small mb-3">ပယ်ချလိုသော ပစ္စည်းများကို ရွေးပါ။ (မရွေးရင် အကုန်ပယ်မည်)</p>
                                                <div id="rejectItemsList" class="reject-items-container mb-3">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold text-muted">အမြန်ရွေးချယ်ရန် အကြောင်းပြချက်များ</label>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <button type="button" class="quick-reason-btn" onclick="setQuickReason('ပစ္စည်းကုန်သွားသောကြောင့် မှာယူ၍မရတော့ပါ')">
                                                            <i class="fa-solid fa-box-open"></i> ပစ္စည်းကုန်သွားသောကြောင့် မှာယူ၍မရတော့ပါ
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold text-muted">ပယ်ချသည့် အကြောင်းပြချက် (Rejection Reason)</label>
                                                    <textarea id="rejectReasonInput" class="form-control rounded-3" rows="2" placeholder="ဥပမာ - ပစ္စည်းမကျန်တော့ပါ..."></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0 bg-light">
                                                <button type="button" class="btn btn-secondary btn-sm rounded-3 px-4" data-bs-dismiss="modal">မလုပ်တော့ပါ</button>
                                                <button type="button" class="btn btn-danger btn-sm rounded-3 px-4" id="confirmRejectBtn" onclick="confirmReject()">
                                                    <i class="fa-solid fa-ban me-1"></i>ပယ်ချမည်
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">အော်ဒါများ မရှိသေးပါ။</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
var currentOrderId = 0;

function setQuickReason(reason) {
    document.getElementById('rejectReasonInput').value = reason;
    document.querySelectorAll('.quick-reason-btn').forEach(function(btn) {
        btn.classList.remove('active');
        if (btn.textContent.trim() === reason) {
            btn.classList.add('active');
        }
    });
}

function showRejectModal(orderId, itemsWithId) {
    currentOrderId = orderId;
    
    var container = document.getElementById('rejectItemsList');
    container.innerHTML = '';
    
    document.querySelectorAll('.quick-reason-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    document.getElementById('rejectReasonInput').value = '';
    
    if (!itemsWithId) {
        container.innerHTML = '<div class="text-muted small">ပစ္စည်းများ မရှိပါ။</div>';
        return;
    }
    
    var parts = itemsWithId.split('|');
    var itemsList = [];
    parts.forEach(function(part) {
        var itemData = part.split(':');
        if (itemData.length == 2) {
            itemsList.push({
                id: itemData[0],
                name: itemData[1]
            });
        }
    });
    
    if (itemsList.length === 0) {
        container.innerHTML = '<div class="text-muted small">ပစ္စည်းများ မရှိပါ။</div>';
        return;
    }
    
    var allDiv = document.createElement('div');
    allDiv.className = 'form-check mb-2 border-bottom pb-2';
    allDiv.innerHTML = `
        <input class="form-check-input" type="checkbox" id="selectAllItems" checked onchange="toggleAllItems()">
        <label class="form-check-label fw-bold" for="selectAllItems">အကုန်ရွေးမည် (Select All)</label>
    `;
    container.appendChild(allDiv);
    
    itemsList.forEach(function(item) {
        var div = document.createElement('div');
        div.className = 'form-check ms-3';
        div.innerHTML = `
            <input class="form-check-input item-checkbox" type="checkbox" value="${item.id}" id="rejectItem_${item.id}" checked>
            <label class="form-check-label" for="rejectItem_${item.id}">
                ${item.name}
            </label>
        `;
        container.appendChild(div);
    });
    
    var modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
}

function toggleAllItems() {
    var checked = document.getElementById('selectAllItems').checked;
    var checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = checked;
    });
}

function confirmReject() {
    var reason = document.getElementById('rejectReasonInput').value.trim();
    if (!reason) {
        Swal.fire({
            icon: 'warning',
            title: 'အကြောင်းပြချက် ထည့်ပါ',
            text: 'ကျေးဇူးပြု၍ ပယ်ချသည့် အကြောင်းပြချက် ရိုက်ထည့်ပါ!',
            confirmButtonColor: '#1EAFBD'
        });
        return;
    }
    
    var selectedItems = [];
    var checkboxes = document.querySelectorAll('.item-checkbox:checked');
    checkboxes.forEach(function(cb) {
        selectedItems.push(cb.value);
    });
    
    var rejectedItems = selectedItems.length > 0 ? selectedItems.join(',') : 'all';
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'admin.php';
    
    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'update_status';
    form.appendChild(actionInput);
    
    var orderIdInput = document.createElement('input');
    orderIdInput.type = 'hidden';
    orderIdInput.name = 'orderId';
    orderIdInput.value = currentOrderId;
    form.appendChild(orderIdInput);
    
    var statusInput = document.createElement('input');
    statusInput.type = 'hidden';
    statusInput.name = 'status';
    statusInput.value = 'rejected';
    form.appendChild(statusInput);
    
    var reasonInput = document.createElement('input');
    reasonInput.type = 'hidden';
    reasonInput.name = 'rejectionReason';
    reasonInput.value = reason;
    form.appendChild(reasonInput);
    
    var rejectedInput = document.createElement('input');
    rejectedInput.type = 'hidden';
    rejectedInput.name = 'rejected_items';
    rejectedInput.value = rejectedItems;
    form.appendChild(rejectedInput);
    
    document.body.appendChild(form);
    form.submit();
}

document.getElementById('rejectModal').addEventListener('hidden.bs.modal', function () {
    currentOrderId = 0;
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>