<?php
require_once 'config.php';

// Простая авторизация
session_start();
if (!isset($_SESSION['admin_logged'])) {
    if (isset($_POST['password']) && $_POST['password'] == 'admin123') {
        $_SESSION['admin_logged'] = true;
    } else {
        echo '
        <!DOCTYPE html>
        <html>
        <head>
            <title>ArtaWork Admin</title>
            <style>
                body { font-family: Arial; max-width: 400px; margin: 100px auto; padding: 20px; background: #f0f2f5; }
                .login-box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                input, button { padding: 10px; width: 100%; margin: 5px 0; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
                button { background: #2c3e50; color: white; cursor: pointer; font-size: 16px; }
                button:hover { background: #34495e; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>🔐 Вход в админ-панель</h2>
                <form method="POST">
                    <input type="password" name="password" placeholder="Пароль" required>
                    <button type="submit">Войти</button>
                </form>
            </div>
        </body>
        </html>
        ';
        exit;
    }
}

// Выход
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ============================================
// ОБРАБОТКА ДЕЙСТВИЙ
// ============================================
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // === УПРАВЛЕНИЕ ЗАДАНИЯМИ ===
    if ($action == 'add_task' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $stmt = $pdo->prepare("INSERT INTO tasks (title, description, reward, limit_count, status, created_at, type, channel_id, requirement_days) VALUES (?, ?, ?, ?, 'active', NOW(), ?, ?, ?)");
        $stmt->execute([
            $_POST['title'],
            $_POST['description'],
            $_POST['reward'],
            $_POST['limit_count'] ?? 0,
            $_POST['type'] ?? 'telegram',
            $_POST['channel_id'] ?? null,
            $_POST['requirement_days'] ?? 0
        ]);
        header('Location: admin.php?msg=Задание создано!');
        exit;
    }
    
    if ($action == 'delete_task' && isset($_GET['id'])) {
        $task_id = $_GET['id'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM user_tasks WHERE task_id = ?");
            $stmt->execute([$task_id]);
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->execute([$task_id]);
            $pdo->commit();
            header('Location: admin.php?msg=Задание удалено!');
        } catch (PDOException $e) {
            $pdo->rollBack();
            header('Location: admin.php?msg-error=Ошибка: ' . $e->getMessage());
        }
        exit;
    }
    
    if ($action == 'toggle_task' && isset($_GET['id'])) {
        $task_id = $_GET['id'];
        $stmt = $pdo->prepare("SELECT status FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $current = $stmt->fetchColumn();
        $new_status = ($current == 'active') ? 'paused' : 'active';
        $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $task_id]);
        header('Location: admin.php?msg=Статус задания изменен!');
        exit;
    }
    
    // === РУЧНОЕ ПОДТВЕРЖДЕНИЕ ЗАДАНИЯ ===
    if ($action == 'approve_task' && isset($_GET['id'])) {
        $user_task_id = $_GET['id'];
        $stmt = $pdo->prepare("SELECT ut.*, t.reward, t.title, u.id as user_id FROM user_tasks ut JOIN tasks t ON ut.task_id = t.id JOIN users u ON ut.user_id = u.id WHERE ut.id = ?");
        $stmt->execute([$user_task_id]);
        $data = $stmt->fetch();
        
        if ($data) {
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$data['reward'], $data['user_id']]);
            $stmt = $pdo->prepare("UPDATE user_tasks SET status = 'completed' WHERE id = ?");
            $stmt->execute([$user_task_id]);
            
            $desc = 'Выполнение задания (ручное подтверждение): ' . $data['title'];
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'task', ?, NOW())");
            $stmt->execute([$data['user_id'], $data['reward'], $desc]);
            
            $stmt = $pdo->prepare("UPDATE users SET cases_keys = cases_keys + ? WHERE id = ?");
            $stmt->execute([CASES_KEYS_PER_TASK, $data['user_id']]);
            
            $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $ref_id = $stmt->fetchColumn();
            
            if ($ref_id > 0) {
                $ref_percent = getReferralPercent($ref_id);
                $ref_bonus = $data['reward'] * ($ref_percent / 100);
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$ref_bonus, $ref_id]);
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'ref_bonus', 'Бонус за реферала (" . $ref_percent . "%)', NOW())");
                $stmt->execute([$ref_id, $ref_bonus]);
                $stmt = $pdo->prepare("INSERT INTO referrals (user_id, ref_user_id, income, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$ref_id, $data['user_id'], $ref_bonus]);
            }
            
            $stmt = $pdo->prepare("SELECT telegram_id, username FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                $text = "✅ <b>Задание подтверждено администратором!</b>\n\n📌 Задание: " . $data['title'] . "\n💰 Начислено: <b>" . formatRub($data['reward']) . "</b>\n🔑 Получен ключ для кейса!";
                sendMessage($user['telegram_id'], $text, mainKeyboard());
            }
            header('Location: admin.php?msg=Задание подтверждено! Начислено ' . formatRub($data['reward']));
        } else {
            header('Location: admin.php?msg-error=Ошибка подтверждения!');
        }
        exit;
    }
    
    if ($action == 'reject_task' && isset($_GET['id'])) {
        $user_task_id = $_GET['id'];
        $stmt = $pdo->prepare("SELECT ut.*, t.title, u.id as user_id, u.telegram_id FROM user_tasks ut JOIN tasks t ON ut.task_id = t.id JOIN users u ON ut.user_id = u.id WHERE ut.id = ?");
        $stmt->execute([$user_task_id]);
        $data = $stmt->fetch();
        $stmt = $pdo->prepare("UPDATE user_tasks SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$user_task_id]);
        if ($data && $data['telegram_id']) {
            $text = "❌ <b>Задание отклонено администратором!</b>\n\n📌 Задание: " . $data['title'] . "\n💬 Попробуй выполнить задание ещё раз.";
            sendMessage($data['telegram_id'], $text, mainKeyboard());
        }
        header('Location: admin.php?msg=Задание отклонено!');
        exit;
    }
    
    // === ВЫВОД СРЕДСТВ ===
    if ($action == 'withdraw' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("UPDATE withdraws SET status = 'approved' WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $stmt = $pdo->prepare("SELECT w.*, u.telegram_id, u.username FROM withdraws w JOIN users u ON w.user_id = u.id WHERE w.id = ?");
        $stmt->execute([$_GET['id']]);
        $data = $stmt->fetch();
        if ($data && $data['telegram_id']) {
            $text = "✅ <b>Ваша заявка на вывод одобрена!</b>\n\n💰 Сумма: <b>" . formatRub($data['amount']) . "</b>\n📋 Заявка #" . $data['id'];
            sendMessage($data['telegram_id'], $text, mainKeyboard());
        }
        header('Location: admin.php?msg=Выплата подтверждена!');
        exit;
    }
    
    if ($action == 'reject_withdraw' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("UPDATE withdraws SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $stmt = $pdo->prepare("SELECT user_id, amount, telegram_id, username FROM withdraws w JOIN users u ON w.user_id = u.id WHERE w.id = ?");
        $stmt->execute([$_GET['id']]);
        $data = $stmt->fetch();
        if ($data) {
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$data['amount'], $data['user_id']]);
            if ($data['telegram_id']) {
                $text = "❌ <b>Ваша заявка на вывод отклонена!</b>\n\n💰 Сумма возвращена на баланс.\n📝 Свяжитесь с поддержкой.";
                sendMessage($data['telegram_id'], $text, mainKeyboard());
            }
        }
        header('Location: admin.php?msg=Выплата отклонена!');
        exit;
    }
    
    // === РАССЫЛКА СООБЩЕНИЙ ===
    if ($action == 'send_mass_message' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $message_text = $_POST['message_text'] ?? '';
        $message_type = $_POST['message_type'] ?? 'all';
        
        if (empty($message_text)) {
            header('Location: admin.php?tab=mailing&msg-error=Введите текст сообщения!');
            exit;
        }
        
        if ($message_type == 'all') {
            $stmt = $pdo->query("SELECT telegram_id FROM users");
        } else {
            $stmt = $pdo->prepare("SELECT telegram_id FROM users WHERE id IN (SELECT user_id FROM user_tasks WHERE status = 'completed' GROUP BY user_id HAVING COUNT(*) >= ?)");
            $stmt->execute([($message_type == 'active' ? 5 : 1)]);
        }
        
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $sent = 0;
        $failed = 0;
        
        foreach ($users as $telegram_id) {
            $result = sendMessage($telegram_id, $message_text, mainKeyboard());
            if (isset($result['ok']) && $result['ok'] === true) {
                $sent++;
            } else {
                $failed++;
            }
            usleep(50000);
        }
        
        header('Location: admin.php?tab=mailing&msg=Рассылка завершена! Отправлено: ' . $sent . ', Ошибок: ' . $failed);
        exit;
    }
    
    // === УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ ===
    if ($action == 'edit_user' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $user_id = $_GET['id'];
        $new_balance = $_POST['balance'] ?? null;
        $new_ref_id = $_POST['ref_id'] ?? null;
        $reset_streak = isset($_POST['reset_streak']) ? 1 : 0;
        $reset_vacation = isset($_POST['reset_vacation']) ? 1 : 0;
        $action_type = $_POST['action_type'] ?? 'edit';
        
        if ($action_type == 'add_balance') {
            $amount = floatval($_POST['add_amount'] ?? 0);
            if ($amount > 0) {
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $user_id]);
                $desc = 'Администратор начислил ' . formatRub($amount);
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'admin_bonus', ?, NOW())");
                $stmt->execute([$user_id, $amount, $desc]);
                header('Location: admin.php?tab=users&msg=Начислено ' . formatRub($amount));
                exit;
            }
        }
        
        if ($action_type == 'remove_balance') {
            $amount = floatval($_POST['remove_amount'] ?? 0);
            if ($amount > 0) {
                $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $user_id]);
                $desc = 'Администратор списал ' . formatRub($amount);
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'admin_penalty', ?, NOW())");
                $stmt->execute([$user_id, -$amount, $desc]);
                header('Location: admin.php?tab=users&msg=Списано ' . formatRub($amount));
                exit;
            }
        }
        
        $updates = [];
        if ($new_balance !== null) $updates[] = "balance = " . floatval($new_balance);
        if ($new_ref_id !== null) $updates[] = "ref_id = " . intval($new_ref_id);
        if ($reset_streak) $updates[] = "daily_streak = 0, last_streak_date = NULL";
        if ($reset_vacation) $updates[] = "vacation_used_at = NULL";
        
        if (!empty($updates)) {
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            header('Location: admin.php?tab=users&msg=Пользователь обновлен!');
        } else {
            header('Location: admin.php?tab=users&msg-error=Нет изменений!');
        }
        exit;
    }
    
    if ($action == 'delete_user' && isset($_GET['id'])) {
        $user_id = $_GET['id'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM daily_bonuses WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM referrals WHERE user_id = ? OR ref_user_id = ?");
            $stmt->execute([$user_id, $user_id]);
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM user_quests WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM user_tasks WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM withdraws WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $pdo->commit();
            header('Location: admin.php?tab=users&msg=Пользователь удален!');
        } catch (PDOException $e) {
            $pdo->rollBack();
            header('Location: admin.php?tab=users&msg-error=Ошибка: ' . $e->getMessage());
        }
        exit;
    }
    
    // === СТАТИСТИКА ===
    if ($action == 'clear_logs' && isset($_GET['id'])) {
        $table = $_GET['id'];
        if ($table == 'transactions' || $table == 'user_tasks') {
            $stmt = $pdo->prepare("TRUNCATE TABLE $table");
            $stmt->execute();
            header('Location: admin.php?tab=stats&msg=Таблица ' . $table . ' очищена!');
        } else {
            header('Location: admin.php?tab=stats&msg-error=Нельзя очистить эту таблицу!');
        }
        exit;
    }
    
    // === УПРАВЛЕНИЕ КВЕСТАМИ ===
    if ($action == 'add_quest' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $stmt = $pdo->prepare("INSERT INTO quests (`key`, name, description, reward, is_monthly, requirement_days) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['key'],
            $_POST['name'],
            $_POST['description'],
            $_POST['reward'],
            $_POST['is_monthly'] ?? 0,
            $_POST['requirement_days'] ?? 0
        ]);
        header('Location: admin.php?tab=quests&msg=Квест добавлен!');
        exit;
    }
    
    if ($action == 'delete_quest' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM quests WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header('Location: admin.php?tab=quests&msg=Квест удален!');
        exit;
    }
    
    // === УПРАВЛЕНИЕ ДУЭЛЯМИ ===
    if ($action == 'finish_duel' && isset($_GET['id'])) {
        $duel_id = $_GET['id'];
        $winner_id = isset($_GET['winner']) ? intval($_GET['winner']) : 0;
        
        $stmt = $pdo->prepare("SELECT * FROM duels WHERE id = ? AND status = 'active'");
        $stmt->execute([$duel_id]);
        $duel = $stmt->fetch();
        
        if ($duel) {
            $stmt = $pdo->prepare("UPDATE duels SET status = 'finished', winner_id = ?, finished_at = NOW() WHERE id = ?");
            $stmt->execute([$winner_id, $duel_id]);
            
            if ($winner_id > 0) {
                // Начисляем выигрыш победителю (ставка * 2)
                $win_amount = $duel['bet'] * 2;
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ?, duel_wins = duel_wins + 1 WHERE id = ?");
                $stmt->execute([$win_amount, $winner_id]);
                
                // Проигравший получает -50 рейтинга
                $loser_id = ($duel['user1_id'] == $winner_id) ? $duel['user2_id'] : $duel['user1_id'];
                if ($loser_id) {
                    $stmt = $pdo->prepare("UPDATE users SET duel_losses = duel_losses + 1 WHERE id = ?");
                    $stmt->execute([$loser_id]);
                }
                
                // Уведомляем участников
                $stmt = $pdo->prepare("SELECT username, telegram_id FROM users WHERE id = ?");
                $stmt->execute([$winner_id]);
                $winner = $stmt->fetch();
                
                $stmt = $pdo->prepare("SELECT username, telegram_id FROM users WHERE id = ?");
                $stmt->execute([$loser_id]);
                $loser = $stmt->fetch();
                
                if ($winner) {
                    sendMessage($winner['telegram_id'], "🏆 <b>Вы победили в дуэли!</b>\n\n💰 Выигрыш: " . formatRub($win_amount) . "\n📈 Рейтинг: +50", mainKeyboard());
                }
                if ($loser) {
                    sendMessage($loser['telegram_id'], "❌ <b>Вы проиграли в дуэли!</b>\n\n📈 Рейтинг: -50", mainKeyboard());
                }
            }
            header('Location: admin.php?tab=duels&msg=Дуэль завершена!');
        } else {
            header('Location: admin.php?tab=duels&msg-error=Дуэль не найдена!');
        }
        exit;
    }
    
    if ($action == 'delete_duel' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM duels WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header('Location: admin.php?tab=duels&msg=Дуэль удалена!');
        exit;
    }
}

