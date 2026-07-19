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

// Обработка действий
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // Создание задания
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
    
    // РУЧНОЕ ПОДТВЕРЖДЕНИЕ задания (для случаев когда бот не админ)
    if ($action == 'approve_task' && isset($_GET['id'])) {
        $user_task_id = $_GET['id'];
        
        // Получаем данные
        $stmt = $pdo->prepare("SELECT ut.*, t.reward, t.title, u.id as user_id FROM user_tasks ut JOIN tasks t ON ut.task_id = t.id JOIN users u ON ut.user_id = u.id WHERE ut.id = ?");
        $stmt->execute([$user_task_id]);
        $data = $stmt->fetch();
        
        if ($data) {
            // Начисляем награду
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$data['reward'], $data['user_id']]);
            
            // Обновляем статус
            $stmt = $pdo->prepare("UPDATE user_tasks SET status = 'completed' WHERE id = ?");
            $stmt->execute([$user_task_id]);
            
            // Добавляем транзакцию
            $desc = 'Выполнение задания (ручное подтверждение): ' . $data['title'];
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'task', ?, NOW())");
            $stmt->execute([$data['user_id'], $data['reward'], $desc]);
            
            // Начисляем реферальный бонус (25%)
            $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $ref_id = $stmt->fetchColumn();
            
            if ($ref_id > 0) {
                $ref_bonus = $data['reward'] * 0.25;
                
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$ref_bonus, $ref_id]);
                
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'ref_bonus', 'Бонус за реферала (25%)', NOW())");
                $stmt->execute([$ref_id, $ref_bonus]);
                
                $stmt = $pdo->prepare("INSERT INTO referrals (user_id, ref_user_id, income, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$ref_id, $data['user_id'], $ref_bonus]);
            }
            
            // Уведомляем пользователя
            $stmt = $pdo->prepare("SELECT telegram_id, username FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                $text = "✅ <b>Задание подтверждено администратором!</b>\n\n";
                $text .= "📌 Задание: " . $data['title'] . "\n";
                $text .= "💰 Начислено: <b>" . formatRub($data['reward']) . "</b>\n";
                if ($ref_id > 0) {
                    $text .= "👥 Реферальный бонус: <b>" . formatRub($ref_bonus) . "</b>\n";
                }
                $text .= "\n💵 Твой баланс обновлён!";
                sendMessage($user['telegram_id'], $text, mainKeyboard());
            }
            
            header('Location: admin.php?msg=Задание подтверждено! Начислено ' . formatRub($data['reward']));
        } else {
            header('Location: admin.php?msg-error=Ошибка подтверждения!');
        }
        exit;
    }
    
    // Отклонение задания
    if ($action == 'reject_task' && isset($_GET['id'])) {
        $user_task_id = $_GET['id'];
        
        // Получаем данные для уведомления
        $stmt = $pdo->prepare("SELECT ut.*, t.title, u.id as user_id, u.telegram_id FROM user_tasks ut JOIN tasks t ON ut.task_id = t.id JOIN users u ON ut.user_id = u.id WHERE ut.id = ?");
        $stmt->execute([$user_task_id]);
        $data = $stmt->fetch();
        
        // Обновляем статус
        $stmt = $pdo->prepare("UPDATE user_tasks SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$user_task_id]);
        
        // Уведомляем пользователя
        if ($data && $data['telegram_id']) {
            $text = "❌ <b>Задание отклонено администратором!</b>\n\n";
            $text .= "📌 Задание: " . $data['title'] . "\n";
            $text .= "💬 Попробуй выполнить задание ещё раз или обратись в поддержку.";
            sendMessage($data['telegram_id'], $text, mainKeyboard());
        }
        
        header('Location: admin.php?msg=Задание отклонено!');
        exit;
    }
    
    // Выплата
    if ($action == 'withdraw' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("UPDATE withdraws SET status = 'approved' WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        
        // Уведомляем пользователя
        $stmt = $pdo->prepare("SELECT w.*, u.telegram_id, u.username FROM withdraws w JOIN users u ON w.user_id = u.id WHERE w.id = ?");
        $stmt->execute([$_GET['id']]);
        $data = $stmt->fetch();
        
        if ($data && $data['telegram_id']) {
            $text = "✅ <b>Ваша заявка на вывод одобрена!</b>\n\n";
            $text .= "💰 Сумма: <b>" . formatRub($data['amount']) . "</b>\n";
            $text .= "📋 Заявка #" . $data['id'] . "\n\n";
            $text .= "💵 Средства отправлены на указанные реквизиты.";
            sendMessage($data['telegram_id'], $text, mainKeyboard());
        }
        
        header('Location: admin.php?msg=Выплата подтверждена!');
        exit;
    }
    
    // Отклонение выплаты
    if ($action == 'reject_withdraw' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("UPDATE withdraws SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        
        // Возвращаем средства пользователю
        $stmt = $pdo->prepare("SELECT user_id, amount, telegram_id, username FROM withdraws w JOIN users u ON w.user_id = u.id WHERE w.id = ?");
        $stmt->execute([$_GET['id']]);
        $data = $stmt->fetch();
        
        if ($data) {
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$data['amount'], $data['user_id']]);
            
            // Уведомляем пользователя
            if ($data['telegram_id']) {
                $text = "❌ <b>Ваша заявка на вывод отклонена!</b>\n\n";
                $text .= "💰 Сумма: <b>" . formatRub($data['amount']) . "</b>\n";
                $text .= "📋 Заявка #" . $_GET['id'] . "\n\n";
                $text .= "💵 Средства возвращены на баланс.\n";
                $text .= "📝 Свяжитесь с поддержкой для уточнения деталей.";
                sendMessage($data['telegram_id'], $text, mainKeyboard());
            }
        }
        
        header('Location: admin.php?msg=Выплата отклонена, средства возвращены!');
        exit;
    }
    
    // Удаление задания
    if ($action == 'delete_task' && isset($_GET['id'])) {
        $task_id = $_GET['id'];
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("DELETE FROM user_tasks WHERE task_id = ?");
            $stmt->execute([$task_id]);
            
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->execute([$task_id]);
            
            $pdo->commit();
            
            header('Location: admin.php?msg=Задание и все связанные записи удалены!');
        } catch (PDOException $e) {
            $pdo->rollBack();
            header('Location: admin.php?msg-error=Ошибка при удалении: ' . $e->getMessage());
        }
        exit;
    }
}

