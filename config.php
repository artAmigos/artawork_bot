<?php
// Настройки базы данных
define('DB_HOST', 'web2.maze-host.ru');
define('DB_NAME', 'artawork_bot');
define('DB_USER', 'artawork_bot');
define('DB_PASS', 'fX7uU8vU8p');


// Настройки бота
define('BOT_TOKEN', '8740343335:AAGGsosn86ivhdNqiYD5xJgrkbz5mQd7WtY');
define('BOT_USERNAME', 'artawork_bot');

// Админ
define('ADMIN_ID', 669733760);

// Курс рубля к евро
define('RUB_TO_EUR', 0.010);

// Настройки реферальной программы
define('REFERRAL_PERCENT', 25);

// Настройки бонусов за активность
define('BONUS_3_DAYS', 5);
define('BONUS_7_DAYS', 7);
define('BONUS_14_DAYS', 10);
define('BONUS_MAX', 10);

// Настройки вывода
define('MIN_WITHDRAW_RUB', 5000);
define('MIN_WITHDRAW_EUR', 50);

// Подключение к БД
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

// Функция для запросов к Telegram API
function botRequest($method, $data = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("CURL Error: " . $error);
    }
    
    return json_decode($result, true);
}

// Функция для отправки сообщения
function sendMessage($chat_id, $text, $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    return botRequest('sendMessage', $data);
}

// Функция для редактирования сообщения
function editMessage($chat_id, $message_id, $text, $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    return botRequest('editMessageText', $data);
}

// Функция для удаления сообщения
function deleteMessage($chat_id, $message_id) {
    return botRequest('deleteMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
}

// Функция для создания клавиатуры
function mainKeyboard() {
    return [
        'keyboard' => [
            [['text' => '💰 Баланс'], ['text' => '📋 Задания']],
            [['text' => '🎁 Бонус дня'], ['text' => '👥 Рефералы']],
            [['text' => '💳 Вывод'], ['text' => '📊 Мои выводы']],
            [['text' => '👤 Профиль'], ['text' => '❓ Помощь']]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];
}

// Функция для конвертации рублей в евро
function rubToEur($rub) {
    return round($rub * RUB_TO_EUR, 2);
}

// Функция для форматирования суммы
function formatRub($rub) {
    return number_format($rub, 0, '.', ' ') . ' ₽';
}

// Функция для расчёта бонуса за активность
function getActivityBonus($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $days = $stmt->fetchColumn();
    
    if ($days >= 14) return BONUS_14_DAYS;
    if ($days >= 7) return BONUS_7_DAYS;
    if ($days >= 3) return BONUS_3_DAYS;
    return 0;
}

// Функция для получения ранга пользователя
function getUserRank($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'task'");
    $stmt->execute([$user_id]);
    $total_earned = $stmt->fetchColumn() ?: 0;
    
    if ($total_earned >= 100000) return ['name' => '👑 Легенда', 'icon' => '👑'];
    if ($total_earned >= 50000) return ['name' => '💎 Мастер', 'icon' => '💎'];
    if ($total_earned >= 25000) return ['name' => '⭐ Профи', 'icon' => '⭐'];
    if ($total_earned >= 10000) return ['name' => '🚀 Продвинутый', 'icon' => '🚀'];
    if ($total_earned >= 5000) return ['name' => '📈 Активный', 'icon' => '📈'];
    return ['name' => '🌱 Новичок', 'icon' => '🌱'];
}

// Функция для регистрации пользователя
function registerUser($telegram_id, $username) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $ref_id = isset($_GET['ref']) ? (int)$_GET['ref'] : 0;
        
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, ref_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$telegram_id, $username, $ref_id]);
        $user_id = $pdo->lastInsertId();
        
        // Начисляем бонус за регистрацию
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + 50 WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, 50, 'bonus', 'Бонус за регистрацию', NOW())");
        $stmt->execute([$user_id]);
        
        return $user_id;
    }
    return $user['id'];
}

// Функция для проверки подписки на канал/группу (РЕАЛЬНАЯ ПРОВЕРКА)
function checkSubscription($user_id, $channel_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $telegram_id = $stmt->fetchColumn();
    
    if (!$telegram_id) return false;
    
    try {
        // Пытаемся получить информацию о членстве
        $result = botRequest('getChatMember', [
            'chat_id' => $channel_id,
            'user_id' => $telegram_id
        ]);
        
        // Если есть ошибка - бот не может проверить
        if (isset($result['error_code'])) {
            // Если бот не админ - возвращаем false (не можем проверить)
            if ($result['error_code'] == 400) {
                return false;
            }
            return false;
        }
        
        // Проверяем статус
        if (isset($result['result']['status'])) {
            $status = $result['result']['status'];
            // Подписан, если статус member, administrator, creator
            return in_array($status, ['member', 'administrator', 'creator']);
        }
        
        return false;
    } catch (Exception $e) {
        // В случае ошибки - не подписан
        return false;
    }
}

// Функция для проверки, может ли бот проверить подписку
// Функция для проверки, может ли бот проверить подписку
function canCheckSubscription($channel_id) {
    try {
        $result = botRequest('getChat', [
            'chat_id' => $channel_id
        ]);
        
        // Если есть ошибка - бот не может проверить
        if (isset($result['error_code'])) {
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Функция для проверки активной заявки на вывод
function hasActiveWithdraw($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdraws WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() > 0;
}

// Функция для получения выполненных заданий пользователя
function getUserCompletedTasks($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT task_id FROM user_tasks WHERE user_id = ? AND status IN ('completed', 'pending')");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Функция для проверки требований по дням
function checkUserDaysRequirement($user_id, $required_days) {
    global $pdo;
    if ($required_days <= 0) return true;
    
    $stmt = $pdo->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $days = $stmt->fetchColumn();
    
    return $days >= $required_days;
}
?>