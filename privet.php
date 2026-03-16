<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');


define('SECRET_KEY', 'rand');
define('BOT_TOKEN', '8763686509:AAFTpbyzE6wnDah146i5pSOtDldQZbv5hps');
define('CHAT_ID', '7556497932');


session_start();


$log_file = __DIR__ . '/telegram_proxy_log.txt';

function add_log($message) {
    global $log_file;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = date('Y-m-d H:i:s');
    $log_entry = "[$time] [$ip] $message\n";
    
    
    if (file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024) {
        file_put_contents($log_file, "=== Log cleared at $time ===\n");
    }
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

add_log("=== НАЧАЛО ЗАПРОСА ===");
add_log("GET параметры: " . json_encode($_GET, JSON_UNESCAPED_UNICODE));

$key = $_GET['key'] ?? '';
if ($key !== SECRET_KEY) {
    add_log("ОШИБКА: Неверный ключ");
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden', 'received_key' => substr($key, 0, 5) . '...'], JSON_UNESCAPED_UNICODE));
}

add_log("✅ Ключ верный");



$last_message = $_SESSION['last_message_time'] ?? 0;
if (time() - $last_message < 10) {
    add_log("🚫 Слишком частый запрос");
    die(json_encode(['error' => 'Wait 30 seconds'], JSON_UNESCAPED_UNICODE));
}
$_SESSION['last_message_time'] = time();


$ip = $_SERVER['REMOTE_ADDR'];
$ip_key = 'ip_count_' . $ip;
$ip_count = $_SESSION[$ip_key] ?? 0;

if ($ip_count > 10) { 
    add_log("🚫 Лимит исчерпан для IP: $ip ($ip_count/50)");
    die(json_encode(['error' => 'Daily limit exceeded'], JSON_UNESCAPED_UNICODE));
}

$_SESSION[$ip_key] = $ip_count + 1;
add_log("📊 IP $ip: отправлено $ip_count/50 сообщений сегодня");

$text = $_GET['text'] ?? '';
if (empty($text)) {
    add_log("ОШИБКА: Нет параметра text");
    die(json_encode(['error' => 'No text parameter'], JSON_UNESCAPED_UNICODE));
}

$decoded_text = urldecode($text);
add_log("Текст декодирован, длина: " . strlen($decoded_text));

if (!mb_check_encoding($decoded_text, 'UTF-8')) {
    $decoded_text = iconv('CP1251', 'UTF-8//IGNORE', $decoded_text);
    add_log("Исправлена кодировка CP1251 -> UTF-8");
}

add_log("Текст после обработки: " . substr($decoded_text, 0, 100) . "...");


$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";

$post_data = [
    'chat_id' => CHAT_ID,
    'text' => $decoded_text,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true
];

add_log("Отправляю в Telegram API...");


$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($post_data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: TelegramBotProxy/1.0'
    ],
    CURLOPT_ENCODING => ''
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);

curl_close($ch);

add_log("Ответ Telegram: HTTP $http_code, cURL ошибка: $curl_error");

if ($http_code == 200) {
    $result = json_decode($response, true);
    $message_id = $result['result']['message_id'] ?? 'unknown';
    
    add_log("✅ УСПЕХ! Сообщение отправлено, ID: $message_id");
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'telegram_ok' => $result['ok'] ?? false,
        'server' => 'beget.com',
        'protection' => [
            'ip_requests_today' => $ip_count,
            'last_message_seconds_ago' => time() - $last_message
        ]
    ], JSON_UNESCAPED_UNICODE);
} else {
    add_log("❌ ОШИБКА: HTTP $http_code, ответ: " . substr($response, 0, 200));
    
    echo json_encode([
        'success' => false,
        'error' => 'Telegram API error',
        'http_code' => $http_code,
        'curl_error' => $curl_error,
        'response_preview' => substr($response, 0, 200)
    ], JSON_UNESCAPED_UNICODE);
}

add_log("=== КОНЕЦ ЗАПРОСА ===\n");
?>
