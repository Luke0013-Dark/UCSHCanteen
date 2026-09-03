<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: guest.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get User Points
$userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userData = $userResult->fetch_assoc();
$currentPoints = $userData['points'] ?? 0;
$userStmt->close();

// Cart Count
$cart_count = 0;
$cart_stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE userId = ?");
if ($cart_stmt) {
    $cart_stmt->bind_param("i", $user_id);
    $cart_stmt->execute();
    $cart_res = $cart_stmt->get_result()->fetch_assoc();
    $cart_count = $cart_res['total'] ?? 0;
    $cart_stmt->close();
}

// Like Count
$like_count = 0;
$like_stmt = $conn->prepare("SELECT COUNT(*) as total FROM liked_items WHERE userId = ?");
if ($like_stmt) {
    $like_stmt->bind_param("i", $user_id);
    $like_stmt->execute();
    $like_res = $like_stmt->get_result()->fetch_assoc();
    $like_count = $like_res['total'] ?? 0;
    $like_stmt->close();
}

// =============================================
// FETCH ORDER HISTORY WITH REJECTED ITEMS
// =============================================
$orders = $conn->query("SELECT o.*, 
                        (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                         FROM order_items oi 
                         JOIN menu_items m ON oi.itemId = m.itemId 
                         WHERE oi.orderId = o.orderId) as items,
                        (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                         FROM order_items oi 
                         JOIN menu_items m ON oi.itemId = m.itemId 
                         WHERE oi.orderId = o.orderId 
                         AND FIND_IN_SET(oi.itemId, o.rejected_items)) as rejected_item_names
                        FROM orders o 
                        WHERE o.userId = $user_id 
                        ORDER BY o.orderId DESC");
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History - UCSH Canteen</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root { 
            --brand-color: #1EAFBD; 
            --brand-hover: #17939F;
            --brand-light: #EBF8F9;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Noto Sans Myanmar', sans-serif;
            background-color: #F8FAFC;
            color: #1E293B;
        }

        .bg-brand-light { background-color: var(--brand-light) !important; }
        .text-brand { color: var(--brand-color) !important; }

        .btn-brand { 
            background: var(--brand-color); 
            color: white; 
            border: none; 
            transition: all 0.25s ease;
        }
        .btn-brand:hover { 
            background: var(--brand-hover); 
            color: white; 
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(30, 175, 189, 0.3);
        }

        .navbar-custom {
            background-color: #FFFFFF;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }
        .nav-icon-btn {
            position: relative;
            color: #64748B;
            font-size: 1.15rem;
            padding: 8px 12px;
            border-radius: 12px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .nav-icon-btn:hover {
            background-color: var(--brand-light);
            color: var(--brand-color);
        }
        .nav-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            font-size: 0.65rem;
            background-color: #FF4757;
        }

        .search-box { max-width: 380px; }
        .search-box .form-control {
            border-radius: 20px;
            padding-left: 40px;
            border: 1px solid #E2E8F0;
            background-color: #F8FAFC;
        }
        .search-box .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
        }

        .history-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
        }
        .history-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
        }

        .fs-7 { font-size: 0.75rem; }
        
        .points-nav {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 8px;
            padding: 4px 14px;
            font-weight: 700;
            color: #92400e;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #fcd34d;
        }

        /* ============================================= */
        /* HISTORY ITEM STYLES                           */
        /* ============================================= */
        .rejected-item {
            color: #dc3545;
            text-decoration: line-through;
            opacity: 0.8;
            display: inline-block;
            margin-right: 6px;
        }
        
        .rejected-item .reject-badge {
            background: #dc3545;
            color: white;
            font-size: 0.55rem;
            padding: 1px 6px;
            border-radius: 8px;
            margin-left: 3px;
            text-decoration: none;
            font-weight: 600;
        }
        
        .accepted-item {
            color: #28a745;
            display: inline-block;
            margin-right: 6px;
        }
        
        .accepted-item .accept-badge {
            background: #28a745;
            color: white;
            font-size: 0.55rem;
            padding: 1px 6px;
            border-radius: 8px;
            margin-left: 3px;
            text-decoration: none;
            font-weight: 600;
        }
        
        .item-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 8px;
        }
        
        .item-list .item {
            padding: 2px 6px;
            border-radius: 4px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            font-size: 0.85rem;
        }
        
        .item-list .item.rejected {
            background: #fff5f5;
            border-color: #dc3545;
        }
        
        .item-list .item.accepted {
            background: #f0fff4;
            border-color: #28a745;
        }

        .partial-reject-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 6px 10px;
            border-radius: 4px;
            margin-top: 6px;
        }
        
        .reject-reason-box {
            background: #fff5f5;
            border-left: 4px solid #dc3545;
            padding: 6px 10px;
            border-radius: 4px;
            margin-top: 6px;
        }
    </style>
