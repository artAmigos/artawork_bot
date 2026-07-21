<?php
require_once 'config.php';

// Обработка входящих обновлений
function processUpdate($update) {
    global $pdo;
    
    // Обработка сообщений
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $username = $message['from']['username'] ?? 'user';
        
        // Регистрируем пользователя
        $user_id = registerUser($chat_id, $username);
        
        // Проверяем реферальную ссылку при старте

        
// Проверяем реферальную ссылку при старте - ТОЛЬКО ДЛЯ НОВЫХ ПОЛЬЗОВАТЕЛЕЙ!
if (strpos($text, '/start') === 0) {
    $parts = explode(' ', $text);
    if (isset($parts[1]) && strpos($parts[1], 'ref_') === 0) {
        $ref_id = (int)str_replace('ref_', '', $parts[1]);
        
        // Проверяем, есть ли у пользователя уже реферал
        $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $current_ref = $stmt->fetchColumn();
        
        // Сохраняем реферала ТОЛЬКО если у пользователя ещё нет реферала
        // И реферал не равен самому себе
        if ($current_ref == 0 && $ref_id > 0 && $ref_id != $user_id) {
            $stmt = $pdo->prepare("UPDATE users SET ref_id = ? WHERE id = ?");
            $stmt->execute([$ref_id, $user_id]);
        }
    }
}

        // Обработка команд
        if ($text == '/start') {
            $welcome = "👋 Добро пожаловать в ArtaWork!\n\n";
            $welcome .= "Здесь ты можешь зарабатывать, выполняя простые задания.\n\n";
            $welcome .= "📌 Нажми на кнопку «📋 Задания», чтобы начать зарабатывать!\n";
            $welcome .= "💰 Минимальная сумма вывода: 5 000 ₽ (≈" . rubToEur(5000) . " €)";
            
            sendMessage($chat_id, $welcome, mainKeyboard());
        }
        elseif ($text == '💰 Баланс') {
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE telegram_id = ?");
            $stmt->execute([$chat_id]);
            $balance = $stmt->fetchColumn();
            
            $text = "💰 <b>Твой баланс</b>\n\n";
            $text .= "💵 <b>" . formatRub($balance) . "</b>\n";
            $text .= "💶 ≈ " . rubToEur($balance) . " €\n\n";
            $text .= "📊 Минимальный вывод: " . formatRub(MIN_WITHDRAW_RUB) . " (≈" . rubToEur(MIN_WITHDRAW_RUB) . " €)";
            
            sendMessage($chat_id, $text, mainKeyboard());
        }
        elseif ($text == '📋 Задания') {
            showTasks($chat_id, $user_id);
        }
        elseif ($text == '🎁 Бонус дня') {
            $stmt = $pdo->prepare("SELECT * FROM daily_bonuses WHERE user_id = ? AND bonus_date = CURDATE()");
            $stmt->execute([$user_id]);
            $bonus_today = $stmt->fetch();
            
            if ($bonus_today) {
                sendMessage($chat_id, "🎁 Ты уже получил бонус сегодня! Возвращайся завтра.", mainKeyboard());
            } else {
                $bonus = 50;
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$bonus, $user_id]);
                
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'daily_bonus', 'Ежедневный бонус', NOW())");
                $stmt->execute([$user_id, $bonus]);
                
                $stmt = $pdo->prepare("INSERT INTO daily_bonuses (user_id, bonus_date, amount) VALUES (?, CURDATE(), ?)");
                $stmt->execute([$user_id, $bonus]);
                
                sendMessage($chat_id, "🎁 Ты получил ежедневный бонус: <b>" . formatRub($bonus) . "</b>\n\n💰 Баланс обновлён!", mainKeyboard());
            }
        }
        elseif ($text == '👥 Рефералы') {
            $stmt = $pdo->prepare("SELECT id, username, created_at FROM users WHERE ref_id = ?");
            $stmt->execute([$user_id]);
            $refs = $stmt->fetchAll();
            
            $stmt = $pdo->prepare("SELECT SUM(income) as total FROM referrals WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $total_income = $stmt->fetchColumn() ?: 0;
            
            $text = "👥 <b>Твои рефералы</b>\n\n";
            $text .= "💰 Ты получаешь <b>" . REFERRAL_PERCENT . "%</b> от заработка приглашённых!\n\n";
            $text .= "📊 Доход с рефералов: <b>" . formatRub($total_income) . "</b>\n";
            $text .= "💶 ≈ " . rubToEur($total_income) . " €\n\n";
            $text .= "🔗 Твоя реферальная ссылка:\n";
            $text .= "<code>https://t.me/" . BOT_USERNAME . "?start=ref_" . $user_id . "</code>\n\n";
            
            if (count($refs) > 0) {
                $text .= "👥 Приглашено: " . count($refs) . " человек\n";
                foreach ($refs as $ref) {
                    $text .= "• @" . $ref['username'] . " (" . date('d.m.Y', strtotime($ref['created_at'])) . ")\n";
                }
            } else {
                $text .= "📊 Пока нет приглашённых\n";
                $text .= "Пригласи друзей и начни зарабатывать!";
            }
            
            sendMessage($chat_id, $text, mainKeyboard());
        }
        elseif ($text == '💳 Вывод') {
            showWithdrawMenu($chat_id, $user_id);
        }
        elseif ($text == '📊 Мои выводы') {
            $stmt = $pdo->prepare("SELECT * FROM withdraws WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user_id]);
            $withdraws = $stmt->fetchAll();
            
            if (count($withdraws) == 0) {
                sendMessage($chat_id, "📊 У тебя пока нет заявок на вывод.", mainKeyboard());
            } else {
                $text = "📊 <b>Мои заявки на вывод</b>\n\n";
                foreach ($withdraws as $w) {
                    $status_text = $w['status'] == 'pending' ? '⏳ Ожидает' : ($w['status'] == 'approved' ? '✅ Выплачено' : '❌ Отклонено');
                    $method_text = $w['method'] == 'crypto' ? '💎 Крипто' : '🏦 Банк';
                    $text .= "• #" . $w['id'] . " - " . formatRub($w['amount']) . " (" . $method_text . ") - " . $status_text . "\n";
                    $text .= "  📅 " . date('d.m.Y H:i', strtotime($w['created_at'])) . "\n\n";
                }
                sendMessage($chat_id, $text, mainKeyboard());
            }
        }
        elseif ($text == '👤 Профиль') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            $rank = getUserRank($user_id);
            $bonus = getActivityBonus($user_id);
            
            $text = "👤 <b>Твой профиль</b>\n\n";
            $text .= $rank['icon'] . " <b>" . $rank['name'] . "</b>\n\n";
            $text .= "🆔 ID: " . $user['id'] . "\n";
            $text .= "👤 Username: @" . $user['username'] . "\n";
            $text .= "💰 Баланс: <b>" . formatRub($user['balance']) . "</b>\n";
            $text .= "💶 ≈ " . rubToEur($user['balance']) . " €\n";
            $text .= "📅 В проекте: " . round((time() - strtotime($user['created_at'])) / 86400) . " дней\n";
            
            if ($bonus > 0) {
                $text .= "\n🔥 <b>Бонус активности: +" . $bonus . "% к заработку</b>\n";
            }
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(amount) as earned FROM transactions WHERE user_id = ? AND type = 'task'");
            $stmt->execute([$user_id]);
            $stats = $stmt->fetch();
            
            $text .= "\n\n📊 <b>Статистика</b>\n";
            $text .= "📋 Выполнено заданий: " . ($stats['total'] ?? 0) . "\n";
            $text .= "💰 Заработано: <b>" . formatRub($stats['earned'] ?? 0) . "</b>";
            
            sendMessage($chat_id, $text, mainKeyboard());
        }
        elseif ($text == '❓ Помощь') {
            $text = "❓ <b>Помощь</b>\n\n";
            $text .= "📌 <b>Как заработать?</b>\n";
            $text .= "1. Нажми «📋 Задания»\n";
            $text .= "2. Выбери задание и выполни его\n";
            $text .= "3. Получи награду на баланс\n\n";
            $text .= "💰 <b>Вывод средств:</b>\n";
            $text .= "Минимальная сумма: " . formatRub(MIN_WITHDRAW_RUB) . " (≈" . rubToEur(MIN_WITHDRAW_RUB) . " €)\n";
            $text .= "Способы: USDT TRC20 или на карту\n\n";
            $text .= "👥 <b>Реферальная программа:</b>\n";
            $text .= "Приглашай друзей и получай <b>" . REFERRAL_PERCENT . "%</b> от их заработка!\n\n";
            $text .= "🔥 <b>Бонусы активности:</b>\n";
            $text .= "• 3 дня в проекте: +5% к заработку\n";
            $text .= "• 7 дней: +7%\n";
            $text .= "• 14 дней: +10%\n\n";
            $text .= "📧 По всем вопросам: @artawork_support";
            
            sendMessage($chat_id, $text, mainKeyboard());
        }
    }
    
    // Обработка callback запросов
    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $chat_id = $callback['from']['id'];
        $data = $callback['data'];
        $message_id = $callback['message']['message_id'];
        $username = $callback['from']['username'] ?? 'user';
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
        $stmt->execute([$chat_id]);
        $user_id = $stmt->fetchColumn();
        
        if (!$user_id) {
            sendMessage($chat_id, "❌ Сначала запусти бота командой /start");
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
            return;
        }
        
        // Просмотр деталей задания
        if (strpos($data, 'task_detail_') === 0) {
            $task_id = str_replace('task_detail_', '', $data);
            showTaskDetail($chat_id, $user_id, $task_id, $message_id, $callback['id']);
        }
        
        // Выполнение задания
        if (strpos($data, 'task_do_') === 0) {
            $task_id = str_replace('task_do_', '', $data);
            doTask($chat_id, $user_id, $task_id, $username, $callback['id']);
        }
        
        // Проверка подписки
        if (strpos($data, 'check_sub_') === 0) {
            $task_id = str_replace('check_sub_', '', $data);
            checkSubscriptionCallback($chat_id, $user_id, $task_id, $callback['id']);
        }
        
        // Обновление списка заданий
        if ($data == 'refresh_tasks') {
            showTasksInline($chat_id, $user_id, $message_id, $callback['id']);
        }
        
        // Вывод средств
        if ($data == 'withdraw_crypto' || $data == 'withdraw_bank') {
            processWithdraw($chat_id, $user_id, $data, $callback['id']);
        }
        
        if ($data == 'withdraw_cancel') {
            sendMessage($chat_id, "❌ Вывод отменён.", mainKeyboard());
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }
    }
}

