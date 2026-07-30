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
define('REFERRAL_PERCENT', 25); // Базовый, переопределяется статусом

// Настройки бонусов за активность (стрик)
define('STREAK_BONUS_1', 10);
define('STREAK_BONUS_7', 100);
define('STREAK_BONUS_30', 500);

// Настройки вывода
define('MIN_WITHDRAW_RUB', 5000);
define('MIN_WITHDRAW_EUR', 50);

// Настройки дуэлей
define('DUEL_MIN_BET', 100);
define('DUEL_MAX_BET', 5000);
define('DUEL_DAILY_LIMIT', 3);
define('DUEL_ACTIVE_LIMIT', 2);
define('DUEL_DURATION', 24); // часов
define('DUEL_WIN_POINTS', 50);
define('DUEL_LOSS_POINTS', -50);

// Настройки переводов (P2P)
define('TRANSFER_MIN_AMOUNT', 10);
define('TRANSFER_MAX_AMOUNT', 5000);
define('TRANSFER_DAILY_LIMIT', 10);
define('TRANSFER_DAILY_SUM_LIMIT', 10000);
define('TRANSFER_CONFIRM_TIME', 5); // минут
define('TRANSFER_COOLDOWN', 3600); // 1 час в секундах

// Настройки invite transfer (Переслать деньги)
define('INVITE_TRANSFER_DAILY_LIMIT', 5);
define('INVITE_TRANSFER_EXPIRE', 24); // часов
define('INVITE_TRANSFER_FEE', 0.02); // 2% комиссия

// Настройки кейсов
define('CASES_KEYS_PER_TASK', 1); // 1 ключ за задание

// ============================================================
// ПОДКЛЮЧЕНИЕ К БД С ПЕРЕПОДКЛЮЧЕНИЕМ
// ============================================================
function getDBConnection() {
    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        // Устанавливаем время ожидания для MySQL
        $pdo->query("SET SESSION wait_timeout = 28800");
        $pdo->query("SET SESSION interactive_timeout = 28800");
        return $pdo;
    } catch(PDOException $e) {
        die("Ошибка подключения: " . $e->getMessage());
    }
}

// Инициализация PDO
$pdo = getDBConnection();

// Функция для переподключения к БД при потере соединения
function reconnectDB() {
    global $pdo;
    try {
        $pdo->query("SELECT 1");
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() == 'HY000' || strpos($e->getMessage(), 'gone away') !== false) {
            // Переподключаемся
            $pdo = getDBConnection();
            return true;
        }
        return false;
    }
}

// Безопасный запрос к БД с переподключением
function safeQuery($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gone away') !== false || $e->getCode() == 'HY000') {
            // Переподключаемся
            reconnectDB();
            // Повторяем запрос
            global $pdo;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
        throw $e;
    }
}

// ============================================================
// ФУНКЦИЯ БЕЗ ОШИБОК - ВОЗВРАЩАЕТ ЛЮБОЙ РЕЗУЛЬТАТ
// ============================================================
function botRequest($method, $data = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        return ['ok' => false, 'error' => $error, 'result' => [], 'http_code' => $http_code];
    }
    
    if ($http_code != 200) {
        return ['ok' => false, 'error' => "HTTP $http_code", 'result' => [], 'http_code' => $http_code];
    }
    
    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON', 'result' => [], 'http_code' => $http_code];
    }
    
    return $decoded;
}

// Функция для отправки сообщения
function sendMessage($chat_id, $text, $keyboard = null) {
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return botRequest('sendMessage', $data);
}

// Функция для редактирования сообщения
function editMessage($chat_id, $message_id, $text, $keyboard = null) {
    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return botRequest('editMessageText', $data);
}

