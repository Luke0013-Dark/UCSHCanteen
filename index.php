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

// User Liked Item IDs
$user_liked_item_ids = [];
$liked_ids_stmt = $conn->prepare("SELECT itemId FROM liked_items WHERE userId = ?");
if ($liked_ids_stmt) {
    $liked_ids_stmt->bind_param("i", $user_id);
    $liked_ids_stmt->execute();
    $liked_ids_res = $liked_ids_stmt->get_result();
    while ($row = $liked_ids_res->fetch_assoc()) {
        $user_liked_item_ids[] = $row['itemId'];
    }
    $liked_ids_stmt->close();
}

// Get User Points
$userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userData = $userResult->fetch_assoc();
$currentPoints = $userData['points'] ?? 0;
$userStmt->close();

// Announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY announcementId DESC LIMIT 3");
// Menu Items - Include special_note
$menu_items = $conn->query("SELECT * FROM menu_items WHERE isAvailable = 1 ORDER BY itemId DESC");

// Get unique categories
$categories = [];
$catResult = $conn->query("SELECT DISTINCT category FROM menu_items WHERE isAvailable = 1");
while ($cat = $catResult->fetch_assoc()) {
    $categories[] = $cat['category'];
}

// Check for voucher data
$showVoucher = isset($_GET['show_voucher']) && isset($_SESSION['voucher_data']);
$voucherData = $showVoucher ? $_SESSION['voucher_data'] : null;
if ($showVoucher) {
    unset($_SESSION['voucher_data']);
}
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Smart Canteen</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <style>
        :root {
            --brand-color: #1EAFBD;
            --brand-color-hover: #17939F;
            --brand-light: #EBF8F9;
            --brand-dark: #0F5860;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Noto Sans Myanmar', sans-serif;
            background-color: #F8FAFC;
            color: #1E293B;
        }

        .bg-brand { background-color: var(--brand-color) !important; }
        .bg-brand-light { background-color: var(--brand-light) !important; }
        .text-brand { color: var(--brand-color) !important; }
        
        .btn-brand {
            background-color: var(--brand-color);
            color: #FFFFFF;
            border: none;
            transition: all 0.25s ease;
        }
        .btn-brand:hover {
            background-color: var(--brand-color-hover);
            color: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(30, 175, 189, 0.3);
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
        .search-box .form-control:focus {
            box-shadow: 0 0 0 3px rgba(30, 175, 189, 0.15);
            border-color: var(--brand-color);
            background-color: #FFFFFF;
        }
        .search-box .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
        }

        .announcement-box {
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            border-radius: 20px;
            color: #FFFFFF;
            box-shadow: 0 8px 24px rgba(30, 175, 189, 0.22);
            position: relative;
            overflow: hidden;
        }
        .announcement-card-item {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 16px;
            transition: all 0.3s ease;
        }
        .announcement-card-item:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-3px);
        }

        .menu-card {
            border: 1px solid #F1F5F9;
            border-radius: 20px;
            overflow: hidden;
            background: #FFFFFF;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }
        .menu-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 30px rgba(0, 0, 0, 0.07);
            border-color: var(--brand-light);
        }
        .menu-card-img-wrapper {
            position: relative;
            height: 160px;
            overflow: hidden;
        }
        .menu-card-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .menu-card:hover .menu-card-img { transform: scale(1.08); }

        .like-btn {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(6px);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #CBD5E0;
            transition: all 0.2s ease;
        }
        .like-btn:hover, .like-btn.active {
            color: #FF4757;
            background: #FFFFFF;
            transform: scale(1.1);
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
        .category-filter-btn {
            border-radius: 20px;
            padding: 6px 16px;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid #E2E8F0;
            background: white;
            color: #64748B;
        }
        .category-filter-btn:hover {
            border-color: var(--brand-color);
            color: var(--brand-color);
        }
        .category-filter-btn.active {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        /* Voucher Styles */
        .voucher-content {
            background: white;
            border: 1px solid #e2e8f0;
        }
        .voucher-header {
            background: #0d1b2a;
            color: white;
            padding: 20px;
            text-align: center;
        }
        .voucher-divider {
            background: #0d1b2a;
            height: 10px;
            background-image: radial-gradient(circle, #fff 45%, transparent 50%);
            background-size: 16px 16px;
            background-repeat: repeat-x;
        }
        .voucher-items {
            background: #f8fafc;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .special-note-tag {
            font-size: 0.65rem;
            color: #dc3545;
            font-weight: 600;
            display: block;
            margin-top: 2px;
        }
    </style>
</head>
<body class="pb-5">

<?php include 'nav.php'; ?>

<!-- Voucher Modal -->
<div class="modal fade" id="voucherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-0">
                <h6 class="fw-bold m-0"><i class="fa-solid fa-receipt text-brand me-2"></i>UCSH Canteen Voucher</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="voucherContent" class="voucher-content rounded-4 shadow-sm overflow-hidden">
                    <div class="voucher-header">
                        <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                            <i class="fa-solid fa-utensils text-info fs-4"></i>
                            <h5 class="fw-bold m-0 text-white">UCSH Canteen Voucher</h5>
                        </div>
                        <small class="text-white-50" style="font-size: 12px;">Smart Canteen System</small>
                        <div class="mt-3">
                            <span class="badge text-white fs-2 px-4 py-2 rounded-3 fw-bold" id="vQueueNumber" style="background-color: #ff4757; letter-spacing: 1px;">Q-000</span>
                        </div>
                    </div>
                    <div class="voucher-divider"></div>
                    <div class="p-4">
                        <div class="text-center mb-3">
                            <span class="badge bg-success-subtle text-success px-3 py-1 rounded-pill small fw-bold">
                                <i class="fa-solid fa-circle-check me-1"></i> Order Confirmed!
                            </span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Customer:</span>
                            <span class="fw-bold text-dark" id="vCustomerName">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Order Date:</span>
                            <span class="fw-bold text-dark" id="vOrderDate">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Pickup Time:</span>
                            <span class="fw-bold text-danger" id="vPickupTime">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Payment Method:</span>
                            <span class="fw-bold text-dark"><i class="fa-solid fa-coins text-warning me-1"></i> Points</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Points Used:</span>
                            <span class="fw-bold text-dark" id="vPointsUsed">0</span>
                        </div>
                        <div class="voucher-items mb-3">
                            <div class="text-muted fw-bold mb-1" style="font-size: 10px;">ORDERED ITEMS:</div>
                            <div id="vItemsList" class="fw-medium text-dark">
                                <span class="text-muted">-</span>
                            </div>
                        </div>
                        <div class="bg-light p-3 rounded-3 mb-3 border small">
                            <div class="text-muted fw-bold mb-1" style="font-size: 10px;">ORDER DETAILS:</div>
                            <div class="d-flex justify-content-between fw-medium" id="vItemsSummary">
                                <span>-</span>
                                <span>-</span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="fw-bold text-dark small">TOTAL POINTS:</span>
                            <span class="fw-bold fs-5" id="vTotalAmount" style="color: #ff4757;">0</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">ပိတ်မည်</button>
                <button type="button" onclick="downloadVoucher()" class="btn btn-dark btn-sm rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-download me-1"></i> Download (PNG)
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container my-4">

    <?php if ($announcements && $announcements->num_rows > 0): ?>
        <div class="announcement-box p-3 p-md-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-white text-brand rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <h6 class="fw-bold mb-0 text-white text-uppercase">ကျောင်းကန်တင်း အသိပေးချက်များ</h6>
                </div>
                <span class="badge bg-white text-brand rounded-pill px-3 py-1 fs-7">Announcements</span>
            </div>
            <div class="row g-3">
                <?php while ($ann = $announcements->fetch_assoc()): ?>
                    <div class="col-12 col-md-4">
                        <div class="announcement-card-item p-3 h-100">
                            <h6 class="fw-bold mb-1 text-white text-truncate"><?= htmlspecialchars($ann['title']) ?></h6>
                            <p class="mb-0 text-white-50 small"><?= htmlspecialchars($ann['content']) ?></p>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold text-dark m-0">ယနေ့ရရှိနိုင်သော အစားအသောက်များ</h5>
        <span class="badge bg-white text-secondary border shadow-sm fw-normal px-3 py-2 rounded-pill">
            <?= $menu_items ? $menu_items->num_rows : 0 ?> Items
        </span>
    </div>

    <!-- Category Filter -->
    <div class="mb-3 d-flex flex-wrap gap-2">
        <button class="category-filter-btn active" data-category="all">All</button>
        <?php foreach ($categories as $cat): ?>
            <button class="category-filter-btn" data-category="<?= htmlspecialchars(strtolower($cat)) ?>"><?= htmlspecialchars($cat) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="row g-3" id="menuContainer">
        <?php if ($menu_items && $menu_items->num_rows > 0): ?>
            <?php while ($item = $menu_items->fetch_assoc()): ?>
                
                <?php 
                    $imgPath = 'https://via.placeholder.com/300x200?text=No+Image';
                    if (!empty($item['image'])) {
                        if (file_exists($item['image'])) {
                            $imgPath = $item['image'];
                        } elseif (file_exists('uploads/' . basename($item['image']))) {
                            $imgPath = 'uploads/' . basename($item['image']);
                        }
                    }

                    $is_liked = in_array($item['itemId'], $user_liked_item_ids);
                ?>

                <div class="col-6 col-md-4 col-lg-3 menu-item-card" 
                     data-name="<?= htmlspecialchars(mb_strtolower($item['itemName'], 'UTF-8')) ?>"
                     data-category="<?= htmlspecialchars(mb_strtolower($item['category'] ?? '', 'UTF-8')) ?>">
    
                    <div class="card h-100 menu-card">
                        
                        <div class="menu-card-img-wrapper">
                            <div class="position-absolute top-0 end-0 p-2 z-2">
                                <button class="like-btn shadow-sm <?= $is_liked ? 'active' : '' ?>" onclick="event.stopPropagation(); toggleLike(this, <?= $item['itemId'] ?>);">
                                    <i class="fa-solid fa-heart"></i>
                                </button>
                            </div>
                            <img src="<?= $imgPath ?>" class="menu-card-img" alt="<?= htmlspecialchars($item['itemName']) ?>" data-bs-toggle="modal" data-bs-target="#detailModal<?= $item['itemId'] ?>">
                        </div>

                        <div class="card-body d-flex flex-column p-3">
                            <span class="badge bg-brand-light text-brand border-0 w-auto align-self-start mb-2 px-2 py-1 rounded-2 fs-7 fw-medium">
                                <?= htmlspecialchars($item['category'] ?? 'General') ?>
                            </span>
                            <h6 class="card-title fw-bold text-dark mb-1 text-truncate"><?= htmlspecialchars($item['itemName']) ?></h6>
                            
                            <!-- SPECIAL NOTE - Admin က ထည့်ထားတဲ့ Note (အနီရောင်) -->
                            <?php if (!empty($item['special_note'])): ?>
                                <small class="text-danger fw-bold mb-1" style="font-size: 0.7rem;">
                                    <i class="fa-solid fa-circle-exclamation me-1"></i>
                                    <?= htmlspecialchars($item['special_note']) ?>
                                </small>
                            <?php endif; ?>
                            
                            <p class="card-text text-brand fw-bold fs-6 mb-3"><?= number_format($item['points']) ?> <small class="text-muted fw-normal fs-7">Points</small></p>
                            
                            <div class="mt-auto">
                                <button onclick="addToCart(<?= $item['itemId'] ?>)" class="btn btn-brand btn-sm w-100 py-2 rounded-3 fw-medium">
                                    <i class="fa-solid fa-cart-plus me-1"></i>မှာယူမည်
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="detailModal<?= $item['itemId'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content rounded-4 border-0 overflow-hidden shadow">
                            <img src="<?= $imgPath ?>" class="w-100" style="height: 220px; object-fit: cover;">
                            <div class="modal-body p-4">
                                <span class="badge bg-brand-light text-brand mb-2"><?= htmlspecialchars($item['category'] ?? 'General') ?></span>
                                <h5 class="fw-bold text-dark"><?= htmlspecialchars($item['itemName']) ?></h5>
                                
                                <!-- SPECIAL NOTE in Detail Modal -->
                                <?php if (!empty($item['special_note'])): ?>
                                    <div class="alert alert-danger py-1 px-2 mb-2" style="font-size: 0.8rem;">
                                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                                        <?= htmlspecialchars($item['special_note']) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <h5 class="text-brand fw-bold mb-3"><?= number_format($item['points']) ?> Points</h5>
                                <p class="text-muted small mb-4">UCSH Canteen မှ လတ်ဆတ်စွာ ချက်ပြုတ်ပြင်ဆင်ပေးထားသော အစားအသောက်ဖြစ်ပါသည်။</p>
                                
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-light w-50 py-2 rounded-3" data-bs-dismiss="modal">ပိတ်မည်</button>
                                    <button onclick="addToCart(<?= $item['itemId'] ?>); bootstrap.Modal.getInstance(document.getElementById('detailModal<?= $item['itemId'] ?>')).hide();" class="btn btn-brand w-50 py-2 rounded-3 fw-medium">
                                        <i class="fa-solid fa-cart-plus me-1"></i>မှာယူမည်
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="text-center py-5 bg-white rounded-4 shadow-sm">
                    <i class="fa-solid fa-utensils fa-3x text-brand opacity-25 mb-3"></i>
                    <h6 class="text-dark fw-bold">လက်ရှိတွင် အစားအသောက်စာရင်းများ မရှိသေးပါ။</h6>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Category Filter
document.querySelectorAll('.category-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.category-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const category = this.dataset.category;
        const items = document.querySelectorAll('.menu-item-card');
        
        items.forEach(item => {
            if (category === 'all') {
                item.style.display = 'block';
            } else {
                const itemCategory = item.dataset.category;
                if (itemCategory === category) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            }
        });
    });
});