// ============ ФУНКЦИИ ДЛЯ ЗАДАНИЙ ============

function showTasks($chat_id, $user_id) {
    global $pdo;
    
    $completed_tasks = getUserCompletedTasks($user_id);
    $completed_ids = !empty($completed_tasks) ? implode(',', array_map('intval', $completed_tasks)) : '0';
    
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE status = 'active' AND (limit_count = 0 OR completed_count < limit_count) AND id NOT IN ($completed_ids) ORDER BY created_at DESC LIMIT 50");
    $stmt->execute();
    $tasks = $stmt->fetchAll();
    
    if (count($tasks) == 0) {
        sendMessage($chat_id, "😕 Пока нет доступных заданий. Загляни позже!", mainKeyboard());
        return;
    }
    
    $list_text = "📋 <b>Доступные задания:</b>\n\n";
    foreach ($tasks as $index => $task) {
        $list_text .= ($index + 1) . ". " . $task['title'] . "\n";
        $list_text .= "   💰 Награда: <b>" . formatRub($task['reward']) . "</b>\n";
        if ($task['requirement_days'] > 0) {
            $list_text .= "   ⏳ Требование: " . $task['requirement_days'] . " дней в проекте\n";
        }
        $list_text .= "\n";
    }
    $list_text .= "⬇️ Нажми на кнопку ниже, чтобы выбрать задание:";
    
    $inlineKeyboard = ['inline_keyboard' => []];
    foreach ($tasks as $task) {
        $btn_text = $task['title'] . ' - ' . formatRub($task['reward']);
        $inlineKeyboard['inline_keyboard'][] = [
            ['text' => $btn_text, 'callback_data' => 'task_detail_' . $task['id']]
        ];
    }
    $inlineKeyboard['inline_keyboard'][] = [['text' => '🔄 Обновить список', 'callback_data' => 'refresh_tasks']];
    
    sendMessage($chat_id, $list_text, $inlineKeyboard);
}