// ============================================
// ДОБАВЛЕНИЕ КОЛОНОК (если отсутствуют)
// ============================================
try {
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_withdraw_method VARCHAR(50) DEFAULT NULL");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS withdraw_waiting_text VARCHAR(10) DEFAULT NULL");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cases_keys INT DEFAULT 0");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS duel_wins INT DEFAULT 0");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS duel_losses INT DEFAULT 0");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS daily_streak INT DEFAULT 0");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_streak_date DATE NULL");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS vacation_used_at DATE NULL");
    $pdo->query("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS requirement_days INT DEFAULT 0");
} catch (PDOException $e) {}

// ============================================
// ПОЛУЧЕНИЕ ДАННЫХ
// ============================================
$users_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$tasks_count = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'active'")->fetchColumn();
$pending_tasks = $pdo->query("SELECT ut.*, u.username, u.telegram_id, t.title, t.reward FROM user_tasks ut JOIN users u ON ut.user_id = u.id JOIN tasks t ON ut.task_id = t.id WHERE ut.status = 'pending' ORDER BY ut.completed_at DESC")->fetchAll();
$withdraws = $pdo->query("SELECT w.*, u.username FROM withdraws w JOIN users u ON w.user_id = u.id WHERE w.status = 'pending' ORDER BY w.created_at DESC")->fetchAll();
$all_tasks = $pdo->query("SELECT * FROM tasks ORDER BY created_at DESC")->fetchAll();
$all_withdraws = $pdo->query("SELECT w.*, u.username FROM withdraws w JOIN users u ON w.user_id = u.id ORDER BY w.created_at DESC LIMIT 50")->fetchAll();
$completed_tasks = $pdo->query("SELECT COUNT(*) FROM user_tasks WHERE status = 'completed'")->fetchColumn();
$total_withdraws = $pdo->query("SELECT SUM(amount) FROM withdraws WHERE status = 'approved'")->fetchColumn() ?: 0;
$quests = $pdo->query("SELECT * FROM quests ORDER BY is_monthly, id")->fetchAll();
$active_duels = $pdo->query("SELECT d.*, u1.username as user1_name, u2.username as user2_name FROM duels d LEFT JOIN users u1 ON d.user1_id = u1.id LEFT JOIN users u2 ON d.user2_id = u2.id WHERE d.status IN ('waiting', 'active') ORDER BY d.started_at DESC")->fetchAll();

