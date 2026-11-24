<?php /* navbar.php v2 — icons + search bar + logout btn */ ?>
<?php
if (!isset($conn)) { return; } // ถูกเรียกตรงหรือ include ก่อนต่อ DB
// Badge counts
$_badge_cart = 0;
$_badge_notif = 0;
if (isset($_SESSION['user_id'])) {
    $__uid = (int)$_SESSION['user_id'];
    $__sc = $conn->prepare("SELECT COALESCE(SUM(qty),0) cnt FROM cart WHERE user_id=?");
    $__sc->bind_param('i', $__uid); $__sc->execute();
    $_badge_cart = (int)$__sc->get_result()->fetch_row()[0]; $__sc->close();
    $__sn = $conn->prepare("SELECT COUNT(*) cnt FROM notifications WHERE user_id=? AND is_read=0");
    $__sn->bind_param('i', $__uid); $__sn->execute();
    $_badge_notif = (int)$__sn->get_result()->fetch_row()[0]; $__sn->close();
    // ลิงก์ Coupon โชว์เฉพาะแอดมินและคนที่มีสินค้าลงขาย (ตรงกับสิทธิ์ใน manage_coupons.php)
    $__sp = $conn->prepare("SELECT 1 FROM products WHERE seller_id=? LIMIT 1");
    $__sp->bind_param('i', $__uid); $__sp->execute();
    $_is_seller = (bool)$__sp->get_result()->fetch_row(); $__sp->close();
}
$_can_coupon = (($_SESSION['role'] ?? '') === 'admin') || !empty($_is_seller);
?>
<nav class="navbar">

  <div class="brand">
    <a href="index.php">WorkSync</a>
  </div>

  <div class="search">
    <form method="GET" action="index.php">
      <div class="search-inner">
        <span class="search-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
        </span>
        <input name="q" placeholder="ค้นหาสินค้า..."
               value="<?= htmlspecialchars(is_string($_GET['q'] ?? null) ? $_GET['q'] : '', ENT_QUOTES, 'UTF-8') ?>">
      </div>
    </form>
  </div>

  <div class="nav-actions">
    <?php if (isset($_SESSION['user_id'])): ?>

      <a href="user_profile.php" class="nav-link-user">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        <span><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></span>
      </a>

      <a href="cart.php" style="position:relative;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
        <?php if ($_badge_cart > 0): ?>
          <span style="position:absolute;top:-6px;right:-6px;background:#ff5c35;color:#fff;
                       font-size:10px;font-weight:700;min-width:18px;height:18px;
                       border-radius:999px;display:flex;align-items:center;justify-content:center;
                       padding:0 4px;line-height:1;border:2px solid #fff;">
            <?= $_badge_cart > 99 ? '99+' : $_badge_cart ?>
          </span>
        <?php endif; ?>
        <span>ตะกร้า</span>
      </a>

      <a href="orders.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        <span>ออเดอร์</span>
      </a>

      <a href="wishlist.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
        </svg>
        <span>โปรด</span>
      </a>

      <a href="lending.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        <span>ยืม-คืน</span>
      </a>

      <?php if ($_can_coupon): ?>
      <a href="manage_coupons.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2z"/><line x1="12" y1="12" x2="12" y2="17"/><line x1="9.5" y1="14.5" x2="14.5" y2="14.5"/>
        </svg>
        <span>Coupon</span>
      </a>
      <?php endif; ?>

      <a href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
          <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
        </svg>
        <span>Dashboard</span>
      </a>
      <a href="add_product.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
          <line x1="9" y1="10" x2="15" y2="10"/><line x1="12" y1="7" x2="12" y2="13"/>
        </svg>
        <span>ลงขาย</span>
      </a>

      <div class="nav-divider"></div>

      <a href="seller_inbox.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <span>ข้อความ</span>
      </a>

      <a href="notifications.php" style="position:relative;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <?php if ($_badge_notif > 0): ?>
          <span style="position:absolute;top:-6px;right:-6px;background:#e53935;color:#fff;
                       font-size:10px;font-weight:700;min-width:18px;height:18px;
                       border-radius:999px;display:flex;align-items:center;justify-content:center;
                       padding:0 4px;line-height:1;border:2px solid #fff;">
            <?= $_badge_notif > 99 ? '99+' : $_badge_notif ?>
          </span>
        <?php endif; ?>
        <span>แจ้งเตือน</span>
      </a>

      <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <a href="admin_chat.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
          <span>Support</span>
        </a>
      <?php else: ?>
        <a href="chat.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          <span>Support</span>
        </a>
      <?php endif; ?>

      <div class="nav-divider"></div>

      <form id="logoutForm" method="POST" action="logout.php" style="display:none"><?= csrf_tag() ?></form>
      <a href="logout.php" class="nav-link-logout"
         onclick="event.preventDefault(); document.getElementById('logoutForm').submit();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <span>ออกจากระบบ</span>
      </a>

    <?php else: ?>
      <a href="login.php">เข้าสู่ระบบ</a>
      <a href="register.php" class="nav-link-register" style="color:#fff !important; -webkit-text-fill-color:#fff !important;">สมัครสมาชิก</a>
    <?php endif; ?>
  </div>

</nav>

<?php if (isset($_SESSION['user_id'])): ?>
<!-- Bottom Nav — mobile only -->
<nav class="bottom-nav">
  <div class="bottom-nav-inner">

    <a href="index.php" class="bn-item <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
      <span class="bn-icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </span>
      หน้าหลัก
    </a>

    <a href="seller_inbox.php" class="bn-item <?= basename($_SERVER['PHP_SELF']) === 'seller_inbox.php' ? 'active' : '' ?>">
      <span class="bn-icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      ข้อความ
    </a>

    <a href="cart.php" class="bn-item <?= basename($_SERVER['PHP_SELF']) === 'cart.php' ? 'active' : '' ?>">
      <span class="bn-icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
      </span>
      ตะกร้า
    </a>

    <a href="orders.php" class="bn-item <?= basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'active' : '' ?>">
      <span class="bn-icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
        </svg>
      </span>
      ออเดอร์
    </a>

    <a href="user_profile.php" class="bn-item <?= basename($_SERVER['PHP_SELF']) === 'user_profile.php' ? 'active' : '' ?>">
      <span class="bn-icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
      </span>
      โปรไฟล์
    </a>

  </div>
</nav>
<?php endif; ?>