// Search Logic
document.getElementById('searchInput').addEventListener('input', function() {
    let filterValue = this.value.toLowerCase().trim();
    let items = document.querySelectorAll('.menu-item-card');

    items.forEach(function(item) {
        let name = item.getAttribute('data-name');
        let category = item.getAttribute('data-category');

        if (name.includes(filterValue) || category.includes(filterValue)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
});

// Add To Cart
function addToCart(itemId) {
    let formData = new FormData();
    formData.append('itemId', itemId);
    formData.append('quantity', 1);

    fetch('api.php?action=add_to_cart', { 
        method: 'POST', 
        body: formData 
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            let cartBadge = document.getElementById('cartBadge');
            let currentCount = parseInt(cartBadge.innerText) || 0;
            cartBadge.innerText = currentCount + 1;

            Swal.fire({
                icon: 'success',
                title: 'Cart ထဲသို့ ထည့်ပြီးပါပြီ',
                timer: 1500,
                showConfirmButton: false,
                toast: true,
                position: 'top',
                timerProgressBar: true,
                background: '#07494f',
                color: '#FFFFFF',
                iconColor: '#FFFFFF',
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Point မလုံလောက်ပါ',
                text: data.message || 'သင့်တွင် Point မလုံလောက်ပါ။',
                confirmButtonColor: '#1EAFBD',
            });
        }
    })
    .catch(err => {
        Swal.fire({
            icon: 'error',
            title: 'အမှားတစ်ခု ဖြစ်ပေါ်နေပါသည်',
            confirmButtonColor: '#1EAFBD'
        });
    });
}

// Toggle Like
function toggleLike(btn, itemId) {
    let likeBadge = document.getElementById('likeBadge');
    let currentCount = parseInt(likeBadge.innerText) || 0;
    let isAdd = !btn.classList.contains('active');
    if (isAdd) {
        btn.classList.add('active');
        likeBadge.innerText = currentCount + 1;
    } else {
        btn.classList.remove('active');
        likeBadge.innerText = Math.max(0, currentCount - 1);
    }

    let formData = new FormData();
    formData.append('itemId', itemId);
    formData.append('action', isAdd ? 'add' : 'remove');

    fetch('toggle_like.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            btn.classList.toggle('active');
            likeBadge.innerText = currentCount;
        }
    })
    .catch(err => {
        btn.classList.toggle('active');
        likeBadge.innerText = currentCount;
    });
}