// Текущая вкладка
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'tasks';
$tab = in_array($tab, ['tasks', 'pending', 'withdraws', 'users', 'mailing', 'quests', 'duels', 'stats']) ? $tab : 'tasks';
?>
<!DOCTYPE html>
<html>
<head>
    <title>ArtaWork Admin Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #2c3e50, #34495e); color: white; padding: 20px 30px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; }
        .header .logout { color: #ecf0f1; text-decoration: none; padding: 8px 20px; background: #e74c3c; border-radius: 6px; }
        .header .logout:hover { background: #c0392b; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; padding: 18px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat h3 { color: #7f8c8d; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat .number { font-size: 28px; font-weight: bold; color: #2c3e50; margin-top: 5px; }
        .stat .number.green { color: #27ae60; }
        .stat .number.orange { color: #f39c12; }
        .stat .number.red { color: #e74c3c; }
        .stat .number.blue { color: #3498db; }
        .stat .number.purple { color: #9b59b6; }
        .card { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .card h2 { margin-bottom: 15px; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #f8f9fa; color: #2c3e50; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        .btn { display: inline-block; padding: 6px 16px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; font-size: 13px; transition: all 0.2s; }
        .btn:hover { opacity: 0.85; transform: translateY(-1px); }
        .btn-success { background: #27ae60; }
        .btn-danger { background: #e74c3c; }
        .btn-warning { background: #f39c12; }
        .btn-info { background: #3498db; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        input, textarea, select { width: 100%; padding: 10px 12px; margin: 5px 0 15px 0; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; transition: border 0.2s; }
        input:focus, textarea:focus, select:focus { border-color: #3498db; outline: none; }
        .form-group { margin-bottom: 15px; }
        .form-group label { font-weight: 600; color: #2c3e50; display: block; margin-bottom: 3px; font-size: 14px; }
        .msg { background: #27ae60; color: white; padding: 12px 18px; border-radius: 6px; margin-bottom: 15px; }
        .msg-error { background: #e74c3c; color: white; padding: 12px 18px; border-radius: 6px; margin-bottom: 15px; }
        .badge { padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .badge-pending { background: #f39c12; color: white; }
        .badge-completed { background: #27ae60; color: white; }
        .badge-rejected { background: #e74c3c; color: white; }
        .badge-active { background: #27ae60; color: white; }
        .badge-paused { background: #f39c12; color: white; }
        .badge-approved { background: #27ae60; color: white; }
        .badge-waiting { background: #f39c12; color: white; }
        .badge-finished { background: #27ae60; color: white; }
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { padding: 10px 20px; background: #ecf0f1; border-radius: 6px; cursor: pointer; font-weight: 500; transition: all 0.2s; }
        .tab:hover { background: #d5dbdb; }
        .tab.active { background: #2c3e50; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .method-crypto { color: #27ae60; font-weight: 600; }
        .method-bank { color: #3498db; font-weight: 600; }
        small { color: #7f8c8d; font-size: 12px; display: block; margin-top: 3px; }
        .rub { color: #2c3e50; font-weight: bold; }
        .eur { color: #95a5a6; font-size: 12px; }
        .channel-link { color: #3498db; font-size: 12px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .form-row, .form-row-3 { grid-template-columns: 1fr; } }
        .requirement-days { color: #e67e22; font-size: 12px; }
        .task-pending { background: #fff3cd; }
        .task-pending td { border-bottom: 1px solid #ffc107; }
        .user-edit-form { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px; }
        .inline-form { display: inline-block; margin: 0 5px; }
        .inline-form input[type="number"] { width: 80px; padding: 4px 8px; margin: 0; }
        .text-muted { color: #7f8c8d; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>🔧 ArtaWork Admin Panel</h1>
            <p style="opacity: 0.7; font-size: 13px;">Управление ботом</p>
        </div>
        <a href="?logout" class="logout">🚪 Выйти</a>
    </div>
    
    <?php if (isset($_GET['msg'])): ?>
    <div class="msg">✅ <?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['msg-error'])): ?>
    <div class="msg-error">❌ <?= htmlspecialchars($_GET['msg-error']) ?></div>
    <?php endif; ?>
    
    <div class="stats">
        <div class="stat"><h3>👤 Пользователей</h3><div class="number blue"><?= $users_count ?></div></div>
        <div class="stat"><h3>📋 Активных заданий</h3><div class="number green"><?= $tasks_count ?></div></div>
        <div class="stat"><h3>⏳ На проверке</h3><div class="number orange"><?= count($pending_tasks) ?></div></div>
        <div class="stat"><h3>✅ Выполнено</h3><div class="number green"><?= $completed_tasks ?></div></div>
        <div class="stat"><h3>💳 Заявок на вывод</h3><div class="number orange"><?= count($withdraws) ?></div></div>
        <div class="stat"><h3>💰 Выведено</h3><div class="number green"><?= formatRub($total_withdraws) ?></div></div>
        <div class="stat"><h3>⚔️ Активных дуэлей</h3><div class="number purple"><?= count(array_filter($active_duels, function($d) { return $d['status'] == 'active'; })) ?></div></div>
    </div>
    
    <div class="tabs">
        <div class="tab <?= $tab == 'tasks' ? 'active' : '' ?>" onclick="showTab('tasks')">📋 Задания</div>
        <div class="tab <?= $tab == 'pending' ? 'active' : '' ?>" onclick="showTab('pending')">⏳ Проверка <span class="badge badge-pending" style="font-size: 10px;"><?= count($pending_tasks) ?></span></div>
        <div class="tab <?= $tab == 'withdraws' ? 'active' : '' ?>" onclick="showTab('withdraws')">💳 Выводы</div>
        <div class="tab <?= $tab == 'users' ? 'active' : '' ?>" onclick="showTab('users')">👤 Пользователи</div>
        <div class="tab <?= $tab == 'mailing' ? 'active' : '' ?>" onclick="showTab('mailing')">📨 Рассылка</div>
        <div class="tab <?= $tab == 'quests' ? 'active' : '' ?>" onclick="showTab('quests')">🎯 Квесты</div>
        <div class="tab <?= $tab == 'duels' ? 'active' : '' ?>" onclick="showTab('duels')">⚔️ Дуэли</div>
        <div class="tab <?= $tab == 'stats' ? 'active' : '' ?>" onclick="showTab('stats')">📊 Статистика</div>
    </div>
    
    <!-- ============ ВКЛАДКА: ЗАДАНИЯ ============ -->
    <div id="tab-tasks" class="tab-content <?= $tab == 'tasks' ? 'active' : '' ?>">
        <div class="card">
            <h2>➕ Создать задание</h2>
            <form method="POST" action="?action=add_task">
                <div class="form-row">
                    <div class="form-group">
                        <label>Название</label>
                        <input type="text" name="title" required placeholder="Например: Подпишись на канал">
                    </div>
                    <div class="form-group">
                        <label>Награда (₽)</label>
                        <input type="number" step="1" name="reward" required placeholder="100">
                    </div>
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" rows="3" required placeholder="Подробное описание задания..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Лимит (0 = безлимит)</label>
                        <input type="number" name="limit_count" value="0">
                    </div>
                    <div class="form-group">
                        <label>Тип задания</label>
                        <select name="type">
                            <option value="telegram">Telegram (подписка)</option>
                            <option value="other">Другое</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>ID канала/группы</label>
                        <input type="text" name="channel_id" placeholder="@channel или -100123456789">
                        <small>Оставь пустым, если не требуется подписка</small>
                    </div>
                    <div class="form-group">
                        <label>Требование (дней в проекте)</label>
                        <input type="number" name="requirement_days" value="3" placeholder="3">
                    </div>
                </div>
                <button type="submit" class="btn">✅ Создать задание</button>
            </form>
        </div>
        
        <div class="card">
            <h2>📋 Все задания</h2>
            <?php if (count($all_tasks) == 0): ?>
                <p style="color: #999;">Заданий пока нет</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>ID</th><th>Название</th><th>Награда</th><th>Выполнено</th><th>Требование</th><th>Канал</th><th>Статус</th><th>Действие</th></tr></thead>
                <tbody>
                    <?php foreach ($all_tasks as $task): ?>
                    <tr>
                        <td>#<?= $task['id'] ?></td>
                        <td><?= htmlspecialchars($task['title']) ?></td>
                        <td><span class="rub"><?= formatRub($task['reward']) ?></span></td>
                        <td><?= $task['completed_count'] ?>/<?= $task['limit_count'] ?: '∞' ?></td>
                        <td><?= $task['requirement_days'] > 0 ? $task['requirement_days'] . ' дней' : '—' ?></td>
                        <td><?= !empty($task['channel_id']) ? htmlspecialchars($task['channel_id']) : '—' ?></td>
                        <td><span class="badge badge-<?= $task['status'] ?>"><?= $task['status'] == 'active' ? 'Активно' : ($task['status'] == 'paused' ? 'Пауза' : 'Завершено') ?></span></td>
                        <td>
                            <a href="?action=toggle_task&id=<?= $task['id'] ?>" class="btn btn-sm btn-warning"><?= $task['status'] == 'active' ? '⏸' : '▶️' ?></a>
                            <a href="?action=delete_task&id=<?= $task['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Удалить задание?')">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ============ ВКЛАДКА: ПРОВЕРКА ============ -->
    <div id="tab-pending" class="tab-content <?= $tab == 'pending' ? 'active' : '' ?>">
        <div class="card">
            <h2>⏳ Задания на ручной проверке</h2>
            <?php if (count($pending_tasks) == 0): ?>
                <p style="color: #999;">✅ Нет заданий на ручной проверке</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>Пользователь</th><th>Задание</th><th>Награда</th><th>Дата</th><th>Канал</th><th>Действие</th></tr></thead>
                <tbody>
                    <?php foreach ($pending_tasks as $pt): ?>
                    <tr class="task-pending">
                        <td>@<?= htmlspecialchars($pt['username']) ?></td>
                        <td><?= htmlspecialchars($pt['title']) ?></td>
                        <td><span class="rub"><?= formatRub($pt['reward']) ?></span></td>
                        <td><?= date('d.m.Y H:i', strtotime($pt['completed_at'])) ?></td>
                        <td>
                            <?php
                            $stmt2 = $pdo->prepare("SELECT channel_id FROM tasks WHERE id = ?");
                            $stmt2->execute([$pt['task_id']]);
                            $channel_id = $stmt2->fetchColumn();
                            echo $channel_id ? htmlspecialchars($channel_id) : '—';
                            ?>
                        </td>
                        <td>
                            <a href="?action=approve_task&id=<?= $pt['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Подтвердить?')">✅</a>
                            <a href="?action=reject_task&id=<?= $pt['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Отклонить?')">❌</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ============ ВКЛАДКА: ВЫВОДЫ ============ -->
    <div id="tab-withdraws" class="tab-content <?= $tab == 'withdraws' ? 'active' : '' ?>">
        <div class="card">
            <h2>💳 Заявки на вывод</h2>
            <?php if (count($withdraws) == 0): ?>
                <p style="color: #999;">Нет заявок на вывод</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>ID</th><th>Пользователь</th><th>Сумма</th><th>Способ</th><th>Данные</th><th>Дата</th><th>Действие</th></tr></thead>
                <tbody>
                    <?php foreach ($withdraws as $w): ?>
                    <tr>
                        <td>#<?= $w['id'] ?></td>
                        <td>@<?= htmlspecialchars($w['username']) ?></td>
                        <td><span class="rub"><?= formatRub($w['amount']) ?></span></td>
                        <td><?= $w['method'] == 'crypto' ? '💎 Крипто' : '🏦 Банк' ?></td>
                        <td><small><?= htmlspecialchars(substr($w['details'], 0, 30)) ?>...</small></td>
                        <td><?= date('d.m.Y H:i', strtotime($w['created_at'])) ?></td>
                        <td>
                            <a href="?action=withdraw&id=<?= $w['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Подтвердить выплату?')">✅</a>
                            <a href="?action=reject_withdraw&id=<?= $w['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Отклонить?')">❌</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>📜 История заявок</h2>
            <?php if (count($all_withdraws) == 0): ?>
                <p style="color: #999;">История пуста</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>ID</th><th>Пользователь</th><th>Сумма</th><th>Способ</th><th>Статус</th><th>Дата</th></tr></thead>
                <tbody>
                    <?php foreach ($all_withdraws as $w): ?>
                    <tr>
                        <td>#<?= $w['id'] ?></td>
                        <td>@<?= htmlspecialchars($w['username']) ?></td>
                        <td><span class="rub"><?= formatRub($w['amount']) ?></span></td>
                        <td><?= $w['method'] == 'crypto' ? '💎 Крипто' : '🏦 Банк' ?></td>
                        <td><span class="badge badge-<?= $w['status'] ?>"><?= $w['status'] == 'pending' ? '⏳ Ожидает' : ($w['status'] == 'approved' ? '✅ Выплачено' : '❌ Отклонено') ?></span></td>
                        <td><?= date('d.m.Y H:i', strtotime($w['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
       <!-- ============ ВКЛАДКА: ПОЛЬЗОВАТЕЛИ ============ -->
    <div id="tab-users" class="tab-content <?= $tab == 'users' ? 'active' : '' ?>">
        <div class="card">
            <h2>👤 Управление пользователями</h2>
            
            <!-- Фильтры -->
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                <form method="GET" action="admin.php" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                    <input type="hidden" name="tab" value="users">
                    <div class="form-group" style="margin:0; flex:1; min-width:200px;">
                        <label style="font-size:13px;">🔍 Поиск по username</label>
                        <input type="text" name="search_username" placeholder="Введите username..." value="<?= htmlspecialchars($_GET['search_username'] ?? '') ?>" style="padding:8px 12px; border:1px solid #ddd; border-radius:5px; width:100%;">
                    </div>
                    <div class="form-group" style="margin:0; min-width:150px;">
                        <label style="font-size:13px;">🏖️ Фильтр по отпуску</label>
                        <select name="vacation_filter" style="padding:8px 12px; border:1px solid #ddd; border-radius:5px; width:100%;">
                            <option value="all" <?= ($_GET['vacation_filter'] ?? 'all') == 'all' ? 'selected' : '' ?>>Все пользователи</option>
                            <option value="on_vacation" <?= ($_GET['vacation_filter'] ?? '') == 'on_vacation' ? 'selected' : '' ?>>🏖️ В отпуске</option>
                            <option value="not_on_vacation" <?= ($_GET['vacation_filter'] ?? '') == 'not_on_vacation' ? 'selected' : '' ?>>❌ Не в отпуске</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <button type="submit" class="btn btn-sm btn-info">🔍 Применить фильтр</button>
                        <a href="admin.php?tab=users" class="btn btn-sm btn-warning">🔄 Сбросить</a>
                    </div>
                </form>
            </div>
            
            <?php
            // Строим запрос с фильтрами
            $sql = "SELECT u.*, 
                    (SELECT COUNT(*) FROM users WHERE ref_id = u.id) as refs, 
                    (SELECT SUM(amount) FROM transactions WHERE user_id = u.id AND type = 'task') as earned 
                    FROM users u WHERE 1=1";
            $params = [];
            
            // Фильтр по username
            if (!empty($_GET['search_username'])) {
                $sql .= " AND u.username LIKE ?";
                $params[] = '%' . $_GET['search_username'] . '%';
            }
            
            // Фильтр по отпуску
            if (isset($_GET['vacation_filter']) && $_GET['vacation_filter'] == 'on_vacation') {
                $sql .= " AND u.vacation_used_at IS NOT NULL AND u.vacation_used_at > DATE_SUB(NOW(), INTERVAL 14 DAY)";
            } elseif (isset($_GET['vacation_filter']) && $_GET['vacation_filter'] == 'not_on_vacation') {
                $sql .= " AND (u.vacation_used_at IS NULL OR u.vacation_used_at <= DATE_SUB(NOW(), INTERVAL 14 DAY))";
            }
            
            $sql .= " ORDER BY u.id DESC LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            // Счетчик пользователей в отпуске
            $vacation_count = $pdo->query("SELECT COUNT(*) FROM users WHERE vacation_used_at IS NOT NULL AND vacation_used_at > DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetchColumn();
            ?>
            
            <p style="color: #7f8c8d; font-size:13px; margin-bottom:10px;">
                👥 Всего: <b><?= count($users) ?></b> пользователей 
                <?php if ($vacation_count > 0): ?>
                | 🏖️ В отпуске: <b><?= $vacation_count ?></b>
                <?php endif; ?>
                <?php if (!empty($_GET['search_username'])): ?>
                | 🔍 Поиск: <b><?= htmlspecialchars($_GET['search_username']) ?></b>
                <?php endif; ?>
            </p>
            
            <div style="overflow-x: auto;">
            <table>
                <thead><tr>
                    <th>ID</th><th>Username</th><th>Баланс</th><th>Ключи</th><th>Стрик</th>
                    <th>🏖️ Отпуск</th><th>Реф.</th><th>Дуэли</th><th>Действие</th>
                </tr></thead>
                <tbody>
                    <?php if (count($users) == 0): ?>
                    <tr><td colspan="9" style="text-align:center; color:#999; padding:20px;">Пользователей не найдено</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $user): 
                        $is_on_vacation = (!empty($user['vacation_used_at']) && strtotime($user['vacation_used_at']) > strtotime('-14 days'));
                        $vacation_end = !empty($user['vacation_used_at']) ? date('d.m.Y', strtotime($user['vacation_used_at'] . ' +14 days')) : '—';
                    ?>
                    <tr>
                        <td>#<?= $user['id'] ?></td>
                        <td>@<?= htmlspecialchars($user['username']) ?></td>
                        <td><span class="rub"><?= formatRub($user['balance']) ?></span></td>
                        <td><?= $user['cases_keys'] ?? 0 ?></td>
                        <td><?= $user['daily_streak'] ?? 0 ?> дн.</td>
                        <td>
                            <?php if ($is_on_vacation): ?>
                                <span style="color:#27ae60; font-weight:bold;">🏖️ До <?= $vacation_end ?></span>
                            <?php elseif (!empty($user['vacation_used_at'])): ?>
                                <span style="color:#95a5a6;">✅ Доступен</span>
                            <?php else: ?>
                                <span style="color:#95a5a6;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $user['refs'] ?></td>
                        <td><?= ($user['duel_wins'] ?? 0) . '🏆/' . ($user['duel_losses'] ?? 0) . '❌' ?></td>
                        <td>
                            <a href="#" onclick="toggleEdit('user_<?= $user['id'] ?>')" class="btn btn-sm btn-info">✏️</a>
                            <a href="?action=delete_user&id=<?= $user['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Удалить пользователя и все его данные?')">🗑️</a>
                        </td>
                    </tr>
                    <tr id="user_<?= $user['id'] ?>" style="display:none;">
                        <td colspan="9">
                            <div class="user-edit-form">
                                <form method="POST" action="?action=edit_user&id=<?= $user['id'] ?>">
                                    <div class="form-row-3">
                                        <div class="form-group">
                                            <label>💰 Баланс</label>
                                            <input type="number" step="0.01" name="balance" value="<?= $user['balance'] ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>👥 Реферал (ID)</label>
                                            <input type="number" name="ref_id" value="<?= $user['ref_id'] ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>🔧 Действия</label>
                                            <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                                <label><input type="checkbox" name="reset_streak" value="1"> Сбросить стрик</label>
                                                <label><input type="checkbox" name="reset_vacation" value="1"> Сбросить отпуск</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-row-3">
                                        <div class="form-group">
                                            <label>➕ Начислить</label>
                                            <input type="number" step="1" name="add_amount" placeholder="Сумма">
                                            <button type="submit" name="action_type" value="add_balance" class="btn btn-sm btn-success">➕ Начислить</button>
                                        </div>
                                        <div class="form-group">
                                            <label>➖ Списать</label>
                                            <input type="number" step="1" name="remove_amount" placeholder="Сумма">
                                            <button type="submit" name="action_type" value="remove_balance" class="btn btn-sm btn-danger">➖ Списать</button>
                                        </div>
                                        <div class="form-group">
                                            <label>&nbsp;</label>
                                            <button type="submit" name="action_type" value="edit" class="btn btn-sm btn-primary">💾 Сохранить</button>
                                            <button type="button" onclick="toggleEdit('user_<?= $user['id'] ?>')" class="btn btn-sm btn-warning">✖ Отмена</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    
    <!-- ============ ВКЛАДКА: РАССЫЛКА ============ -->
    <div id="tab-mailing" class="tab-content <?= $tab == 'mailing' ? 'active' : '' ?>">
        <div class="card">
            <h2>📨 Массовая рассылка</h2>
            <form method="POST" action="?action=send_mass_message">
                <div class="form-group">
                    <label>📝 Текст сообщения</label>
                    <textarea name="message_text" rows="6" required placeholder="Введите текст для рассылки..." style="font-size:14px;"></textarea>
                    <small>Поддерживается HTML разметка: &lt;b&gt;жирный&lt;/b&gt;, &lt;i&gt;курсив&lt;/i&gt;</small>
                </div>
                <div class="form-group">
                    <label>👥 Кому отправить</label>
                    <select name="message_type">
                        <option value="all">📢 Всем пользователям</option>
                        <option value="active">🔥 Активным (≥5 заданий)</option>
                        <option value="new">🌱 Новым (≥1 задание)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-success" onclick="return confirm('Отправить рассылку? Это может занять время!')">📨 Отправить рассылку</button>
            </form>
            <p style="margin-top:10px; color:#7f8c8d; font-size:13px;">⚠️ Рассылка отправляется с задержкой 50мс между сообщениями, чтобы избежать блокировки.</p>
        </div>
    </div>
    
    <!-- ============ ВКЛАДКА: КВЕСТЫ ============ -->
    <div id="tab-quests" class="tab-content <?= $tab == 'quests' ? 'active' : '' ?>">
        <div class="card">
            <h2>➕ Добавить квест</h2>
            <form method="POST" action="?action=add_quest">
                <div class="form-row">
                    <div class="form-group">
                        <label>Ключ (уникальный ID)</label>
                        <input type="text" name="key" required placeholder="my_quest_key">
                    </div>
                    <div class="form-group">
                        <label>Название</label>
                        <input type="text" name="name" required placeholder="Название квеста">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Описание</label>
                        <input type="text" name="description" placeholder="Описание квеста">
                    </div>
                    <div class="form-group">
                        <label>Награда (₽)</label>
                        <input type="number" name="reward" required placeholder="10">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Месячный квест</label>
                        <select name="is_monthly">
                            <option value="0">Нет</option>
                            <option value="1">Да</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Требование (дней)</label>
                        <input type="number" name="requirement_days" value="0" placeholder="0">
                    </div>
                </div>
                <button type="submit" class="btn">✅ Добавить квест</button>
            </form>
        </div>
        
        <div class="card">
            <h2>🎯 Все квесты</h2>
            <?php if (count($quests) == 0): ?>
                <p style="color: #999;">Квестов пока нет</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>ID</th><th>Ключ</th><th>Название</th><th>Награда</th><th>Месячный</th><th>Дней</th><th>Действие</th></tr></thead>
                <tbody>
                    <?php foreach ($quests as $q): ?>
                    <tr>
                        <td>#<?= $q['id'] ?></td>
                        <td><code><?= htmlspecialchars($q['key']) ?></code></td>
                        <td><?= htmlspecialchars($q['name']) ?></td>
                        <td><span class="rub"><?= formatRub($q['reward']) ?></span></td>
                        <td><?= $q['is_monthly'] ? '✅' : '❌' ?></td>
                        <td><?= $q['requirement_days'] ?></td>
                        <td><a href="?action=delete_quest&id=<?= $q['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Удалить квест?')">🗑️</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ============ ВКЛАДКА: ДУЭЛИ ============ -->
    <div id="tab-duels" class="tab-content <?= $tab == 'duels' ? 'active' : '' ?>">
        <div class="card">
            <h2>⚔️ Активные дуэли</h2>
            <?php if (count($active_duels) == 0): ?>
                <p style="color: #999;">Нет активных дуэлей</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>ID</th><th>Участник 1</th><th>Участник 2</th><th>Ставка</th><th>Статус</th><th>Начало</th><th>Действие</th></tr></thead>
                <tbody>
                    <?php foreach ($active_duels as $d): ?>
                    <tr>
                        <td>#<?= $d['id'] ?></td>
                        <td>@<?= htmlspecialchars($d['user1_name'] ?? '—') ?></td>
                        <td>@<?= htmlspecialchars($d['user2_name'] ?? 'Ожидание') ?></td>
                        <td><span class="rub"><?= formatRub($d['bet']) ?></span></td>
                        <td><span class="badge badge-<?= $d['status'] ?>"><?= $d['status'] == 'waiting' ? '⏳ Ожидание' : '⚔️ Активна' ?></span></td>
                        <td><?= date('d.m.Y H:i', strtotime($d['started_at'])) ?></td>
                        <td>
                            <?php if ($d['status'] == 'active' && $d['user2_id']): ?>
                            <a href="?action=finish_duel&id=<?= $d['id'] ?>&winner=<?= $d['user1_id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Победитель: @<?= htmlspecialchars($d['user1_name']) ?>?')">🏆 У1</a>
                            <a href="?action=finish_duel&id=<?= $d['id'] ?>&winner=<?= $d['user2_id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Победитель: @<?= htmlspecialchars($d['user2_name']) ?>?')">🏆 У2</a>
                            <?php endif; ?>
                            <a href="?action=delete_duel&id=<?= $d['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Удалить дуэль?')">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ============ ВКЛАДКА: СТАТИСТИКА ============ -->
    <div id="tab-stats" class="tab-content <?= $tab == 'stats' ? 'active' : '' ?>">
        <div class="card">
            <h2>📊 Общая статистика</h2>
            <?php
            $total_tasks = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
            $total_user_tasks = $pdo->query("SELECT COUNT(*) FROM user_tasks")->fetchColumn();
            $total_transactions = $pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
            $total_referrals = $pdo->query("SELECT COUNT(*) FROM referrals")->fetchColumn();
            $total_invites = $pdo->query("SELECT COUNT(*) FROM invite_transfers")->fetchColumn();
            $total_quests_completed = $pdo->query("SELECT COUNT(*) FROM user_quests WHERE status = 'completed'")->fetchColumn();
            ?>
            <div class="stats" style="margin-bottom:0;">
                <div class="stat"><h3>📋 Всего заданий</h3><div class="number blue"><?= $total_tasks ?></div></div>
                <div class="stat"><h3>✅ Выполнено заданий</h3><div class="number green"><?= $total_user_tasks ?></div></div>
                <div class="stat"><h3>💸 Транзакций</h3><div class="number orange"><?= $total_transactions ?></div></div>
                <div class="stat"><h3>👥 Рефералов</h3><div class="number purple"><?= $total_referrals ?></div></div>
                <div class="stat"><h3>📨 Приглашений</h3><div class="number blue"><?= $total_invites ?></div></div>
                <div class="stat"><h3>🎯 Квестов выполнено</h3><div class="number green"><?= $total_quests_completed ?></div></div>
            </div>
        </div>
        
        <div class="card">
            <h2>🗑️ Очистка данных</h2>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="?action=clear_logs&id=transactions" class="btn btn-danger" onclick="return confirm('Очистить все транзакции?')">🗑️ Очистить транзакции</a>
                <a href="?action=clear_logs&id=user_tasks" class="btn btn-danger" onclick="return confirm('Очистить историю заданий?')">🗑️ Очистить историю заданий</a>
            </div>
            <p style="margin-top:10px; color:#e74c3c; font-size:13px;">⚠️ Внимание! Очистка данных необратима!</p>
        </div>
        
        <div class="card">
            <h2>📈 Топ пользователей</h2>
            <?php
            $top_balance = $pdo->query("SELECT username, balance FROM users ORDER BY balance DESC LIMIT 10")->fetchAll();
            $top_tasks = $pdo->query("SELECT u.username, COUNT(ut.id) as cnt FROM users u JOIN user_tasks ut ON u.id = ut.user_id WHERE ut.status = 'completed' GROUP BY u.id ORDER BY cnt DESC LIMIT 10")->fetchAll();
            ?>
            <div class="form-row">
                <div>
                    <h4>💰 Топ по балансу</h4>
                    <?php foreach ($top_balance as $t): ?>
                    <div>@<?= htmlspecialchars($t['username']) ?> — <?= formatRub($t['balance']) ?></div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <h4>📋 Топ по заданиям</h4>
                    <?php foreach ($top_tasks as $t): ?>
                    <div>@<?= htmlspecialchars($t['username']) ?> — <?= $t['cnt'] ?> заданий</div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.target.classList.add('active');
    // Обновляем URL с параметром tab
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

function toggleEdit(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
    }
}

// При загрузке показываем активную вкладку
document.addEventListener('DOMContentLoaded', function() {
    const tab = '<?= $tab ?>';
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.querySelector(`.tab[onclick*="${tab}"]`)?.classList.add('active');
});
</script>
</body>
</html>