</head>
<body class="pb-5">

    <!-- ============================================= -->
    <!-- NAVBAR                                       -->
    <!-- ============================================= -->
    <nav class="navbar navbar-expand-lg sticky-top navbar-custom py-2">
        <div class="container">
            <a class="navbar-brand fw-bold fs-4 text-brand d-flex align-items-center me-3" href="index.php">
                <i class="fa-solid fa-utensils me-2"></i>UCSH Canteen
            </a>

            <div class="search-box position-relative flex-grow-1 mx-lg-4 my-2 my-lg-0">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="searchInput" class="form-control form-control-sm py-2" placeholder="အစားအသောက်များ ရှာဖွေပါ...">
            </div>

            <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#navbarIcons">
                <i class="fa-solid fa-bars fs-4 text-dark"></i>
            </button>

            <div class="collapse navbar-collapse" id="navbarIcons">
                <div class="d-flex align-items-center ms-auto gap-1 mt-3 mt-lg-0 justify-content-around">
                    
                    <a href="index.php" class="nav-icon-btn text-decoration-none" title="Home">
                        <i class="fa-solid fa-house"></i>
                        <span class="d-lg-none ms-2 small">Home</span>
                    </a>

                    <a href="track.php" class="nav-icon-btn text-decoration-none" title="Track Order">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span class="d-lg-none ms-2 small">Queue</span>
                    </a>

                    <a href="history.php" class="nav-icon-btn text-decoration-none text-brand" title="Order History">
                        <i class="fa-solid fa-receipt"></i>
                        <span class="d-lg-none ms-2 small">History</span>
                    </a>

                    <span class="points-nav">
                        <i class="fa-solid fa-coins text-warning"></i> 
                        <span id="userPointsText"><?= number_format($currentPoints) ?></span>
                    </span>

                    <a href="likes.php" class="nav-icon-btn text-decoration-none" title="Liked Items">
                        <i class="fa-regular fa-heart"></i>
                        <span class="badge rounded-pill nav-badge" id="likeBadge"><?= $like_count ?></span>
                        <span class="d-lg-none ms-2 small">Likes</span>
                    </a>

                    <a href="cart.php" class="nav-icon-btn text-decoration-none" title="Cart">
                        <i class="fa-solid fa-cart-shopping"></i>
                        <span class="badge rounded-pill nav-badge" id="cartBadge"><?= $cart_count ?></span>
                        <span class="d-lg-none ms-2 small">Cart</span>
                    </a>

                    <div class="dropdown ms-lg-2">
                        <a href="#" class="nav-icon-btn text-decoration-none d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                            <i class="fa-regular fa-user-circle fs-5"></i>
                            <span class="fw-medium small d-none d-lg-inline"><?= htmlspecialchars($_SESSION['username'] ?? 'Account') ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-3 mt-2">
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <li><a class="dropdown-item py-2" href="profile.php"><i class="fa-regular fa-id-card me-2 text-brand"></i>Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item py-2" href="login.php"><i class="fa-solid fa-right-to-bracket me-2 text-brand"></i>Login</a></li>
                                <li><a class="dropdown-item py-2" href="register.php"><i class="fa-solid fa-user-plus me-2 text-brand"></i>Register</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                </div>
            </div>
        </div>
    </nav>

    <div class="container my-4" style="max-width: 850px;">
        
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-2">
                <a href="index.php" class="btn btn-white border shadow-sm rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-arrow-left text-dark"></i>
                </a>
                <h5 class="fw-bold mb-0 text-dark">My Order History</h5>
            </div>
            <span class="badge bg-white text-secondary border shadow-sm fw-normal px-3 py-2 rounded-pill fs-7">
                <i class="fa-solid fa-clock-rotate-left me-1 text-brand"></i><?= $orders ? $orders->num_rows : 0 ?> Orders
            </span>
        </div>

        <?php if ($orders && $orders->num_rows > 0): ?>
            <?php while ($ord = $orders->fetch_assoc()): 
                $st = strtolower($ord['status']);
                $badgeClass = 'bg-secondary';
                
                // ✅ Updated status badge for new statuses
                if ($st === 'ordered' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                elseif ($st === 'completed') $badgeClass = 'bg-success';
                elseif ($st === 'rejected') $badgeClass = 'bg-danger';
                elseif ($st === 'partial_rejected') $badgeClass = 'bg-warning text-dark';
                elseif ($st === 'pending' || $st === 'confirmed') $badgeClass = 'bg-warning text-dark';
                
                // Parse items for rejected status
                $allItems = [];
                $rejectedItems = [];
                $acceptedItems = [];
                $itemStatusMap = [];
                
                if (!empty($ord['items'])) {
                    $allItems = explode(', ', $ord['items']);
                    $rejectedNames = !empty($ord['rejected_item_names']) ? explode(', ', $ord['rejected_item_names']) : [];
                    
                    foreach ($allItems as $item) {
                        $isRejected = false;
                        foreach ($rejectedNames as $rejected) {
                            if (strpos($item, $rejected) !== false || strpos($rejected, $item) !== false) {
                                $isRejected = true;
                                break;
                            }
                        }
                        if ($isRejected) {
                            $rejectedItems[] = $item;
                            $itemStatusMap[$item] = 'rejected';
                        } else {
                            $acceptedItems[] = $item;
                            $itemStatusMap[$item] = 'accepted';
                        }
                    }
                }
            ?>
                <div class="history-card p-3 p-md-4 mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-dark text-white fw-bold"><?= $ord['queue_number'] ?? 'Q-' . str_pad($ord['orderId'], 3, '0', STR_PAD_LEFT) ?></span>
                                <span class="badge <?= $badgeClass ?> text-uppercase"><?= $ord['status'] ?></span>
                            </div>
                            <div class="small text-muted">
                                <i class="fa-regular fa-calendar me-1"></i><?= date('d M Y, h:i A', strtotime($ord['createdAt'])) ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-warning"><?= number_format($ord['points_used'] ?? 0) ?> Points</div>
                            <div class="small text-muted"><?= strtoupper($ord['orderType']) ?> • <?= $ord['pickupTime'] ?></div>
                        </div>
                    </div>

                    <!-- ============================================= -->
                    <!-- ITEMS DISPLAY WITH REJECTED STATUS           -->
                    <!-- ============================================= -->
                    <div class="mt-2 pt-2 border-top">
                        <div class="small text-muted mb-1 fw-bold">Ordered Items:</div>
                        <div class="item-list">
                            <?php if (!empty($allItems)): ?>
                                <?php foreach ($allItems as $item): 
                                    $isRejected = isset($itemStatusMap[$item]) && $itemStatusMap[$item] === 'rejected';
                                ?>
                                    <span class="item <?= $isRejected ? 'rejected' : 'accepted' ?>">
                                        <?php if ($isRejected): ?>
                                            <span class="rejected-item">
                                                <i class="fa-solid fa-circle-xmark me-1"></i>
                                                <?= htmlspecialchars($item) ?>
                                                <span class="reject-badge">REJECTED</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="accepted-item">
                                                <i class="fa-solid fa-circle-check me-1"></i>
                                                <?= htmlspecialchars($item) ?>
                                                <span class="accept-badge">ACCEPTED</span>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">No items</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($ord['specialRequest'])): ?>
                        <div class="mt-1 small text-muted">
                            <i class="fa-regular fa-note-sticky me-1"></i>Special: <?= htmlspecialchars($ord['specialRequest']) ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- ============================================= -->
                    <!-- PARTIAL REJECT / REJECT REASON BOX           -->
                    <!-- ============================================= -->
                    <?php if ($st === 'partial_rejected'): ?>
                        <div class="partial-reject-box mt-2">
                            <small class="text-warning fw-bold d-block">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>Partially Rejected
                            </small>
                            <?php if (!empty($rejectedItems)): ?>
                                <div class="mt-1">
                                    <small class="text-danger fw-bold">❌ Rejected:</small>
                                    <small class="text-danger d-block"><?= htmlspecialchars(implode(', ', $rejectedItems)) ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($acceptedItems)): ?>
                                <div class="mt-1">
                                    <small class="text-success fw-bold">✅ Accepted:</small>
                                    <small class="text-success d-block"><?= htmlspecialchars(implode(', ', $acceptedItems)) ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($ord['rejectionReason'])): ?>
                                <div class="mt-1 pt-1 border-top">
                                    <small class="text-muted">Reason: <?= htmlspecialchars($ord['rejectionReason']) ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($st === 'rejected' && !empty($ord['rejectionReason'])): ?>
                        <div class="reject-reason-box mt-2">
                            <small class="text-danger fw-bold d-block">
                                <i class="fa-solid fa-circle-exclamation me-1"></i>Rejected
                            </small>
                            <span class="text-dark"><?= htmlspecialchars($ord['rejectionReason']) ?></span>
                            <?php if (!empty($rejectedItems)): ?>
                                <div class="mt-1">
                                    <small class="text-danger">Rejected Items: <?= htmlspecialchars(implode(', ', $rejectedItems)) ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="text-center py-5 bg-white rounded-4 shadow-sm">
                <i class="fa-solid fa-receipt fa-3x text-muted opacity-50 mb-3"></i>
                <h6 class="text-secondary fw-bold">မှာယူထားသော Order များ မရှိသေးပါ။</h6>
                <a href="index.php" class="btn btn-brand btn-sm mt-3 px-4 py-2 rounded-3">မီနူး သို့သွားရန်</a>
            </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>