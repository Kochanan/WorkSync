<?php
require_once 'init.php';
require_login();
check_csrf();

// ดึงหมวดหมู่
$cats = $conn->query("SELECT id, icon, name FROM categories ORDER BY sort ASC")->fetch_all(MYSQLI_ASSOC);

// อ่านค่า POST แบบข้อความเท่านั้น กันส่งมาเป็น array แล้ว trim() พังเป็น Fatal error
$ps = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title  = trim($ps('title'));
    $desc   = trim($ps('description'));
    $price  = (float)($_POST['price'] ?? 0);
    $stock  = (int)($_POST['stock'] ?? 0);
    $catId  = (int)($_POST['category_id'] ?? 0);
    // หมวดหมู่ต้องมีอยู่จริง ไม่งั้นสินค้าจะหลุดไปอยู่หมวดที่ไม่มีใครเห็น
    if ($catId > 0 && !in_array($catId, array_map('intval', array_column($cats, 'id')), true)) {
        $catId = 0;
    }

    if ($title === '' || !is_finite($price) || $price <= 0 || $stock < 0) {
        $err = 'กรุณากรอกชื่อสินค้า ราคา และจำนวนคงคลังให้ถูกต้องแม่นยำ';
    } elseif (mb_strlen($title) > 255 || mb_strlen($desc) > 10000) {
        $err = 'ชื่อสินค้ายาวได้ไม่เกิน 255 ตัวอักษร และคำอธิบายไม่เกิน 10,000 ตัวอักษร';
    } elseif ($price > 9999999 || $stock > 1000000) {
        $err = 'ราคาต้องไม่เกิน 9,999,999 บาท และสต็อกต้องไม่เกิน 1,000,000 ชิ้น';
    } else {
        $uploadDir = __DIR__ . '/assets/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $f = $_FILES['image'];
            $allowedExt  = ['jpg', 'jpeg', 'png', 'webp'];
            // นามสกุลไฟล์ที่บันทึกจริงเลือกจากชนิดไฟล์ที่ตรวจได้ ไม่ใช้นามสกุลที่ผู้ใช้ส่งมา
            $mimeExt     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $imgTypes    = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
            $maxSize     = 2 * 1024 * 1024; // ล็อกขนาดไว้ที่ 2MB

            $ext = is_string($f['name']) ? strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) : '';

            if (is_array($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
                $err = 'เกิดข้อผิดพลาดภายในระบบขั้นตอนการอัปโหลดไฟล์ รูปภาพ (ไฟล์อาจใหญ่เกิน 2MB)';
            } elseif (!in_array($ext, $allowedExt, true)) {
                $err = 'ไฟล์ที่อนุญาตต้องเป็น jpg, jpeg, png หรือ webp เท่านั้น';
            } elseif ($f['size'] > $maxSize) {
                $err = 'ขนาดไฟล์รูปภาพห้ามมีขนาดเกินกว่า 2MB';
            } else {
                // ดึงข้อมูล MIME Type ที่แท้จริงด้วย Fileinfo API ป้องกันแฮกเกอร์ปลอมแปลงนามสกุลไฟล์
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fi, $f['tmp_name']);
                finfo_close($fi);
                $info = @getimagesize($f['tmp_name']);
                $raw  = (string)@file_get_contents($f['tmp_name']);

                if (!isset($mimeExt[$mime]) || !$info || !in_array($info[2], $imgTypes, true)
                    || $info[0] < 1 || $info[1] < 1
                    || stripos($raw, '<?php') !== false) { // กันไฟล์ polyglot ที่ฝังโค้ด PHP มาในรูป
                    $err = 'ไฟล์รูปภาพไม่ถูกต้องตามมาตรฐานรูปภาพจริง';
                } else {
                    // ทำการเข้ารหัสแบบปลอดภัยสุ่มตั้งชื่อไฟล์ใหม่ทั้งหมดเพื่อตัดขาด RCE และ Script Execution
                    $newFileName = bin2hex(random_bytes(16)) . '.' . $mimeExt[$mime];
                    $target = $uploadDir . '/' . $newFileName;
                    
                    if (!move_uploaded_file($f['tmp_name'], $target)) {
                        $err = 'ระบบไม่สามารถเคลื่อนย้ายไฟล์ไปยังเป้าหมายของเซิร์ฟเวอร์ได้';
                    } else {
                        $imagePath = 'assets/uploads/' . $newFileName;
                    }
                }
            }
        }

        // หากไม่มีข้อผิดพลาด บันทึกข้อมูลลงฐานข้อมูลโดยตรงอย่างปลอดภัยด้วย Prepared Statements
        if ($err === '') {
            $uid    = (int)$_SESSION['user_id'];
            $catVal = $catId > 0 ? $catId : null;
            $st = $conn->prepare("INSERT INTO products (seller_id, category_id, title, description, price, stock, image) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param('iissdis', $uid, $catVal, $title, $desc, $price, $stock, $imagePath);

            if ($st->execute()) {
                $newProductId = $st->insert_id;
                $st->close();

                if (isset($_POST['is_lendable'])) {
                    $lendDays = max(1, min(30, (int)($_POST['lending_days_limit'] ?? 7)));
                    $deposit  = (float)($_POST['deposit_required'] ?? 0);
                    $deposit  = is_finite($deposit) ? max(0, min(9999999, $deposit)) : 0;
                    $lendNote = mb_substr(trim($ps('lending_note')), 0, 300);
                    $lendIns = $conn->prepare(
                        "INSERT INTO product_lending_info (product_id, is_lendable, lending_days_limit, deposit_required, lending_note)
                         VALUES (?, 1, ?, ?, ?)"
                    );
                    $lendIns->bind_param('iids', $newProductId, $lendDays, $deposit, $lendNote);
                    $lendIns->execute();
                    $lendIns->close();
                }

                header('Location: product.php?id=' . (int)$newProductId);
                exit;
            } else {
                $err = 'บันทึกข้อมูลสินค้าล้มเหลว กรุณาตรวจสอบอีกครั้ง';
            }
            $st->close();
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>WorkSync — เพิ่มรายการสินค้าชิ้นใหม่</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
  <div class="box" style="max-width:720px; margin:30px auto; padding:25px;">
    <h2 style="margin-bottom:15px;">➕ เพิ่มข้อมูลสินค้าใหม่เข้าระบบ</h2>
    
    <?php if ($err): ?>
        <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold;">
           ⚠️ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
      <?= csrf_tag() ?>
      <label style="font-weight:bold; display:block; margin-bottom:5px;">ชื่อสินค้า</label>
      <input class="input" name="title" maxlength="255" placeholder="พิมพ์ชื่อสินค้าตรงนี้..." required style="width:100%; margin-bottom:15px;" value="<?= htmlspecialchars($ps('title'), ENT_QUOTES, 'UTF-8') ?>">

      <label style="font-weight:bold; display:block; margin-bottom:5px;">หมวดหมู่สินค้า</label>
      <select class="input" name="category_id" id="categorySelect" style="width:100%; margin-bottom:15px;">
        <option value="">— ไม่ระบุหมวดหมู่ —</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (isset($_POST['category_id']) && (int)$_POST['category_id'] === (int)$c['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['icon'].' '.$c['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div id="priceRecoBox" style="display:none; margin:-8px 0 15px; padding:14px 16px; background:#eef6ff; border:1px solid #cfe4fb; border-radius:12px;">
        <div style="font-size:12.5px; font-weight:700; color:#0d2137; margin-bottom:8px;">💡 ราคาแนะนำจากข้อมูลตลาด</div>
        <div id="priceRecoContent" style="font-size:12px; color:#5f6b7a;"></div>
      </div>

      <label style="font-weight:bold; display:block; margin-bottom:5px;">คำอธิบายรายละเอียด</label>
      <textarea class="input" name="description" rows="5" placeholder="พิมพ์รายละเอียดย่อเกี่ยวกับสินค้า..." style="width:100%; margin-bottom:15px; font-family:inherit;"><?= htmlspecialchars($ps('description'), ENT_QUOTES, 'UTF-8') ?></textarea>

      <div class="row" style="display:flex; gap:15px; margin-bottom:15px;">
        <div style="flex:1;">
          <label style="font-weight:bold; display:block; margin-bottom:5px;">ราคา (บาท)</label>
          <input class="input" type="number" name="price" step="0.01" min="0.01" max="9999999" placeholder="0.00" required style="width:100%;" value="<?= htmlspecialchars($ps('price'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div style="flex:1;">
          <label style="font-weight:bold; display:block; margin-bottom:5px;">จำนวนสินค้าในคลัง</label>
          <input class="input" type="number" name="stock" min="0" max="1000000" placeholder="0" required style="width:100%;" value="<?= htmlspecialchars($ps('stock'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <label style="font-weight:bold; display:block; margin-bottom:8px;">ภาพหน้าปกสินค้า (ไม่เกิน 2MB)</label>
      <div id="dropZone"
           style="border:2px dashed rgba(13,33,55,.2); border-radius:14px;
                  padding:28px 20px; text-align:center; cursor:pointer;
                  background:#fafafa; transition:border-color .2s, background .2s;
                  margin-bottom:20px; position:relative;">
        <div id="dropContent">
          <div style="font-size:36px; margin-bottom:8px;">🖼️</div>
          <div style="font-weight:600; color:#3d5066; margin-bottom:4px;">ลากรูปมาวางที่นี่</div>
          <div style="font-size:13px; color:#aaa; margin-bottom:12px;">หรือ</div>
          <label for="imageInput" style="background:#0d2137; color:#fff; padding:8px 20px;
                 border-radius:999px; font-size:13px; font-weight:700; cursor:pointer;">
            เลือกรูปภาพ
          </label>
          <div style="font-size:11px; color:#bbb; margin-top:8px;">JPG, PNG, WEBP · ไม่เกิน 2MB</div>
        </div>
        <div id="previewWrap" style="display:none;">
          <img id="imgPreview" src="" alt=""
               style="max-height:200px; max-width:100%; border-radius:10px; object-fit:contain;">
          <div style="margin-top:8px;">
            <span id="fileName" style="font-size:12px; color:#666;"></span>
            <button type="button" onclick="clearImg()"
                    style="margin-left:10px; background:none; border:none;
                           color:#c8102e; font-size:12px; cursor:pointer; font-weight:600;">ลบรูป ×</button>
          </div>
        </div>
        <input type="file" id="imageInput" name="image" accept="image/*"
               style="position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;">
      </div>

      <div style="border:1.5px solid rgba(15,155,94,.25); background:#f3fbf7; border-radius:12px; padding:16px 18px; margin-bottom:20px;">
        <label style="display:flex; align-items:center; gap:8px; font-weight:700; cursor:pointer; margin-bottom:4px;">
          <input type="checkbox" name="is_lendable" value="1" id="lendToggle" style="width:16px; height:16px;">
          🤝 เปิดให้ยืมสินค้านี้ (นอกเหนือจากขาย)
        </label>
        <div style="font-size:12px; color:#7a8fa6; margin-bottom:12px;">
          ผู้ซื้อที่มี Trust Score ตั้งแต่ 40 ขึ้นไปจะยืมสินค้านี้ได้ผ่านหน้า "ยืม-คืนสินค้าชุมชน"
        </div>
        <div id="lendFields" style="display:none; gap:14px; grid-template-columns:1fr 1fr;">
          <div>
            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">ยืมได้สูงสุด (วัน)</label>
            <input class="input" type="number" name="lending_days_limit" value="7" min="1" max="30" style="width:100%;">
          </div>
          <div>
            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">เงินมัดจำ (บาท)</label>
            <input class="input" type="number" name="deposit_required" value="0" min="0" step="0.01" style="width:100%;">
          </div>
          <div style="grid-column:1/-1;">
            <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">หมายเหตุการยืม (ถ้ามี)</label>
            <input class="input" name="lending_note" maxlength="300" placeholder="เช่น ต้องนัดรับ-คืนด้วยตัวเอง" style="width:100%;">
          </div>
        </div>
      </div>

      <button type="submit" class="btn" style="width:100%; padding:10px; font-size:16px;">บันทึกและขึ้นโชว์ขายสินค้า</button>
    </form>
  </div>
</div>
<script>
const zone   = document.getElementById('dropZone');
const input  = document.getElementById('imageInput');
const prev   = document.getElementById('previewWrap');
const cont   = document.getElementById('dropContent');
const imgEl  = document.getElementById('imgPreview');
const fnEl   = document.getElementById('fileName');

function showPreview(file) {
  if (!file) return;
  const r = new FileReader();
  r.onload = e => { imgEl.src = e.target.result; };
  r.readAsDataURL(file);
  fnEl.textContent = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
  prev.style.display = 'block';
  cont.style.display = 'none';
}
function clearImg() {
  input.value = '';
  prev.style.display = 'none';
  cont.style.display = 'block';
  imgEl.src = '';
}

input.addEventListener('change', () => showPreview(input.files[0]));

const lendToggle = document.getElementById('lendToggle');
const lendFields = document.getElementById('lendFields');
lendToggle.addEventListener('change', () => { lendFields.style.display = lendToggle.checked ? 'grid' : 'none'; });

zone.addEventListener('dragover', e => { e.preventDefault(); zone.style.borderColor='#2756a0'; zone.style.background='#e8f0f9'; });
zone.addEventListener('dragleave', () => { zone.style.borderColor=''; zone.style.background=''; });
zone.addEventListener('drop', e => {
  e.preventDefault();
  zone.style.borderColor=''; zone.style.background='';
  const file = e.dataTransfer.files[0];
  if (file) {
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    showPreview(file);
  }
});

// Price recommendation
const categorySelect = document.getElementById('categorySelect');
const priceInputEl   = document.querySelector('input[name="price"]');
const recoBox        = document.getElementById('priceRecoBox');
const recoContent    = document.getElementById('priceRecoContent');

async function loadPriceReco() {
  const catId = categorySelect.value;
  if (!catId) { recoBox.style.display = 'none'; return; }
  try {
    const res  = await fetch('price_recommend.php?cat_id=' + encodeURIComponent(catId));
    const data = await res.json();
    if (!data.ok) { recoBox.style.display = 'none'; return; }

    let html = `<div style="margin-bottom:10px;">${data.tip}</div><div style="display:flex; gap:8px; flex-wrap:wrap;">`;
    data.strategies.forEach(s => {
      html += `<button type="button" class="price-reco-btn" data-price="${s.price}"
                 style="flex:1; min-width:150px; text-align:left; padding:9px 12px; border-radius:10px; cursor:pointer;
                        border:1.5px solid ${s.recommended ? '#0d2137' : '#dce3ea'}; background:#fff;">
                 <div style="font-size:11.5px; font-weight:700; color:#3d5066;">${s.name}</div>
                 <div style="font-size:15px; font-weight:800; color:#0d2137;">฿${Number(s.price).toLocaleString()}</div>
               </button>`;
    });
    html += '</div>';
    recoContent.innerHTML = html;
    recoBox.style.display = 'block';

    recoContent.querySelectorAll('.price-reco-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        priceInputEl.value = btn.dataset.price;
        checkForm();
      });
    });
  } catch (e) {
    recoBox.style.display = 'none';
  }
}
categorySelect.addEventListener('change', loadPriceReco);
if (categorySelect.value) loadPriceReco();

// Disabled submit ถ้ากรอกไม่ครบ
const submitBtn = document.querySelector('button[type="submit"]');
const titleIn   = document.querySelector('input[name="title"]');
const priceIn   = document.querySelector('input[name="price"]');
const stockIn   = document.querySelector('input[name="stock"]');
function checkForm() {
  const ok = titleIn.value.trim() !== '' && parseFloat(priceIn.value) > 0 && parseInt(stockIn.value) >= 0;
  submitBtn.disabled = !ok;
  submitBtn.style.opacity = ok ? '1' : '0.5';
  submitBtn.style.cursor  = ok ? 'pointer' : 'not-allowed';
}
[titleIn, priceIn, stockIn].forEach(el => el && el.addEventListener('input', checkForm));
checkForm();
</script>
</body>
</html>