function showTasksInline($chat_id, $user_id, $message_id, $callback_id) {
    global $pdo;
    
    $completed_tasks = getUserCompletedTasks($user_id);
    $completed_ids = !empty($completed_tasks) ? implode(',', array_map('intval', $completed_tasks)) : '0';
    
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE status = 'active' AND (limit_count = 0 OR completed_count < limit_count) AND id NOT IN ($completed_ids) ORDER BY created_at DESC LIMIT 50");
    $stmt->execute();
    $tasks = $stmt->fetchAll();
    
    if (count($tasks) == 0) {
        editMessage($chat_id, $message_id, "😕 Пока нет доступных заданий. Загляни позже!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $list_text = "📋 <b>Доступные задания:</b>\n\n";
    foreach ($tasks as $index => $task) {
        $list_text .= ($index + 1) . ". " . $task['title'] . "\n";
        $list_text .= "   💰 Награда: <b>" . formatRub($task['reward']) . "</b>\n";
        if ($task['requirement_days'] > 0) {
            $list_text .= "   ⏳ Требование: " . $task['requirement_days'] . " дней в проекте\n";
        }
        $list_text .= "\n";
    }
    $list_text .= "⬇️ Нажми на кнопку ниже, чтобы выбрать задание:";
    
    $inlineKeyboard = ['inline_keyboard' => []];
    foreach ($tasks as $task) {
        $btn_text = $task['title'] . ' - ' . formatRub($task['reward']);
        $inlineKeyboard['inline_keyboard'][] = [
            ['text' => $btn_text, 'callback_data' => 'task_detail_' . $task['id']]
        ];
    }
    $inlineKeyboard['inline_keyboard'][] = [['text' => '🔄 Обновить список', 'callback_data' => 'refresh_tasks']];
    
    editMessage($chat_id, $message_id, $list_text, $inlineKeyboard);
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

function showTaskDetail($chat_id, $user_id, $task_id, $message_id, $callback_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        sendMessage($chat_id, "❌ Задание не найдено", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $text = "📌 <b>" . $task['title'] . "</b>\n\n";
    $text .= $task['description'] . "\n\n";
    $text .= "💰 Награда: <b>" . formatRub($task['reward']) . "</b>\n";
    $text .= "💶 ≈ " . rubToEur($task['reward']) . " €\n";
    
    if ($task['requirement_days'] > 0) {
        $text .= "⏳ Требование: быть в проекте <b>" . $task['requirement_days'] . " дней</b>\n";
    }
    $text .= "\n";
    
    if (!empty($task['channel_id'])) {
        $channel_link = $task['channel_id'];
        if (strpos($channel_link, '@') === 0) {
            $channel_link = 'https://t.me/' . substr($channel_link, 1);
        } elseif (strpos($channel_link, '-100') === 0) {
            $channel_link = 'https://t.me/c/' . substr($channel_link, 4);
        }
        $text .= "🔗 <b>Подпишись на канал:</b>\n";
        $text .= "<a href='" . $channel_link . "'>👉 Перейти и подписаться</a>\n\n";
    }
    
    $text .= "⚠️ <b>Внимание!</b>\n";
    $text .= "Пользователи должны быть в подписанных сообществах минимум 3 дня.\n";
    $text .= "Иначе может привести к списанию средств с баланса.\n\n";
    
    $text .= "✅ После выполнения нажми кнопку ниже:";
    
    $inlineKeyboard = [
        'inline_keyboard' => [
            [['text' => '✅ Выполнил задание', 'callback_data' => 'task_do_' . $task_id]],
            [['text' => '🔙 Назад к списку', 'callback_data' => 'refresh_tasks']]
        ]
    ];
    
    editMessage($chat_id, $message_id, $text, $inlineKeyboard);
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

// ============ ОСНОВНАЯ ФУНКЦИЯ ВЫПОЛНЕНИЯ ЗАДАНИЯ (ИСПРАВЛЕННАЯ) ============
function doTask($chat_id, $user_id, $task_id, $username, $callback_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        sendMessage($chat_id, "❌ Задание не найдено", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    // Проверяем требования по дням
    if (!checkUserDaysRequirement($user_id, $task['requirement_days'])) {
        sendMessage($chat_id, "❌ Для выполнения этого задания нужно быть в проекте минимум " . $task['requirement_days'] . " дней!\n\nТы в проекте: " . getDaysInProject($user_id) . " дней", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    // Проверяем, не выполнил ли уже
    $stmt = $pdo->prepare("SELECT * FROM user_tasks WHERE user_id = ? AND task_id = ?");
    $stmt->execute([$user_id, $task_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        sendMessage($chat_id, "❌ Ты уже выполнил это задание!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    // Проверяем подписку (ТОЛЬКО если есть channel_id)
    if ($task['type'] == 'telegram' && !empty($task['channel_id'])) {
        // Проверяем, может ли бот проверить подписку
        $can_check = canCheckSubscription($task['channel_id']);
        
        if (!$can_check) {
            // Бот не может проверить подписку - отправляем на ручную проверку
            $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status, completed_at) VALUES (?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $task_id]);
            
            $stmt = $pdo->prepare("UPDATE tasks SET completed_count = completed_count + 1 WHERE id = ?");
            $stmt->execute([$task_id]);
            
            sendMessage($chat_id, "⏳ Задание отправлено на проверку администратору!\n\nБот не может автоматически проверить подписку.\nОжидай подтверждения.", mainKeyboard());
            
            // Уведомление админу
            $admin_text = "📋 Задание требует ручной проверки!\n\n";
            $admin_text .= "👤 Пользователь: @" . $username . "\n";
            $admin_text .= "📌 Задание: " . $task['title'] . "\n";
            $admin_text .= "💰 Награда: " . formatRub($task['reward']) . "\n";
            $admin_text .= "📢 Канал: " . $task['channel_id'] . "\n";
            $admin_text .= "⚠️ Бот не является администратором канала!\n";
            $admin_text .= "Проверьте подписку вручную.";
            sendMessage(ADMIN_ID, $admin_text);
            
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            return;
        }
        
        // Бот может проверить - проверяем подписку
        $is_subscribed = checkSubscription($user_id, $task['channel_id']);
        
        if (!$is_subscribed) {
            $channel_link = $task['channel_id'];
            if (strpos($channel_link, '@') === 0) {
                $channel_link = 'https://t.me/' . substr($channel_link, 1);
            } elseif (strpos($channel_link, '-100') === 0) {
                $channel_link = 'https://t.me/c/' . substr($channel_link, 4);
            }
            
            $text = "❌ Ты не подписан на канал!\n\n";
            $text .= "🔗 <a href='" . $channel_link . "'>👉 Подписаться на канал</a>\n\n";
            $text .= "⚠️ Помни: нужно быть подписанным минимум 3 дня!\n\n";
            $text .= "После подписки нажми кнопку ещё раз.";
            
            $inlineKeyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 Проверить подписку', 'callback_data' => 'check_sub_' . $task_id]]
                ]
            ];
            sendMessage($chat_id, $text, $inlineKeyboard);
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            return;
        }
    }
    
    // АВТОПОДТВЕРЖДЕНИЕ - сразу начисляем
    $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status, completed_at) VALUES (?, ?, 'completed', NOW())");
    $stmt->execute([$user_id, $task_id]);
    
    $stmt = $pdo->prepare("UPDATE tasks SET completed_count = completed_count + 1 WHERE id = ?");
    $stmt->execute([$task_id]);
    
    // Начисляем награду
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$task['reward'], $user_id]);
    
    $desc = 'Выполнение задания: ' . $task['title'];
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'task', ?, NOW())");
    $stmt->execute([$user_id, $task['reward'], $desc]);
    
    // Реферальный бонус
    $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $ref_id = $stmt->fetchColumn();
    
    if ($ref_id > 0) {
        $ref_bonus = $task['reward'] * 0.25;
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$ref_bonus, $ref_id]);
        
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'ref_bonus', 'Бонус за реферала (25%)', NOW())");
        $stmt->execute([$ref_id, $ref_bonus]);
        
        $stmt = $pdo->prepare("INSERT INTO referrals (user_id, ref_user_id, income, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$ref_id, $user_id, $ref_bonus]);
    }
    
    $text = "✅ <b>Задание выполнено!</b>\n\n";
    $text .= "💰 Начислено: <b>" . formatRub($task['reward']) . "</b>\n";
    if ($ref_id > 0) {
        $text .= "👥 Реферальный бонус: <b>" . formatRub($ref_bonus) . "</b>\n";
    }
    $text .= "\n💵 Твой баланс обновлён!";
    sendMessage($chat_id, $text, mainKeyboard());
    
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

function checkSubscriptionCallback($chat_id, $user_id, $task_id, $callback_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $username = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        sendMessage($chat_id, "❌ Задание не найдено", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    // Проверяем, может ли бот проверить подписку
    $can_check = canCheckSubscription($task['channel_id']);
    
    if (!$can_check) {
        // Бот не может проверить - отправляем на ручную проверку
        $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status, completed_at) VALUES (?, ?, 'pending', NOW())");
        $stmt->execute([$user_id, $task_id]);
        
        $stmt = $pdo->prepare("UPDATE tasks SET completed_count = completed_count + 1 WHERE id = ?");
        $stmt->execute([$task_id]);
        
        sendMessage($chat_id, "⏳ Задание отправлено на проверку администратору!\n\nБот не может автоматически проверить подписку.\nОжидай подтверждения.", mainKeyboard());
        
        $admin_text = "📋 Задание требует ручной проверки!\n\n";
        $admin_text .= "👤 Пользователь: @" . $username . "\n";
        $admin_text .= "📌 Задание: " . $task['title'] . "\n";
        $admin_text .= "💰 Награда: " . formatRub($task['reward']) . "\n";
        $admin_text .= "📢 Канал: " . $task['channel_id'] . "\n";
        $admin_text .= "⚠️ Бот не является администратором канала!\n";
        $admin_text .= "Проверьте подписку вручную.";
        sendMessage(ADMIN_ID, $admin_text);
        
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $is_subscribed = checkSubscription($user_id, $task['channel_id']);
    
    if ($is_subscribed) {
        // Проверяем требования по дням
        if (!checkUserDaysRequirement($user_id, $task['requirement_days'])) {
            sendMessage($chat_id, "❌ Нужно быть в проекте минимум " . $task['requirement_days'] . " дней!\n\nТы в проекте: " . getDaysInProject($user_id) . " дней", mainKeyboard());
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            return;
        }
        
        // АВТОПОДТВЕРЖДЕНИЕ
        $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status, completed_at) VALUES (?, ?, 'completed', NOW())");
        $stmt->execute([$user_id, $task_id]);
        
        $stmt = $pdo->prepare("UPDATE tasks SET completed_count = completed_count + 1 WHERE id = ?");
        $stmt->execute([$task_id]);
        
        // Начисляем награду
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$task['reward'], $user_id]);
        
        $desc = 'Выполнение задания: ' . $task['title'];
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'task', ?, NOW())");
        $stmt->execute([$user_id, $task['reward'], $desc]);
        
        // Реферальный бонус
        $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $ref_id = $stmt->fetchColumn();
        
        if ($ref_id > 0) {
            $ref_bonus = $task['reward'] * 0.25;
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$ref_bonus, $ref_id]);
            
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'ref_bonus', 'Бонус за реферала (25%)', NOW())");
            $stmt->execute([$ref_id, $ref_bonus]);
            
            $stmt = $pdo->prepare("INSERT INTO referrals (user_id, ref_user_id, income, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$ref_id, $user_id, $ref_bonus]);
        }
        
        $text = "✅ <b>Подписка подтверждена! Задание выполнено!</b>\n\n";
        $text .= "💰 Начислено: <b>" . formatRub($task['reward']) . "</b>\n";
        if ($ref_id > 0) {
            $text .= "👥 Реферальный бонус: <b>" . formatRub($ref_bonus) . "</b>\n";
        }
        $text .= "\n💵 Твой баланс обновлён!";
        sendMessage($chat_id, $text, mainKeyboard());
    } else {
        $channel_link = $task['channel_id'];
        if (strpos($channel_link, '@') === 0) {
            $channel_link = 'https://t.me/' . substr($channel_link, 1);
        } elseif (strpos($channel_link, '-100') === 0) {
            $channel_link = 'https://t.me/c/' . substr($channel_link, 4);
        }
        
        $text = "❌ Ты всё ещё не подписан на канал!\n\n";
        $text .= "🔗 <a href='" . $channel_link . "'>👉 Подписаться на канал</a>\n\n";
        $text .= "⚠️ Помни: нужно быть подписанным минимум 3 дня!\n\n";
        $text .= "После подписки нажми кнопку ещё раз.";
        
        $inlineKeyboard = [
            'inline_keyboard' => [
                [['text' => '🔄 Проверить подписку', 'callback_data' => 'check_sub_' . $task_id]]
            ]
        ];
        sendMessage($chat_id, $text, $inlineKeyboard);
    }
    
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