// Download Voucher
function downloadVoucher() {
    const element = document.getElementById('voucherContent');
    html2canvas(element, {
        scale: 2,
        backgroundColor: '#ffffff'
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = 'ucsh_voucher.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
}

// Show Voucher on page load if exists
<?php if ($showVoucher && $voucherData): ?>
document.addEventListener('DOMContentLoaded', function() {
    const orderData = <?= json_encode($voucherData) ?>;
    
    document.getElementById('vQueueNumber').innerText = orderData.queueNumber;
    document.getElementById('vCustomerName').innerText = '<?= htmlspecialchars($_SESSION['username'] ?? 'Customer') ?>';
    document.getElementById('vOrderDate').innerText = new Date().toLocaleString();
    document.getElementById('vPickupTime').innerText = orderData.pickupTime;
    document.getElementById('vTotalAmount').innerText = Number(orderData.totalAmount).toLocaleString();
    document.getElementById('vPointsUsed').innerText = Number(orderData.pointsUsed).toLocaleString();
    document.getElementById('vItemsList').innerHTML = orderData.items || 'No items';
    document.getElementById('vItemsSummary').innerHTML = `<span>Order #${orderData.orderId}</span><span>${Number(orderData.totalAmount).toLocaleString()} Points</span>`;
    
    const myModal = new bootstrap.Modal(document.getElementById('voucherModal'));
    myModal.show();
});
<?php endif; ?>
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>