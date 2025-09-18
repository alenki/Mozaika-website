<?php
header('Content-Type: application/json; charset=utf-8');

// 🔐 Секретные данные — хранятся ТОЛЬКО на сервере
$botToken = 'YOUR_BOT_TOKEN_HERE';        // ← Замени на свой
$chatId = 'YOUR_CHAT_ID_HERE';            // ← Замени на свой
$secretKey = 'my_super_secret_key_123';   // ← Для HMAC (если нужно)

// Получаем данные
$parents = trim($_POST['Parents'] ?? '');
$phone = trim($_POST['Phone'] ?? '');
$message = trim($_POST['message'] ?? '');

// 🧹 Простейшая валидация
if (empty($parents) || empty($phone)) {
    echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
    exit;
}

// 📞 Проверка телефона (можно улучшить регуляркой)
if (!preg_match('/^\+?[0-9\s\-\(\)]{7,20}$/', $phone)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный номер телефона']);
    exit;
}

// 📝 Формируем сообщение
$text = "📞 Новая заявка:\n";
$text .= "Имя родителя: " . htmlspecialchars($parents) . "\n";
$text .= "Телефон: " . htmlspecialchars($phone) . "\n";
if (!empty($message)) {
    $text .= "Сообщение: " . htmlspecialchars($message) . "\n";
}

// 🔐 Генерируем HMAC подпись (опционально)
$hmac = hash_hmac('sha256', $text, $secretKey);

// 📤 Отправляем в Telegram
$url = "https://api.telegram.org/bot{$botToken}/sendMessage";
$data = [
    'chat_id' => $chatId,
    'text' => $text . "\n\n🔑 HMAC: " . $hmac,
    'parse_mode' => 'HTML'
];

$options = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === false) {
    echo json_encode(['success' => false, 'error' => 'Ошибка отправки в Telegram']);
    exit;
}

$response = json_decode($result, true);
if (!$response['ok']) {
    echo json_encode(['success' => false, 'error' => 'Telegram API error: ' . ($response['description'] ?? 'unknown')]);
    exit;
}

// ✅ Успех
echo json_encode(['success' => true]);
?>