function getDaysInProject($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() ?: 0;
}

// ============ ФУНКЦИИ ДЛЯ ВЫВОДА ============

function showWithdrawMenu($chat_id, $user_id) {
    global $pdo;
    
    if (hasActiveWithdraw($user_id)) {
        sendMessage($chat_id, "⚠️ У тебя уже есть активная заявка на вывод!\n\nОжидай её обработки.", mainKeyboard());
        return;
    }
    
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $balance = $stmt->fetchColumn();
    
    if ($balance < MIN_WITHDRAW_RUB) {
        sendMessage($chat_id, "❌ Минимальная сумма для вывода: " . formatRub(MIN_WITHDRAW_RUB) . "\n\nТвой баланс: " . formatRub($balance), mainKeyboard());
        return;
    }
    
    $text = "💳 <b>Вывод средств</b>\n\n";
    $text .= "💰 Твой баланс: <b>" . formatRub($balance) . "</b>\n";
    $text .= "💶 ≈ " . rubToEur($balance) . " €\n\n";
    $text .= "Выбери способ вывода:\n";
    $text .= "💰 Будет выведена <b>ВСЯ СУММА</b> с баланса!\n\n";
    $text .= "📝 После выбора способа напиши реквизиты:\n";
    $text .= "• Для крипто: адрес кошелька USDT TRC20\n";
    $text .= "• Для банка: номер карты и ФИО владельца";
    
    $inlineKeyboard = [
        'inline_keyboard' => [
            [['text' => '💎 Криптокошелёк (USDT TRC20)', 'callback_data' => 'withdraw_crypto']],
            [['text' => '🏦 Банковский счёт / Карта', 'callback_data' => 'withdraw_bank']],
            [['text' => '❌ Отмена', 'callback_data' => 'withdraw_cancel']]
        ]
    ];
    
    sendMessage($chat_id, $text, $inlineKeyboard);
}