// Функция для отправки фото
function sendPhoto($chat_id, $photo, $caption = '', $keyboard = null) {
    $data = ['chat_id' => $chat_id, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return botRequest('sendPhoto', $data);
}

// Функция для создания главной клавиатуры (обновлена)
function mainKeyboard() {
    return [
        'keyboard' => [
            [['text' => '💰 Баланс'], ['text' => '📋 Задания']],
            [['text' => '🎁 Бонус дня'], ['text' => '👥 Рефералы']],
            [['text' => '💳 Вывод'], ['text' => '💸 Перевод']],
            [['text' => '👤 Профиль'], ['text' => '❓ Помощь']],
            [['text' => '🏖️ Отпуск'], ['text' => '🏆 Дуэли']],
            [['text' => '🔥 Стрик'], ['text' => '🎯 Квесты']],
            [['text' => '🏆 Топ'], ['text' => '🎲 Кейсы']]
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

// Функция для получения ранга пользователя (обновлена для Рефералов 2.0)
function getUserRank($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE ref_id = ?");
    $stmt->execute([$user_id]);
    $ref_count = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->prepare("SELECT SUM(income) as total FROM referrals WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_income = $stmt->fetchColumn() ?: 0;
    
    // Ранг по рефералам
    if ($ref_count >= 100) return ['name' => '👑 Легенда', 'icon' => '👑', 'percent' => 40];
    if ($ref_count >= 50) return ['name' => '💎 VIP', 'icon' => '💎', 'percent' => 30];
    if ($ref_count >= 20) return ['name' => '⭐ Звёздный', 'icon' => '⭐', 'percent' => 25];
    if ($ref_count >= 5) return ['name' => '📈 Активный', 'icon' => '📈', 'percent' => 20];
    return ['name' => '🌱 Новичок', 'icon' => '🌱', 'percent' => 15];
}

// Функция для регистрации пользователя (обновлена)
function registerUser($telegram_id, $username) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $ref_id = 0;
        // Реферал обрабатывается в processUpdate
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, ref_id, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$telegram_id, $username, $ref_id]);
        $user_id = $pdo->lastInsertId();
        
        // Бонус за регистрацию
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + 50 WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, 50, 'bonus', 'Бонус за регистрацию', NOW())");
        $stmt->execute([$user_id]);
        
        return $user_id;
    }
    return $user['id'];
}

// Функция для проверки подписки
function checkSubscription($user_id, $channel_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $telegram_id = $stmt->fetchColumn();
    if (!$telegram_id) return false;
    
    $result = botRequest('getChatMember', ['chat_id' => $channel_id, 'user_id' => $telegram_id]);
    
    if (isset($result['result']['status'])) {
        return in_array($result['result']['status'], ['member', 'administrator', 'creator']);
    }
    return false;
}

// Функция для проверки, может ли бот проверить подписку
function canCheckSubscription($channel_id) {
    $result = botRequest('getChat', ['chat_id' => $channel_id]);
    return !isset($result['error_code']);
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

// Функция для проверки и выполнения квестов (ИСПРАВЛЕНА с try-catch)
function checkAndCompleteQuest($user_id, $quest_key) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT q.id, q.reward, q.name, q.is_monthly, q.requirement_days FROM quests q 
                               LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.user_id = ? AND uq.status = 'completed'
                               WHERE q.`key` = ? AND uq.id IS NULL");
        $stmt->execute([$user_id, $quest_key]);
        $quest = $stmt->fetch();
        
        if ($quest) {
            // Проверяем условие для месячных квестов
            if ($quest['is_monthly'] == 1) {
                $stmt = $pdo->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $days = $stmt->fetchColumn();
                if ($days < $quest['requirement_days']) {
                    return false;
                }
            }
            
            $stmt = $pdo->prepare("INSERT INTO user_quests (user_id, quest_id, status, completed_at) VALUES (?, ?, 'completed', NOW())");
            $stmt->execute([$user_id, $quest['id']]);
            
            // Начисляем награду
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$quest['reward'], $user_id]);
            
            $desc = 'Квест: ' . $quest['name'];
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'quest', ?, NOW())");
            $stmt->execute([$user_id, $quest['reward'], $desc]);
            
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log("checkAndCompleteQuest error: " . $e->getMessage());
        return false;
    }
}

// Функция для получения реферального процента по статусу
function getReferralPercent($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE ref_id = ?");
    $stmt->execute([$user_id]);
    $ref_count = $stmt->fetchColumn() ?: 0;
    
    if ($ref_count >= 100) return 40;
    if ($ref_count >= 50) return 30;
    if ($ref_count >= 20) return 25;
    if ($ref_count >= 5) return 20;
    return 15;
}