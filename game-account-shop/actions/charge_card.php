<?php
// actions/charge_card.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    redirect($base_url . '/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($base_url . '/pages/deposit.php');
}

// CSRF verification
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Lỗi bảo mật: CSRF token không hợp lệ.');
}

// Rate limit nạp thẻ: tối đa 3 lần trong 5 phút
if (!checkRateLimit('charge_card', 3, 300)) {
    $_SESSION['deposit_error'] = 'Quá nhiều yêu cầu nạp thẻ. Vui lòng thử lại sau 5 phút.';
    redirect($base_url . '/pages/deposit.php');
}

$user_id = $_SESSION['user_id'];
$telco = strtoupper(trim($_POST['telco'] ?? ''));
$amount = (int)($_POST['amount'] ?? 0);
$serial = trim($_POST['serial'] ?? '');
$code = trim($_POST['code'] ?? '');

// Validate: telco phải thuộc whitelist, amount phải thuộc các mệnh giá hợp lệ
$allowed_amounts = [10000, 20000, 30000, 50000, 100000, 200000, 300000, 500000, 1000000];

if (empty($telco) || empty($amount) || empty($serial) || empty($code)) {
    $_SESSION['deposit_error'] = 'Vui lòng nhập đầy đủ thông tin thẻ cào.';
    redirect($base_url . '/pages/deposit.php');
}

if (!isValidTelco($telco)) {
    $_SESSION['deposit_error'] = 'Nhà mạng không hợp lệ.';
    redirect($base_url . '/pages/deposit.php');
}

if (!in_array($amount, $allowed_amounts, true)) {
    $_SESSION['deposit_error'] = 'Mệnh giá không hợp lệ.';
    redirect($base_url . '/pages/deposit.php');
}

// Validate định dạng serial & code (chỉ chấp nhận chữ/số, độ dài hợp lý)
if (!preg_match('/^[A-Za-z0-9]{8,20}$/', $serial) || !preg_match('/^[A-Za-z0-9]{8,20}$/', $code)) {
    $_SESSION['deposit_error'] = 'Số seri hoặc mã thẻ không đúng định dạng.';
    redirect($base_url . '/pages/deposit.php');
}

// Lấy config từ settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('doithe1s_partner_id', 'doithe1s_partner_key')");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$partner_id = $settings['doithe1s_partner_id'] ?? '';
$partner_key = $settings['doithe1s_partner_key'] ?? '';

if (empty($partner_id) || empty($partner_key)) {
    $_SESSION['deposit_error'] = 'Hệ thống nạp thẻ đang bảo trì (thiếu cấu hình API). Vui lòng báo Admin.';
    redirect($base_url . '/pages/deposit.php');
}

// Tạo request_id duy nhất
$request_id = random_int(100000000, 999999999);

// Lưu vào DB trạng thái pending (mã hóa code + serial)
$stmt = $pdo->prepare("INSERT INTO card_transactions (user_id, request_id, telco, amount, serial, code, status, provider) VALUES (?, ?, ?, ?, ?, ?, 0, 'doithe1s')");
if (!$stmt->execute([$user_id, $request_id, $telco, $amount, pxEncrypt($serial), pxEncrypt($code)])) {
    $_SESSION['deposit_error'] = 'Lỗi hệ thống khi tạo giao dịch.';
    redirect($base_url . '/pages/deposit.php');
}

// Gọi API doithe1s.vn
$dataPost = array();
$dataPost['request_id'] = $request_id;
$dataPost['code'] = $code;
$dataPost['partner_id'] = $partner_id;
$dataPost['serial'] = $serial;
$dataPost['telco'] = $telco;
$dataPost['amount'] = $amount;
$dataPost['command'] = 'charging';
$dataPost['sign'] = md5($partner_key . $code . $serial);

$data = http_build_query($dataPost);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://doithe1s.vn/chargingws/v2');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
$actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . getBasePath();
curl_setopt($ch, CURLOPT_REFERER, $actual_link);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$result = curl_exec($ch);
curl_close($ch);

$obj = json_decode($result);

if ($obj) {
    // 99 = chờ xử lý, 1 = thẻ đúng, 2 = thẻ sai mệnh giá, 3 = thẻ lỗi, 4 = bảo trì
    $api_status = $obj->status ?? 3;
    $api_message = $obj->message ?? 'Lỗi không xác định';
    
    // Cập nhật trạng thái trans_id và message
    $trans_id = $obj->trans_id ?? null;
    
    $updateStmt = $pdo->prepare("UPDATE card_transactions SET trans_id = ?, status = ?, message = ? WHERE request_id = ?");
    $updateStmt->execute([$trans_id, $api_status, $api_message, $request_id]);
    
    if ($api_status == 99) {
         $_SESSION['deposit_success'] = 'Thẻ của bạn đã được gửi lên hệ thống và đang chờ xử lý. Vui lòng kiểm tra lại trạng thái sau ít phút.';
    } elseif ($api_status == 1) {
         // Nếu thẻ lỗi ngay từ lúc gửi (hiếm khi API gạch thẻ cào báo 1 ngay trừ thẻ siêu nhanh)
         $_SESSION['deposit_success'] = 'Nạp thẻ thành công!';
    } elseif ($api_status == 2) {
         $_SESSION['deposit_error'] = 'Thẻ sai mệnh giá! Vui lòng liên hệ Admin.';
    } elseif ($api_status == 3) {
         $_SESSION['deposit_error'] = 'Thẻ lỗi: ' . $api_message;
    } elseif ($api_status == 4) {
         $_SESSION['deposit_error'] = 'Hệ thống thẻ đang bảo trì: ' . $api_message;
    } else {
         $_SESSION['deposit_error'] = 'Lỗi nạp thẻ: ' . $api_message;
    }
} else {
    $_SESSION['deposit_error'] = 'Không thể kết nối đến máy chủ xử lý thẻ.';
}

redirect($base_url . '/pages/deposit.php');