function processWithdraw($chat_id, $user_id, $data, $callback_id) {
    global $pdo;
    
    if (hasActiveWithdraw($user_id)) {
        sendMessage($chat_id, "⚠️ У тебя уже есть активная заявка на вывод!\n\nОжидай её обработки.", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT balance, username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user['balance'] < MIN_WITHDRAW_RUB) {
        sendMessage($chat_id, "❌ Минимальная сумма для вывода: " . formatRub(MIN_WITHDRAW_RUB) . "\n\nТвой баланс: " . formatRub($user['balance']), mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $method = $data == 'withdraw_crypto' ? 'crypto' : 'bank';
    $method_name = $data == 'withdraw_crypto' ? '💎 Криптокошелёк (USDT TRC20)' : '🏦 Банковский счёт';
    $amount = $user['balance'];
    
    $stmt = $pdo->prepare("UPDATE users SET last_withdraw_method = ? WHERE id = ?");
    $stmt->execute([$method, $user_id]);
    
    $text = "💳 <b>Вывод средств - " . $method_name . "</b>\n\n";
    $text .= "💰 Сумма вывода: <b>" . formatRub($amount) . "</b>\n";
    $text .= "💶 ≈ " . rubToEur($amount) . " €\n\n";
    $text .= "📝 <b>Напиши реквизиты для вывода:</b>\n";
    if ($method == 'crypto') {
        $text .= "💎 Адрес USDT TRC20 кошелька\n";
    } else {
        $text .= "💳 Номер карты и ФИО владельца\n";
    }
    $text .= "\n✏️ Просто отправь сообщение с реквизитами\n\n";
    $text .= "ℹ️ Если информации будет недостаточно,\n";
    $text .= "администрация свяжется с тобой в ближайшие два рабочих дня.";
    
    sendMessage($chat_id, $text, mainKeyboard());
    
    $stmt = $pdo->prepare("UPDATE users SET withdraw_waiting_text = 'yes' WHERE id = ?");
    $stmt->execute([$user_id]);
    
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

function handleWithdrawText($chat_id, $text) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id, balance, username, last_withdraw_method, withdraw_waiting_text FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendMessage($chat_id, "❌ Пользователь не найден. Напиши /start");
        return;
    }
    
    if ($user['withdraw_waiting_text'] != 'yes') {
        return;
    }
    
    if (hasActiveWithdraw($user['id'])) {
        sendMessage($chat_id, "⚠️ У тебя уже есть активная заявка на вывод!\n\nОжидай её обработки.", mainKeyboard());
        $stmt = $pdo->prepare("UPDATE users SET withdraw_waiting_text = NULL, last_withdraw_method = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
        return;
    }
    
    $method = $user['last_withdraw_method'];
    $amount = $user['balance'];
    $details = trim($text);
    
    if (empty($details) || strlen($details) < 3) {
        sendMessage($chat_id, "❌ Слишком короткое сообщение!\n\nНапиши реквизиты подробнее.", mainKeyboard());
        return;
    }
    
    // Создаём заявку
    $method_db = $method;
    $stmt = $pdo->prepare("INSERT INTO withdraws (user_id, amount, method, details, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$user['id'], $amount, $method_db, $details]);
    $withdraw_id = $pdo->lastInsertId();
    
    // Списываем сумму
    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$amount, $user['id']]);
    
    $desc = 'Вывод средств #' . $withdraw_id;
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'withdraw', ?, NOW())");
    $stmt->execute([$user['id'], -$amount, $desc]);
    
    $stmt = $pdo->prepare("UPDATE users SET last_withdraw_method = NULL, withdraw_waiting_text = NULL WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    $method_name = $method == 'crypto' ? '💎 Криптокошелёк (USDT TRC20)' : '🏦 Банковский счёт';
    
    $text = "✅ <b>Заявка на вывод отправлена!</b>\n\n";
    $text .= "💰 Сумма: <b>" . formatRub($amount) . "</b>\n";
    $text .= "💶 ≈ " . rubToEur($amount) . " €\n";
    $text .= "🏦 Способ: " . $method_name . "\n";
    $text .= "📋 Номер заявки: #" . $withdraw_id . "\n\n";
    $text .= "⏳ Статус: <b>Ожидает подтверждения</b>\n\n";
    $text .= "ℹ️ Если информации недостаточно,\n";
    $text .= "администрация свяжется с тобой в ближайшие два рабочих дня.";
    
    sendMessage($chat_id, $text, mainKeyboard());
    
    // Уведомление админу
    $admin_text = "💳 <b>НОВАЯ ЗАЯВКА НА ВЫВОД!</b>\n\n";
    $admin_text .= "👤 Пользователь: @" . $user['username'] . "\n";
    $admin_text .= "💰 Сумма: " . formatRub($amount) . " (" . rubToEur($amount) . " €)\n";
    $admin_text .= "🏦 Способ: " . $method_name . "\n";
    $admin_text .= "📝 Реквизиты: " . $details . "\n";
    $admin_text .= "📋 Заявка #" . $withdraw_id . "\n";
    $admin_text .= "📅 " . date('d.m.Y H:i:s');
    
    sendMessage(ADMIN_ID, $admin_text);
}

// ============ ОСНОВНОЙ ЦИКЛ ============
echo "🤖 Бот ArtaWork запущен!\n";
echo "Нажми Ctrl+C для остановки\n\n";

$last_update_id = 0;

while (true) {
    try {
        $updates = botRequest('getUpdates', [
            'offset' => $last_update_id + 1,
            'timeout' => 30
        ]);
        
        if (isset($updates['result']) && count($updates['result']) > 0) {
            foreach ($updates['result'] as $update) {
                $last_update_id = $update['update_id'];
                processUpdate($update);
                
                if (isset($update['message'])) {
                    $message = $update['message'];
                    $chat_id = $message['chat']['id'];
                    $text = $message['text'] ?? '';
                    
                    $stmt = $pdo->prepare("SELECT withdraw_waiting_text FROM users WHERE telegram_id = ?");
                    $stmt->execute([$chat_id]);
                    $waiting = $stmt->fetchColumn();
                    
                    if ($waiting == 'yes' && $text && $text != '/start') {
                        handleWithdrawText($chat_id, $text);
                    }
                }
                
                echo "✅ Обработано обновление #" . $update['update_id'] . "\n";
            }
        }
    } catch (Exception $e) {
        echo "❌ Ошибка: " . $e->getMessage() . "\n";
        sleep(5);
    }
    
    sleep(1);
}
?>