// Добавляем колонки если их нет
try {
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_withdraw_method VARCHAR(50) DEFAULT NULL");
    $pdo->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS withdraw_waiting_text VARCHAR(10) DEFAULT NULL");
    $pdo->query("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS requirement_days INT DEFAULT 0");
} catch (PDOException $e) {}

// Получаем данные для отображения
$users_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$tasks_count = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'active'")->fetchColumn();
$pending_tasks = $pdo->query("SELECT ut.*, u.username, u.telegram_id, t.title, t.reward FROM user_tasks ut JOIN users u ON ut.user_id = u.id JOIN tasks t ON ut.task_id = t.id WHERE ut.status = 'pending' ORDER BY ut.completed_at DESC")->fetchAll();
$withdraws = $pdo->query("SELECT w.*, u.username FROM withdraws w JOIN users u ON w.user_id = u.id WHERE w.status = 'pending' ORDER BY w.created_at DESC")->fetchAll();
$all_tasks = $pdo->query("SELECT * FROM tasks ORDER BY created_at DESC")->fetchAll();
$all_withdraws = $pdo->query("SELECT w.*, u.username FROM withdraws w JOIN users u ON w.user_id = u.id ORDER BY w.created_at DESC LIMIT 50")->fetchAll();
$completed_tasks = $pdo->query("SELECT COUNT(*) FROM user_tasks WHERE status = 'completed'")->fetchColumn();
$total_withdraws = $pdo->query("SELECT SUM(amount) FROM withdraws WHERE status = 'approved'")->fetchColumn() ?: 0;
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
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; padding: 18px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat h3 { color: #7f8c8d; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat .number { font-size: 28px; font-weight: bold; color: #2c3e50; margin-top: 5px; }
        .stat .number.green { color: #27ae60; }
        .stat .number.orange { color: #f39c12; }
        .stat .number.red { color: #e74c3c; }
        .stat .number.blue { color: #3498db; }
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
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
        .requirement-days { color: #e67e22; font-size: 12px; }
        .task-pending { background: #fff3cd; }
        .task-pending td { border-bottom: 1px solid #ffc107; }
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
        <div class="stat">
            <h3>👤 Пользователей</h3>
            <div class="number blue"><?= $users_count ?></div>
        </div>
        <div class="stat">
            <h3>📋 Активных заданий</h3>
            <div class="number green"><?= $tasks_count ?></div>
        </div>
        <div class="stat">
            <h3>⏳ На проверке</h3>
            <div class="number orange"><?= count($pending_tasks) ?></div>
        </div>
        <div class="stat">
            <h3>✅ Выполнено заданий</h3>
            <div class="number green"><?= $completed_tasks ?></div>
        </div>
        <div class="stat">
            <h3>💳 Заявок на вывод</h3>
            <div class="number orange"><?= count($withdraws) ?></div>
        </div>
        <div class="stat">
            <h3>💰 Выведено всего</h3>
            <div class="number green"><?= formatRub($total_withdraws) ?></div>
        </div>
    </div>
    
    <div class="tabs">
        <div class="tab active" onclick="showTab('tasks')">📋 Задания</div>
        <div class="tab <?= count($pending_tasks) > 0 ? 'active' : '' ?>" onclick="showTab('pending')">⏳ Проверка <span class="badge badge-pending" style="font-size: 10px;"><?= count($pending_tasks) ?></span></div>
        <div class="tab" onclick="showTab('withdraws')">💳 Выводы</div>
        <div class="tab" onclick="showTab('users')">👤 Пользователи</div>
    </div>
    
    <!-- Вкладка: Задания -->
    <div id="tab-tasks" class="tab-content <?= count($pending_tasks) == 0 ? 'active' : '' ?>">
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
                        <small>Сумма в рублях</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" rows="3" required placeholder="Подробное описание задания..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Лимит (0 = безлимит)</label>
                        <input type="number" name="limit_count" value="0" placeholder="0">
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
                        <small>Минимальное количество дней пользователя в проекте</small>
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
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Награда</th>
                        <th>Выполнено</th>
                        <th>Требование</th>
                        <th>Канал</th>
                        <th>Статус</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_tasks as $task): ?>
                    <tr>
                        <td>#<?= $task['id'] ?></td>
                        <td><?= htmlspecialchars($task['title']) ?></td>
                        <td><span class="rub"><?= formatRub($task['reward']) ?></span></td>
                        <td><?= $task['completed_count'] ?>/<?= $task['limit_count'] ?: '∞' ?></td>
                        <td><?= $task['requirement_days'] > 0 ? $task['requirement_days'] . ' дней' : '—' ?></td>
                        <td>
                            <?php if (!empty($task['channel_id'])): ?>
                                <span class="channel-link"><?= htmlspecialchars($task['channel_id']) ?></span>
                            <?php else: ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $task['status'] ?>">
                                <?= $task['status'] == 'active' ? 'Активно' : ($task['status'] == 'paused' ? 'Пауза' : 'Завершено') ?>
                            </span>
                        </td>
                        <td>
                            <a href="?action=delete_task&id=<?= $task['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('⚠️ Внимание! Будут удалены все записи о выполнении этого задания. Продолжить?')">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Вкладка: Проверка (РУЧНАЯ) -->
    <div id="tab-pending" class="tab-content <?= count($pending_tasks) > 0 ? 'active' : '' ?>">
        <div class="card">
            <h2>⏳ Задания на ручной проверке</h2>
            <p style="color: #7f8c8d; margin-bottom: 15px;">
                🔹 Здесь отображаются задания, которые требуют ручной проверки администратором.<br>
                🔹 Это происходит когда бот не может автоматически проверить подписку (не добавлен в канал как администратор).<br>
                🔹 Проверьте, что пользователь действительно выполнил условие, затем подтвердите или отклоните.
            </p>
            <?php if (count($pending_tasks) == 0): ?>
                <p style="color: #999;">✅ Нет заданий на ручной проверке</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Пользователь</th>
                        <th>Задание</th>
                        <th>Награда</th>
                        <th>Дата</th>
                        <th>Канал</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_tasks as $pt): ?>
                    <tr class="task-pending">
                        <td>@<?= htmlspecialchars($pt['username']) ?></td>
                        <td><?= htmlspecialchars($pt['title']) ?></td>
                        <td>
                            <span class="rub"><?= formatRub($pt['reward']) ?></span>
                            <span class="eur">(≈<?= rubToEur($pt['reward']) ?> €)</span>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($pt['completed_at'])) ?></td>
                        <td>
                            <?php
                            // Пытаемся получить ссылку на канал
                            $stmt2 = $pdo->prepare("SELECT channel_id FROM tasks WHERE id = ?");
                            $stmt2->execute([$pt['task_id']]);
                            $channel_id = $stmt2->fetchColumn();
                            if ($channel_id) {
                                if (strpos($channel_id, '@') === 0) {
                                    echo '<a href="https://t.me/' . substr($channel_id, 1) . '" target="_blank">' . htmlspecialchars($channel_id) . '</a>';
                                } else {
                                    echo htmlspecialchars($channel_id);
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <a href="?action=approve_task&id=<?= $pt['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Подтвердить выполнение и начислить награду?')">✅ Подтвердить</a>
                            <a href="?action=reject_task&id=<?= $pt['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Отклонить выполнение? Пользователь получит уведомление.')">❌ Отклонить</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Вкладка: Выводы -->
    <div id="tab-withdraws" class="tab-content">
        <div class="card">
            <h2>💳 Заявки на вывод</h2>
            <?php if (count($withdraws) == 0): ?>
                <p style="color: #999;">Нет заявок на вывод</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Пользователь</th>
                        <th>Сумма</th>
                        <th>Способ</th>
                        <th>Данные</th>
                        <th>Дата</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdraws as $w): ?>
                    <tr>
                        <td>#<?= $w['id'] ?></td>
                        <td>@<?= htmlspecialchars($w['username']) ?></td>
                        <td><span class="rub"><?= formatRub($w['amount']) ?></span></td>
                        <td>
                            <?php if ($w['method'] == 'crypto'): ?>
                                <span class="method-crypto">💎 Крипто</span>
                            <?php else: ?>
                                <span class="method-bank">🏦 Банк</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= htmlspecialchars(substr($w['details'], 0, 50)) ?>...</small></td>
                        <td><?= date('d.m.Y H:i', strtotime($w['created_at'])) ?></td>
                        <td>
                            <a href="?action=withdraw&id=<?= $w['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Подтвердить выплату?')">✅</a>
                            <a href="?action=reject_withdraw&id=<?= $w['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Отклонить выплату? Средства вернутся пользователю.')">❌</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>📜 История всех заявок</h2>
            <?php if (count($all_withdraws) == 0): ?>
                <p style="color: #999;">История пуста</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Пользователь</th>
                        <th>Сумма</th>
                        <th>Способ</th>
                        <th>Статус</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_withdraws as $w): ?>
                    <tr>
                        <td>#<?= $w['id'] ?></td>
                        <td>@<?= htmlspecialchars($w['username']) ?></td>
                        <td><span class="rub"><?= formatRub($w['amount']) ?></span></td>
                        <td>
                            <?php if ($w['method'] == 'crypto'): ?>
                                💎 Крипто
                            <?php else: ?>
                                🏦 Банк
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $w['status'] ?>">
                                <?= $w['status'] == 'pending' ? '⏳ Ожидает' : ($w['status'] == 'approved' ? '✅ Выплачено' : '❌ Отклонено') ?>
                            </span>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($w['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Вкладка: Пользователи -->
    <div id="tab-users" class="tab-content">
        <div class="card">
            <h2>👤 Последние пользователи</h2>
            <?php
            $users = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM users WHERE ref_id = u.id) as refs, (SELECT SUM(amount) FROM transactions WHERE user_id = u.id AND type = 'task') as earned FROM users u ORDER BY u.created_at DESC LIMIT 20")->fetchAll();
            ?>
            <?php if (count($users) == 0): ?>
                <p style="color: #999;">Пользователей пока нет</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Баланс</th>
                        <th>Заработал</th>
                        <th>Рефералов</th>
                        <th>Дней</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>#<?= $user['id'] ?></td>
                        <td>@<?= htmlspecialchars($user['username']) ?></td>
                        <td><span class="rub"><?= formatRub($user['balance']) ?></span></td>
                        <td><span class="rub"><?= formatRub($user['earned'] ?? 0) ?></span></td>
                        <td><?= $user['refs'] ?></td>
                        <td><?= round((time() - strtotime($user['created_at'])) / 86400) ?></td>
                        <td><?= date('d.m.Y', strtotime($user['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.target.classList.add('active');
}
</script>
</body>
</html>