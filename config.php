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

// Настройки ежедневного бонуса
define('DAILY_BONUS_AMOUNT', 50);

// Настройки бонусов за активность (стрик)
define('STREAK_BONUS_1', 10);
define('STREAK_BONUS_7', 100);
define('STREAK_BONUS_30', 500);

// Настройки вывода
define('MIN_WITHDRAW_RUB', 5000);
define('MIN_WITHDRAW_EUR', 50);
define('WITHDRAW_TARGET_USERS', 2000); // 2000 пользователей для открытия выводов

// Настройки дуэлей
define('DUEL_MIN_BET', 100);
define('DUEL_MAX_BET', 5000);
define('DUEL_DAILY_LIMIT', 3);
define('DUEL_ACTIVE_LIMIT', 2);
define('DUEL_DURATION', 24);
define('DUEL_WIN_POINTS', 50);
define('DUEL_LOSS_POINTS', -50);

// Настройки переводов (P2P)
define('TRANSFER_MIN_AMOUNT', 10);
define('TRANSFER_MAX_AMOUNT', 5000);
define('TRANSFER_DAILY_LIMIT', 10);
define('TRANSFER_DAILY_SUM_LIMIT', 10000);
define('TRANSFER_CONFIRM_TIME', 5);
define('TRANSFER_COOLDOWN', 3600);

// Настройки invite transfer
define('INVITE_TRANSFER_DAILY_LIMIT', 5);
define('INVITE_TRANSFER_EXPIRE', 24);
define('INVITE_TRANSFER_FEE', 0.02);

// Команда для рассылки (только для админа)
define('MAILING_COMMAND', '/mail');

// Настройки кейсов
define('CASES_KEYS_PER_TASK', 1);

// ============================================================
// ПОДКЛЮЧЕНИЕ К БД С ПЕРЕПОДКЛЮЧЕНИЕМ
// ============================================================
function getDBConnection() {
    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
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
            reconnectDB();
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

function sendMessage($chat_id, $text, $keyboard = null) {
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return botRequest('sendMessage', $data);
}

function editMessage($chat_id, $message_id, $text, $keyboard = null) {
    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return botRequest('editMessageText', $data);
}

function sendPhoto($chat_id, $photo, $caption = '', $keyboard = null) {
    $data = ['chat_id' => $chat_id, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return botRequest('sendPhoto', $data);
}

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

function rubToEur($rub) {
    return round($rub * RUB_TO_EUR, 2);
}

function formatRub($rub) {
    return number_format($rub, 0, '.', ' ') . ' ₽';
}

function getUserRank($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE ref_id = ?");
    $stmt->execute([$user_id]);
    $ref_count = $stmt->fetchColumn() ?: 0;
    
    if ($ref_count >= 100) return ['name' => '👑 Легенда', 'icon' => '👑', 'percent' => 40];
    if ($ref_count >= 50) return ['name' => '💎 VIP', 'icon' => '💎', 'percent' => 30];
    if ($ref_count >= 20) return ['name' => '⭐ Звёздный', 'icon' => '⭐', 'percent' => 25];
    if ($ref_count >= 5) return ['name' => '📈 Активный', 'icon' => '📈', 'percent' => 20];
    return ['name' => '🌱 Новичок', 'icon' => '🌱', 'percent' => 15];
}

function registerUser($telegram_id, $username) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, username, ref_id, created_at) VALUES (?, ?, 0, NOW())");
        $stmt->execute([$telegram_id, $username]);
        $user_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + 50 WHERE id = ?");
        $stmt->execute([$user_id]);
        
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, 50, 'bonus', 'Бонус за регистрацию', NOW())");
        $stmt->execute([$user_id]);
        
        return $user_id;
    }
    return $user['id'];
}

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

function canCheckSubscription($channel_id) {
    $result = botRequest('getChat', ['chat_id' => $channel_id]);
    return !isset($result['error_code']);
}

function hasActiveWithdraw($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdraws WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() > 0;
}

function getUserCompletedTasks($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT task_id FROM user_tasks WHERE user_id = ? AND status IN ('completed', 'pending')");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function checkUserDaysRequirement($user_id, $required_days) {
    global $pdo;
    if ($required_days <= 0) return true;
    
    $stmt = $pdo->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $days = $stmt->fetchColumn();
    return $days >= $required_days;
}

// ============================================================
// ГЛАВНАЯ ФУНКЦИЯ КВЕСТОВ
// ============================================================
function checkAndCompleteQuest($user_id, $quest_key) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, reward, name, is_monthly, requirement_days FROM quests WHERE `key` = ?");
        $stmt->execute([$quest_key]);
        $quest = $stmt->fetch();
        
        if (!$quest) {
            return false;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM user_quests WHERE user_id = ? AND quest_id = ? AND status = 'completed'");
        $stmt->execute([$user_id, $quest['id']]);
        if ($stmt->fetch()) {
            return false;
        }
        
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
        
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$quest['reward'], $user_id]);
        
        $desc = 'Квест: ' . $quest['name'];
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'quest', ?, NOW())");
        $stmt->execute([$user_id, $quest['reward'], $desc]);
        
        return true;
        
    } catch (PDOException $e) {
        error_log("checkAndCompleteQuest error: " . $e->getMessage());
        return false;
    }
}

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
?>