<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
// header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Headers: Content-Type, X-Signature');

// Конфигурация
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '-');
// define('TELEGRAM_CHAT_ID', getenv('TELEGRAM_CHAT_ID') ?: '2060119820');
define('TELEGRAM_CHAT_ID', getenv('TELEGRAM_CHAT_ID') ?: '2060119820');
define('HMAC_SECRET', getenv('HMAC_SECRET') ?: 'cor-it');

// Ограничение количества запросов
session_start();
if (!isset($_SESSION['request_count'])) {
    $_SESSION['request_count'] = 0;
    $_SESSION['last_request_time'] = time();
}

// Проверка ограничения запросов
if ($_SESSION['request_count'] > 10 && (time() - $_SESSION['last_request_time']) < 1) {
    die(json_encode(['success' => false, 'message' => 'Слишком много запросов. Попробуйте позже.']));
}

$_SESSION['request_count']++;
$_SESSION['last_request_time'] = time();

// Функция валидации данных
function validateData($data) {
    $errors = [];
    
    // Валидация имени родителя
    if (empty($data['Parents']) || strlen(trim($data['Parents'])) < 2) {
        $errors[] = 'Имя родителя должно содержать минимум 2 символа';
    }
    
    // Валидация телефона
    $phone = preg_replace('/[^\d]/', '', $data['Phone']);
    if (empty($data['Phone']) || strlen($phone) < 10 || strlen($phone) > 15) {
        $errors[] = 'Некорректный номер телефона';
    }
    
    // Валидация сообщения
    if (empty($data['message']) || strlen(trim($data['message'])) < 5) {
        $errors[] = 'Сообщение должно содержать минимум 5 символов';
    }
    
    // Проверка времени запроса
    if ((time() - $data['timestamp']) > 300) {
        $errors[] = 'Подозрительный запрос';
    }
    
    return $errors;
}

// Функция для создания HMAC подписи
function createHMACSignature($data) {
    $data_string = json_encode($data);
    return hash_hmac('sha256', $data_string, HMAC_SECRET);
}

// Функция для отправки сообщения в Telegram
function sendToTelegram($data, $signature) {
    $message = "🔔 Новое сообщение от родителя:\n\n";
    $message .= "👤 Имя: " . htmlspecialchars($data['Parents']) . "\n";
    $message .= "📞 Телефон: " . htmlspecialchars($data['Phone']) . "\n";
    $message .= "📝 Сообщение: " . htmlspecialchars($data['message']) . "\n";
    $message .= "🔒 Подпись (HMAC): " . $signature . "\n";
    $message .= "🔒 Timestamp: " . $data['timestamp'] . "\n";
    $message .= "⏰ Время: " . date('d.m.Y H:i:s') . "\n";
    
    // $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $url = "https://cor-it.tech/send_message";

    
    $postData = [
        'telegram_id' => TELEGRAM_CHAT_ID,
        'message' => $message,
        'timestamp' => $data['timestamp']
    ];
    $signature = createHMACSignature($postData);
    // Проверяем, доступен ли cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "x-signature: $signature"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Для локальной разработки
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        // return $httpCode === 200 && isset($responseData['ok']) && $responseData['ok'] === true;
        return $httpCode === 200;
    } 
    // Если cURL недоступен, используем file_get_contents с контекстом
    else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                "x-signature: " . $signature . "\r\n",
                'content' => json_encode($postData),
                'timeout' => 10
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            return false;
        }
        else {
            return true;
        }
        
        $responseData = json_decode($response, true);
        return isset($responseData['ok']) && $responseData['ok'] === true;
    }
}

// Основная логика
try {
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не разрешен');
    }
    
    // Получение данных
    $data = [
        'Parents' => isset($_POST['Parents']) ? trim($_POST['Parents']) : '',
        'Phone' => isset($_POST['Phone']) ? trim($_POST['Phone']) : '',
        'message' => isset($_POST['message']) ? trim($_POST['message']) : '',
        'timestamp' => time(),
        'nonce' => isset($_POST['nonce']) ? $_POST['nonce'] : uniqid()
    ];
    
    // Валидация данных
    $errors = validateData($data);
    if (!empty($errors)) {
        throw new Exception(implode(', ', $errors));
    }
    // Создание HMAC подписи
    $signature = createHMACSignature($data);
    
    // Отправка в Telegram
    $sent = sendToTelegram($data, $signature);
    // echo json_encode(['success' => true, 'message' => $sent]);
    
    if ($sent) {
        echo json_encode(['success' => true, 'message' => 'Сообщение успешно отправлено!']);
    } else {
        // throw new Exception('Ошибка отправки сообщения в Telegram');
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Ошибка отправки сообщения в Telegram']);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    // echo "<script>console.error('Ошибка сервера: " . addslashes($e->getMessage()) . "');</script>";
    exit;
}
?>