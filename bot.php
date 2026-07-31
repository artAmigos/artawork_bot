<?php
require_once 'config.php';

// Проверка соединения с БД (используем функцию из config.php)
function isDBConnected() {
    global $pdo;
    try {
        $pdo->query("SELECT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Обработка входящих обновлений
function processUpdate($update) {
    global $pdo;
    
    // Проверяем соединение с БД перед каждым обновлением
    if (!isDBConnected()) {
        reconnectDB();
    }
    
    // Обработка сообщений
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $username = $message['from']['username'] ?? 'user';
        
        // Регистрируем пользователя
        $user_id = registerUser($chat_id, $username);
        
        // ============================================
        // === КОМАНДА ДЛЯ МАССОВОЙ РАССЫЛКИ (ТОЛЬКО ДЛЯ АДМИНА) ===
        // ============================================
        if (strpos($text, '/mail') === 0 && $chat_id == ADMIN_ID) {
            $mail_text = trim(substr($text, 5));
            
            if (empty($mail_text)) {
                sendMessage($chat_id, "❌ <b>Укажите текст для рассылки!</b>\n\nПример:\n<code>/mail Привет всем! 🚀</code>", mainKeyboard());
                return;
            }
            
            // Проверяем, есть ли параметр типа получателей
            $message_type = 'all';
            $lines = explode("\n", $mail_text);
            $first_line = $lines[0] ?? '';
            
            if (strpos($first_line, '[active]') !== false) {
                $message_type = 'active';
                $mail_text = str_replace('[active]', '', $mail_text);
                $mail_text = trim($mail_text);
            } elseif (strpos($first_line, '[new]') !== false) {
                $message_type = 'new';
                $mail_text = str_replace('[new]', '', $mail_text);
                $mail_text = trim($mail_text);
            }
            
            // Подтверждение перед отправкой
            if (!isDBConnected()) reconnectDB();
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $total_users = $stmt->fetchColumn();
            
            if ($total_users == 0) {
                sendMessage($chat_id, "❌ Нет пользователей для рассылки!", mainKeyboard());
                return;
            }
            
            $type_label = $message_type == 'all' ? 'ВСЕМ' : ($message_type == 'active' ? 'АКТИВНЫМ (≥5 заданий)' : 'НОВЫМ (≥1 задание)');
            
            $confirm_text = "📨 <b>Массовая рассылка</b>\n\n";
            $confirm_text .= "👥 Получатели: <b>{$type_label}</b>\n";
            $confirm_text .= "📊 Всего: <b>{$total_users}</b> пользователей\n\n";
            $confirm_text .= "📝 <b>Текст сообщения:</b>\n";
            $confirm_text .= "━━━━━━━━━━━━━━━━━━━\n";
            $confirm_text .= $mail_text . "\n";
            $confirm_text .= "━━━━━━━━━━━━━━━━━━━\n\n";
            $confirm_text .= "⚠️ <b>Отправить рассылку?</b>\n";
            $confirm_text .= "Нажмите кнопку ниже для подтверждения.";
            
            $GLOBALS['pending_mailing'] = [
                'chat_id' => $chat_id,
                'text' => $mail_text,
                'type' => $message_type,
                'total' => $total_users
            ];
            
            $inlineKeyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Да, отправить всем', 'callback_data' => 'mailing_confirm']],
                    [['text' => '❌ Отмена', 'callback_data' => 'mailing_cancel']]
                ]
            ];
            
            sendMessage($chat_id, $confirm_text, $inlineKeyboard);
            return;
        }
        
        // Проверяем реферальную ссылку при старте
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            if (isset($parts[1])) {
                if (strpos($parts[1], 'ref_') === 0) {
                    $ref_id = (int)str_replace('ref_', '', $parts[1]);
                    if (!isDBConnected()) reconnectDB();
                    $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $current_ref = $stmt->fetchColumn();
                    if ($current_ref == 0 && $ref_id > 0 && $ref_id != $user_id) {
                        $stmt = $pdo->prepare("UPDATE users SET ref_id = ? WHERE id = ?");
                        $stmt->execute([$ref_id, $user_id]);
                    }
                }
                if (strpos($parts[1], 'invite_') === 0) {
                    $code = str_replace('invite_', '', $parts[1]);
                    processInviteTransfer($chat_id, $user_id, $code);
                }
            }
            
            $welcome = "👋 Добро пожаловать в ArtaWork!\n\n";
            $welcome .= "Здесь ты можешь зарабатывать, выполняя простые задания.\n\n";
            $welcome .= "📌 Нажми на кнопку «📋 Задания», чтобы начать зарабатывать!\n";
            $welcome .= "💰 Минимальная сумма вывода: " . formatRub(MIN_WITHDRAW_RUB) . " (≈" . rubToEur(MIN_WITHDRAW_RUB) . " €)";
            sendMessage($chat_id, $welcome, mainKeyboard());
        }
        // === НОВЫЕ КОМАНДЫ ===
        elseif ($text == '🏖️ Отпуск') {
            handleVacation($chat_id, $user_id);
        }
        elseif ($text == '🏆 Дуэли') {
            handleDuels($chat_id, $user_id);
        }
        elseif ($text == '🔥 Стрик') {
            handleStreak($chat_id, $user_id);
        }
        elseif ($text == '🎯 Квесты') {
            handleQuests($chat_id, $user_id);
        }
        elseif ($text == '🏆 Топ') {
            handleTop($chat_id, $user_id);
        }
        elseif ($text == '🎲 Кейсы') {
            handleCases($chat_id, $user_id);
        }
        elseif ($text == '💸 Перевод') {
            handleTransfer($chat_id, $user_id);
        }
        // === СТАРЫЕ КОМАНДЫ ===
        elseif ($text == '💰 Баланс') {
            checkAndCompleteQuest($user_id, 'check_balance');
            if (!isDBConnected()) reconnectDB();
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE telegram_id = ?");
            $stmt->execute([$chat_id]);
            $balance = $stmt->fetchColumn();
            $text = "💰 <b>Твой баланс</b>\n\n💵 <b>" . formatRub($balance) . "</b>\n💶 ≈ " . rubToEur($balance) . " €\n\n📊 Минимальный вывод: " . formatRub(MIN_WITHDRAW_RUB) . " (≈" . rubToEur(MIN_WITHDRAW_RUB) . " €)";
            sendMessage($chat_id, $text, mainKeyboard());
        }
        elseif ($text == '📋 Задания') {
            checkAndCompleteQuest($user_id, 'first_step');
            showTasks($chat_id, $user_id);
        }
        elseif ($text == '🎁 Бонус дня') {
            checkAndCompleteQuest($user_id, 'take_bonus');
            handleDailyBonus($chat_id, $user_id);
        }
        elseif ($text == '👥 Рефералы') {
            checkAndCompleteQuest($user_id, 'check_refs');
            showReferrals($chat_id, $user_id);
        }
        elseif ($text == '💳 Вывод') {
            checkAndCompleteQuest($user_id, 'check_withdraw');
            showWithdrawMenu($chat_id, $user_id);
        }
        elseif ($text == '📊 Мои выводы') {
            checkAndCompleteQuest($user_id, 'check_my_withdraws');
            showMyWithdraws($chat_id, $user_id);
        }
        elseif ($text == '👤 Профиль') {
            checkAndCompleteQuest($user_id, 'check_profile');
            showProfile($chat_id, $user_id);
        }
        elseif ($text == '❓ Помощь') {
            checkAndCompleteQuest($user_id, 'ask_help');
            showHelp($chat_id);
        }
        else {
            if (!isDBConnected()) reconnectDB();
            $stmt = $pdo->prepare("SELECT withdraw_waiting_text FROM users WHERE telegram_id = ?");
            $stmt->execute([$chat_id]);
            $waiting = $stmt->fetchColumn();
            if ($waiting == 'yes') {
                handleWithdrawText($chat_id, $text);
                return;
            }
        }
    }
    
    // Обработка callback запросов
    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $chat_id = $callback['from']['id'];
        $data = $callback['data'];
        $message_id = $callback['message']['message_id'];
        $username = $callback['from']['username'] ?? 'user';
        
        if (!isDBConnected()) reconnectDB();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
        $stmt->execute([$chat_id]);
        $user_id = $stmt->fetchColumn();
        
        if (!$user_id) {
            sendMessage($chat_id, "❌ Сначала запусти бота командой /start");
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
            return;
        }
        
        // === ПОДТВЕРЖДЕНИЕ РАССЫЛКИ ===
        if ($data == 'mailing_confirm') {
            if ($chat_id != ADMIN_ID) {
                sendMessage($chat_id, "❌ У вас нет прав для этой операции!", mainKeyboard());
                botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
                return;
            }
            
            $mail_data = $GLOBALS['pending_mailing'] ?? null;
            
            if (!$mail_data || $mail_data['chat_id'] != $chat_id) {
                sendMessage($chat_id, "❌ Данные рассылки устарели. Отправьте команду заново.", mainKeyboard());
                botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
                return;
            }
            
            $mail_text = $mail_data['text'];
            $message_type = $mail_data['type'];
            
            if (!isDBConnected()) reconnectDB();
            if ($message_type == 'all') {
                $stmt = $pdo->query("SELECT telegram_id, username FROM users");
            } elseif ($message_type == 'active') {
                $stmt = $pdo->prepare("SELECT telegram_id, username FROM users WHERE id IN (SELECT user_id FROM user_tasks WHERE status = 'completed' GROUP BY user_id HAVING COUNT(*) >= 5)");
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare("SELECT telegram_id, username FROM users WHERE id IN (SELECT user_id FROM user_tasks WHERE status = 'completed' GROUP BY user_id HAVING COUNT(*) >= 1)");
                $stmt->execute();
            }
            
            $users = $stmt->fetchAll();
            $total = count($users);
            $sent = 0;
            $failed = 0;
            $failed_list = [];
            
            sendMessage($chat_id, "📨 <b>Начинаю рассылку...</b>\n\n👥 Всего пользователей: {$total}\n⏳ Отправка может занять некоторое время.", mainKeyboard());
            
            foreach ($users as $user) {
                $result = sendMessage($user['telegram_id'], $mail_text, mainKeyboard());
                
                if (isset($result['ok']) && $result['ok'] === true) {
                    $sent++;
                } else {
                    $failed++;
                    $failed_list[] = '@' . $user['username'];
                }
                usleep(100000);
            }
            
            $result_text = "📨 <b>Рассылка завершена!</b>\n\n";
            $result_text .= "✅ Отправлено: <b>{$sent}</b>\n";
            $result_text .= "❌ Ошибок: <b>{$failed}</b>\n";
            
            if (!empty($failed_list)) {
                $result_text .= "\n❌ Не доставлено:\n" . implode(', ', array_slice($failed_list, 0, 20));
                if (count($failed_list) > 20) {
                    $result_text .= "\n...и еще " . (count($failed_list) - 20) . " пользователей";
                }
            }
            
            sendMessage($chat_id, $result_text, mainKeyboard());
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
            unset($GLOBALS['pending_mailing']);
            return;
        }
        
        if ($data == 'mailing_cancel') {
            sendMessage($chat_id, "❌ Рассылка отменена.", mainKeyboard());
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
            unset($GLOBALS['pending_mailing']);
            return;
        }
        
        // === ОБРАБОТКА ОСТАЛЬНЫХ CALLBACK ===
        if (strpos($data, 'task_detail_') === 0) {
            $task_id = str_replace('task_detail_', '', $data);
            showTaskDetail($chat_id, $user_id, $task_id, $message_id, $callback['id']);
        }
        if (strpos($data, 'task_do_') === 0) {
            $task_id = str_replace('task_do_', '', $data);
            doTask($chat_id, $user_id, $task_id, $username, $callback['id']);
        }
        if (strpos($data, 'check_sub_') === 0) {
            $task_id = str_replace('check_sub_', '', $data);
            checkSubscriptionCallback($chat_id, $user_id, $task_id, $callback['id']);
        }
        if ($data == 'refresh_tasks') {
            showTasksInline($chat_id, $user_id, $message_id, $callback['id']);
        }
        
        if ($data == 'withdraw_crypto' || $data == 'withdraw_bank') {
            processWithdraw($chat_id, $user_id, $data, $callback['id']);
        }
        if ($data == 'withdraw_cancel') {
            sendMessage($chat_id, "❌ Вывод отменён.", mainKeyboard());
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }
        
        if (strpos($data, 'duel_bet_') === 0) {
            $bet = (int)str_replace('duel_bet_', '', $data);
            createDuel($chat_id, $user_id, $bet, $callback['id']);
        }
        if (strpos($data, 'duel_join_') === 0) {
            $duel_id = (int)str_replace('duel_join_', '', $data);
            joinDuel($chat_id, $user_id, $duel_id, $callback['id']);
        }
        if ($data == 'duel_refresh') {
            handleDuels($chat_id, $user_id);
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }
        
        if (strpos($data, 'case_open_') === 0) {
            $case_id = (int)str_replace('case_open_', '', $data);
            openCase($chat_id, $user_id, $case_id, $callback['id']);
        }
        if ($data == 'cases_refresh') {
            handleCases($chat_id, $user_id);
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }
        
        if ($data == 'vacation_confirm') {
            confirmVacation($chat_id, $user_id, $callback['id']);
        }
        if ($data == 'vacation_cancel') {
            sendMessage($chat_id, "❌ Отпуск отменён.", mainKeyboard());
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        }
        
        if ($data == 'invite_transfer') {
            createInviteTransfer($chat_id, $user_id, $callback['id']);
        }
        if (strpos($data, 'invite_amount_') === 0) {
            $amount = (int)str_replace('invite_amount_', '', $data);
            createInviteTransferWithAmount($chat_id, $user_id, $amount, $callback['id']);
        }
    }
}

// ============================================
// 1. 🏖️ ОТПУСК
// ============================================
function handleVacation($chat_id, $user_id) {
    global $pdo;
    
    checkAndCompleteQuest($user_id, 'check_vacation');
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT vacation_used_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $last_vacation = $stmt->fetchColumn();
    
    if ($last_vacation && strtotime($last_vacation) > strtotime('-14 days')) {
        $next_available = date('d.m.Y', strtotime($last_vacation . ' +14 days'));
        sendMessage($chat_id, "🏖️ Ты уже брал отпуск!\n\nСледующий отпуск доступен: <b>$next_available</b>", mainKeyboard());
        return;
    }
    
    $tomorrow = date('d.m.Y', strtotime('+1 day'));
    
    $text = "🏖️ <b>Подтверждение отпуска</b>\n\n";
    $text .= "Ты собираешься взять отпуск на <b>$tomorrow</b>.\n";
    $text .= "Этот день не будет считаться пропущенным для стрика.\n\n";
    $text .= "❓ <b>Подтверждаешь?</b>";
    
    $inlineKeyboard = [
        'inline_keyboard' => [
            [['text' => '✅ Да, подтверждаю', 'callback_data' => 'vacation_confirm']],
            [['text' => '❌ Нет, отмена', 'callback_data' => 'vacation_cancel']]
        ]
    ];
    
    sendMessage($chat_id, $text, $inlineKeyboard);
}

function confirmVacation($chat_id, $user_id, $callback_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT vacation_used_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $last_vacation = $stmt->fetchColumn();
    
    if ($last_vacation && strtotime($last_vacation) > strtotime('-14 days')) {
        sendMessage($chat_id, "❌ Отпуск уже был использован недавно!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE users SET vacation_used_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d'), $user_id]);
    
    $desc = 'Отпуск на ' . date('Y-m-d', strtotime('+1 day'));
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, 0, 'vacation', ?, NOW())");
    $stmt->execute([$user_id, $desc]);
    
    sendMessage($chat_id, "🏖️ <b>Отпуск оформлен!</b>\n\nТы взял отпуск на <b>" . date('d.m.Y', strtotime('+1 day')) . "</b>\n\n✅ Завтрашний день не будет считаться пропущенным!", mainKeyboard());
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

// ============================================
// 2. 🎁 БОНУС ДНЯ 2.0
// ============================================
function handleDailyBonus($chat_id, $user_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT * FROM daily_bonuses WHERE user_id = ? AND bonus_date = CURDATE()");
    $stmt->execute([$user_id]);
    $bonus_today = $stmt->fetch();
    
    if ($bonus_today) {
        sendMessage($chat_id, "🎁 Ты уже получил бонус сегодня! Возвращайся завтра.", mainKeyboard());
        return;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE ref_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $stmt->execute([$user_id]);
    $new_refs = $stmt->fetchColumn();
    
    if ($new_refs == 0) {
        sendMessage($chat_id, "🎁 <b>Бонус дня!</b>\n\nПригласи 1 нового реферала за сутки, чтобы получить <b>50 ₽</b>!\n\n👥 Твоя реферальная ссылка:\n<code>https://t.me/" . BOT_USERNAME . "?start=ref_" . $user_id . "</code>", mainKeyboard());
        return;
    }
    
    $bonus = 50;
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$bonus, $user_id]);
    
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'daily_bonus', 'Бонус дня (пригласил реферала)', NOW())");
    $stmt->execute([$user_id, $bonus]);
    
    $stmt = $pdo->prepare("INSERT INTO daily_bonuses (user_id, bonus_date, amount) VALUES (?, CURDATE(), ?)");
    $stmt->execute([$user_id, $bonus]);
    
    sendMessage($chat_id, "🎁 <b>Бонус дня получен!</b>\n\nТы пригласил $new_refs новых рефералов за сутки!\n💰 Начислено: <b>" . formatRub($bonus) . "</b>", mainKeyboard());
}

// ============================================
// 3. 📨 ПЕРЕСЛАТЬ ДЕНЬГИ (INVITE TRANSFER)
// ============================================
function handleTransfer($chat_id, $user_id) {
    global $pdo;
    
    $text = "💸 <b>Перевод средств через пересылку</b>\n\n";
    $text .= "💰 Твой баланс: " . formatRub(getUserBalance($user_id)) . "\n";
    $text .= "📊 Лимиты:\n";
    $text .= "• Переводов в день: " . INVITE_TRANSFER_DAILY_LIMIT . "\n";
    $text .= "• Комиссия: 2%\n";
    $text .= "• Срок действия: " . INVITE_TRANSFER_EXPIRE . " часов\n\n";
    $text .= "📨 Ты создаёшь ссылку-приглашение с суммой.\n";
    $text .= "Перешли её любому пользователю Telegram.\n";
    $text .= "Он получит деньги после регистрации в боте!\n\n";
    $text .= "💰 Выбери сумму:";
    
    $inlineKeyboard = [
        'inline_keyboard' => [
            [['text' => '100 ₽', 'callback_data' => 'invite_amount_100']],
            [['text' => '200 ₽', 'callback_data' => 'invite_amount_200']],
            [['text' => '500 ₽', 'callback_data' => 'invite_amount_500']],
            [['text' => '1000 ₽', 'callback_data' => 'invite_amount_1000']],
            [['text' => '❌ Отмена', 'callback_data' => 'withdraw_cancel']]
        ]
    ];
    
    sendMessage($chat_id, $text, $inlineKeyboard);
}

function createInviteTransfer($chat_id, $user_id, $callback_id) {
    global $pdo;
    
    $text = "📨 <b>Переслать деньги</b>\n\n";
    $text .= "Ты можешь отправить деньги любому пользователю Telegram через пересылку сообщения.\n";
    $text .= "Если пользователь не зарегистрирован — он получит деньги после регистрации.\n\n";
    $text .= "💰 Выбери сумму перевода:";
    
    $inlineKeyboard = [
        'inline_keyboard' => [
            [['text' => '100 ₽', 'callback_data' => 'invite_amount_100']],
            [['text' => '200 ₽', 'callback_data' => 'invite_amount_200']],
            [['text' => '500 ₽', 'callback_data' => 'invite_amount_500']],
            [['text' => '1000 ₽', 'callback_data' => 'invite_amount_1000']],
            [['text' => '❌ Отмена', 'callback_data' => 'withdraw_cancel']]
        ]
    ];
    
    sendMessage($chat_id, $text, $inlineKeyboard);
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

function createInviteTransferWithAmount($chat_id, $user_id, $amount, $callback_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invite_transfers WHERE sender_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$user_id]);
    $today_count = $stmt->fetchColumn();
    
    if ($today_count >= INVITE_TRANSFER_DAILY_LIMIT) {
        sendMessage($chat_id, "❌ Ты исчерпал лимит пригласительных переводов на сегодня (макс. " . INVITE_TRANSFER_DAILY_LIMIT . ")", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $balance = getUserBalance($user_id);
    $fee = $amount * INVITE_TRANSFER_FEE;
    $total_with_fee = $amount + $fee;
    
    if ($balance < $total_with_fee) {
        sendMessage($chat_id, "❌ Недостаточно средств!\n\n💰 Твой баланс: " . formatRub($balance) . "\n📊 Нужно: " . formatRub($total_with_fee) . " (включая комиссию)", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $sender_username = $stmt->fetchColumn();
    
    $code = strtoupper(bin2hex(random_bytes(4)));
    
    $stmt = $pdo->prepare("INSERT INTO invite_transfers (sender_id, amount, code, status, created_at, expires_at) VALUES (?, ?, ?, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR))");
    $stmt->execute([$user_id, $amount, $code, INVITE_TRANSFER_EXPIRE]);
    $transfer_id = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$total_with_fee, $user_id]);
    
    $desc = 'Invite Transfer (комиссия ' . formatRub($fee) . ')';
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'invite_transfer', ?, NOW())");
    $stmt->execute([$user_id, -$total_with_fee, $desc]);
    
    $text = "📨 <b>Пригласительный перевод создан!</b>\n\n";
    $text .= "💰 Сумма: <b>" . formatRub($amount) . "</b>\n";
    $text .= "📊 Комиссия: <b>" . formatRub($fee) . "</b>\n";
    $text .= "⏳ Действует: " . INVITE_TRANSFER_EXPIRE . " часов\n\n";
    $text .= "📤 <b>Перешли это сообщение тому, кому хочешь отправить деньги:</b>\n\n";
    $text .= "💰 <b>Перевод от @" . $sender_username . "</b>\n";
    $text .= "Сумма: <b>" . formatRub($amount) . "</b>\n";
    $text .= "Код: <code>" . $code . "</code>\n\n";
    $text .= "🔗 <a href='https://t.me/" . BOT_USERNAME . "?start=invite_" . $code . "'>👉 Нажми сюда, чтобы получить перевод</a>";
    
    sendMessage($chat_id, $text, mainKeyboard());
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

function processInviteTransfer($chat_id, $user_id, $code) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT * FROM invite_transfers WHERE code = ? AND status = 'pending' AND expires_at > NOW()");
    $stmt->execute([$code]);
    $transfer = $stmt->fetch();
    
    if (!$transfer) {
        sendMessage($chat_id, "❌ Код недействителен или истек срок действия!", mainKeyboard());
        return;
    }
    
    if ($transfer['sender_id'] == $user_id) {
        sendMessage($chat_id, "❌ Ты не можешь получить свой собственный перевод!", mainKeyboard());
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$transfer['amount'], $user_id]);
    
    $stmt = $pdo->prepare("UPDATE invite_transfers SET status = 'completed' WHERE id = ?");
    $stmt->execute([$transfer['id']]);
    
    $desc = 'Получен Invite Transfer от #' . $transfer['sender_id'];
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'invite_transfer_receive', ?, NOW())");
    $stmt->execute([$user_id, $transfer['amount'], $desc]);
    
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $receiver_username = $stmt->fetchColumn();
    
    sendMessage($transfer['sender_id'], "✅ Пользователь @" . $receiver_username . " получил твой перевод в размере " . formatRub($transfer['amount']) . "!", mainKeyboard());
    
    sendMessage($chat_id, "✅ <b>Перевод получен!</b>\n\n💰 Начислено: <b>" . formatRub($transfer['amount']) . "</b>\n📨 Отправитель: #" . $transfer['sender_id'], mainKeyboard());
}

// ============================================
// 4. 🥊 ДУЭЛИ
// ============================================
function handleDuels($chat_id, $user_id) {
    global $pdo;
    
    checkAndCompleteQuest($user_id, 'check_duels');
    
    if (!checkDuelRequirements($user_id)) {
        $text = "🏆 <b>Дуэли</b>\n\n❌ Ты не соответствуешь требованиям для участия в дуэлях:\n";
        $text .= "• Минимум 7 дней в проекте\n";
        $text .= "• Минимум 10 выполненных заданий\n";
        $text .= "• Минимум 3 реферала\n\n";
        $text .= "Продолжай выполнять задания и приглашать рефералов!";
        sendMessage($chat_id, $text, mainKeyboard());
        return;
    }
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM duels WHERE (user1_id = ? OR user2_id = ?) AND status = 'active'");
    $stmt->execute([$user_id, $user_id]);
    $active_duels = $stmt->fetchColumn();
    
    if ($active_duels >= DUEL_ACTIVE_LIMIT) {
        sendMessage($chat_id, "❌ У тебя уже " . DUEL_ACTIVE_LIMIT . " активные дуэли! Дождись их завершения.", mainKeyboard());
        return;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM duels WHERE (user1_id = ? OR user2_id = ?) AND DATE(started_at) = CURDATE()");
    $stmt->execute([$user_id, $user_id]);
    $daily_duels = $stmt->fetchColumn();
    
    if ($daily_duels >= DUEL_DAILY_LIMIT) {
        sendMessage($chat_id, "❌ Ты использовал лимит дуэлей на сегодня (макс. " . DUEL_DAILY_LIMIT . ")", mainKeyboard());
        return;
    }
    
    $text = "🏆 <b>Дуэли</b>\n\n";
    $text .= "💰 Выбери сумму ставки:\n";
    $text .= "Мин. ставка: " . formatRub(DUEL_MIN_BET) . "\n";
    $text .= "Макс. ставка: " . formatRub(DUEL_MAX_BET) . "\n\n";
    $text .= "⚔️ Бот подберёт соперника с похожим рейтингом.\n";
    $text .= "Победитель определяется по количеству приведённых рефералов за 24 часа.\n";
    $text .= "Рейтинг: победа +50, поражение -50.";
    
    $stmt = $pdo->prepare("SELECT d.*, u.username FROM duels d JOIN users u ON d.user1_id = u.id WHERE d.status = 'waiting' AND d.user1_id != ? ORDER BY d.started_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $waiting_duels = $stmt->fetchAll();
    
    $inlineKeyboard = ['inline_keyboard' => []];
    
    if (count($waiting_duels) > 0) {
        $text .= "\n\n📋 <b>Доступные дуэли для присоединения:</b>\n";
        foreach ($waiting_duels as $d) {
            $text .= "• @" . $d['username'] . " | Ставка: " . formatRub($d['bet']) . "\n";
            $inlineKeyboard['inline_keyboard'][] = [['text' => "⚔️ Присоединиться к @" . $d['username'] . " (" . formatRub($d['bet']) . ")", 'callback_data' => 'duel_join_' . $d['id']]];
        }
    }
    
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => formatRub(DUEL_MIN_BET), 'callback_data' => 'duel_bet_' . DUEL_MIN_BET],
        ['text' => formatRub(500), 'callback_data' => 'duel_bet_500'],
        ['text' => formatRub(1000), 'callback_data' => 'duel_bet_1000'],
        ['text' => formatRub(5000), 'callback_data' => 'duel_bet_5000']
    ];
    $inlineKeyboard['inline_keyboard'][] = [['text' => '🔄 Обновить', 'callback_data' => 'duel_refresh']];
    
    sendMessage($chat_id, $text, $inlineKeyboard);
}

function checkDuelRequirements($user_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $days = $stmt->fetchColumn();
    if ($days < 7) return false;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_tasks WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$user_id]);
    $tasks = $stmt->fetchColumn();
    if ($tasks < 10) return false;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE ref_id = ?");
    $stmt->execute([$user_id]);
    $refs = $stmt->fetchColumn();
    if ($refs < 3) return false;
    
    return true;
}

function createDuel($chat_id, $user_id, $bet, $callback_id) {
    global $pdo;
    
    if ($bet < DUEL_MIN_BET || $bet > DUEL_MAX_BET) {
        sendMessage($chat_id, "❌ Ставка должна быть от " . formatRub(DUEL_MIN_BET) . " до " . formatRub(DUEL_MAX_BET), mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $balance = getUserBalance($user_id);
    if ($balance < $bet) {
        sendMessage($chat_id, "❌ Недостаточно средств! Твой баланс: " . formatRub($balance), mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    if (!checkDuelRequirements($user_id)) {
        sendMessage($chat_id, "❌ Ты не соответствуешь требованиям для дуэлей!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("INSERT INTO duels (user1_id, bet, status, started_at) VALUES (?, ?, 'waiting', NOW())");
    $stmt->execute([$user_id, $bet]);
    $duel_id = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$bet, $user_id]);
    
    sendMessage($chat_id, "⚔️ <b>Дуэль создана!</b>\n\n💰 Ставка: " . formatRub($bet) . "\n⏳ Ожидай соперника...\n\nСоперник будет найден автоматически!", mainKeyboard());
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    
    findDuelOpponent($duel_id);
}

function findDuelOpponent($duel_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT * FROM duels WHERE id = ? AND status = 'waiting'");
    $stmt->execute([$duel_id]);
    $duel = $stmt->fetch();
    
    if (!$duel) return;
    
    $stmt = $pdo->prepare("SELECT u.id, u.username, (SELECT COUNT(*) FROM users WHERE ref_id = u.id) as refs, (SELECT duel_wins FROM users WHERE id = u.id) as wins 
                           FROM users u 
                           WHERE u.id != ? AND u.id NOT IN (SELECT user1_id FROM duels WHERE status = 'waiting') 
                           AND u.id NOT IN (SELECT user1_id FROM duels WHERE status = 'active' UNION SELECT user2_id FROM duels WHERE status = 'active')
                           ORDER BY RAND() LIMIT 1");
    $stmt->execute([$duel['user1_id']]);
    $opponent = $stmt->fetch();
    
    if (!$opponent) {
        return;
    }
    
    if (!checkDuelRequirements($opponent['id'])) return;
    
    $balance = getUserBalance($opponent['id']);
    if ($balance < $duel['bet']) return;
    
    $stmt = $pdo->prepare("UPDATE duels SET user2_id = ?, status = 'active' WHERE id = ?");
    $stmt->execute([$opponent['id'], $duel_id]);
    
    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$duel['bet'], $opponent['id']]);
    
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$duel['user1_id']]);
    $user1 = $stmt->fetchColumn();
    
    $text = "⚔️ <b>Дуэль началась!</b>\n\n";
    $text .= "👤 Участник 1: @" . $user1 . "\n";
    $text .= "👤 Участник 2: @" . $opponent['username'] . "\n";
    $text .= "💰 Ставка: " . formatRub($duel['bet']) . "\n";
    $text .= "⏳ Длительность: " . DUEL_DURATION . " часов\n\n";
    $text .= "📊 <b>Кто приведёт больше рефералов за 24 часа — тот победит!</b>\n";
    $text .= "🏆 Победа: +50 рейтинга, Поражение: -50 рейтинга";
    
    sendMessage($duel['user1_id'], $text, mainKeyboard());
    sendMessage($opponent['id'], $text, mainKeyboard());
}

function joinDuel($chat_id, $user_id, $duel_id, $callback_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT * FROM duels WHERE id = ? AND status = 'waiting'");
    $stmt->execute([$duel_id]);
    $duel = $stmt->fetch();
    
    if (!$duel) {
        sendMessage($chat_id, "❌ Эта дуэль уже началась или отменена!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    if ($duel['user1_id'] == $user_id) {
        sendMessage($chat_id, "❌ Это твоя дуэль! Дождись соперника.", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    if (!checkDuelRequirements($user_id)) {
        sendMessage($chat_id, "❌ Ты не соответствуешь требованиям для дуэлей!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $balance = getUserBalance($user_id);
    if ($balance < $duel['bet']) {
        sendMessage($chat_id, "❌ Недостаточно средств! Твой баланс: " . formatRub($balance), mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE duels SET user2_id = ?, status = 'active' WHERE id = ?");
    $stmt->execute([$user_id, $duel_id]);
    
    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$duel['bet'], $user_id]);
    
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$duel['user1_id']]);
    $user1 = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user2 = $stmt->fetchColumn();
    
    $text = "⚔️ <b>Дуэль началась!</b>\n\n";
    $text .= "👤 Участник 1: @" . $user1 . "\n";
    $text .= "👤 Участник 2: @" . $user2 . "\n";
    $text .= "💰 Ставка: " . formatRub($duel['bet']) . "\n";
    $text .= "⏳ Длительность: " . DUEL_DURATION . " часов\n\n";
    $text .= "📊 <b>Кто приведёт больше рефералов за 24 часа — тот победит!</b>\n";
    $text .= "🏆 Победа: +50 рейтинга, Поражение: -50 рейтинга";
    
    sendMessage($duel['user1_id'], $text, mainKeyboard());
    sendMessage($user_id, $text, mainKeyboard());
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

// ============================================
// 5. 👥 РЕФЕРАЛЫ 2.0
// ============================================
function showReferrals($chat_id, $user_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT id, username, created_at FROM users WHERE ref_id = ?");
    $stmt->execute([$user_id]);
    $refs = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT SUM(income) as total FROM referrals WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_income = $stmt->fetchColumn() ?: 0;
    
    $rank = getUserRank($user_id);
    $ref_count = count($refs);
    
    $text = "👥 <b>Реферальная система 2.0</b>\n\n";
    $text .= "👑 Твой статус: <b>" . $rank['name'] . "</b>\n";
    $text .= "📊 Процент с рефералов: <b>" . $rank['percent'] . "%</b>\n\n";
    $text .= "💰 Доход с рефералов: <b>" . formatRub($total_income) . "</b>\n";
    $text .= "💶 ≈ " . rubToEur($total_income) . " €\n\n";
    
    $text .= "📊 <b>Статусы:</b>\n";
    $text .= "🌱 Новичок (0-5) → 15%\n";
    $text .= "📈 Активный (5-20) → 20%\n";
    $text .= "⭐ Звёздный (20-50) → 25%\n";
    $text .= "💎 VIP (50-100) → 30%\n";
    $text .= "👑 Легенда (100+) → 40%\n\n";
    
    $text .= "🔗 Твоя реферальная ссылка:\n";
    $text .= "<code>https://t.me/" . BOT_USERNAME . "?start=ref_" . $user_id . "</code>\n\n";
    
    if ($ref_count > 0) {
        $text .= "👥 Приглашено: <b>" . $ref_count . "</b> человек\n";
        $text .= "📋 Последние 10:\n";
        foreach (array_slice($refs, 0, 10) as $ref) {
            $text .= "• @" . $ref['username'] . " (" . date('d.m.Y', strtotime($ref['created_at'])) . ")\n";
        }
    } else {
        $text .= "📊 Пока нет приглашённых\n";
        $text .= "Пригласи друзей и начни зарабатывать!";
    }
    
    sendMessage($chat_id, $text, mainKeyboard());
}

// ============================================
// 6. 🔥 СТРИК
// ============================================
function handleStreak($chat_id, $user_id) {
    global $pdo;
    
    checkAndCompleteQuest($user_id, 'check_streak');
    
    updateStreak($chat_id, $user_id);
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT daily_streak FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $streak = $stmt->fetchColumn() ?: 0;
    
    $text = "🔥 <b>Ежедневный стрик</b>\n\n";
    $text .= "📊 Текущий стрик: <b>" . $streak . "</b> дней\n\n";
    $text .= "📋 <b>Награды:</b>\n";
    $text .= "• День 1: " . formatRub(STREAK_BONUS_1) . "\n";
    $text .= "• День 7: " . formatRub(STREAK_BONUS_7) . "\n";
    $text .= "• День 30: " . formatRub(STREAK_BONUS_30) . "\n\n";
    $text .= "💡 Заходи каждый день, чтобы увеличивать стрик и получать бонусы!";
    
    sendMessage($chat_id, $text, mainKeyboard());
}

function updateStreak($chat_id, $user_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT daily_streak, last_streak_date, vacation_used_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    $today = date('Y-m-d');
    $last_date = $user['last_streak_date'];
    $streak = $user['daily_streak'] ?: 0;
    $vacation_date = $user['vacation_used_at'] ?? null;
    
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $is_vacation_yesterday = ($vacation_date && $vacation_date == $yesterday);
    
    if (!$last_date || $last_date == $today) {
        return;
    }
    
    if ($is_vacation_yesterday) {
        $stmt = $pdo->prepare("UPDATE users SET last_streak_date = ? WHERE id = ?");
        $stmt->execute([$today, $user_id]);
        return;
    }
    
    if ($last_date == $yesterday) {
        $streak++;
    } else {
        $streak = 1;
    }
    
    $bonus = 0;
    if ($streak == 1) $bonus = STREAK_BONUS_1;
    elseif ($streak == 7) $bonus = STREAK_BONUS_7;
    elseif ($streak == 30) $bonus = STREAK_BONUS_30;
    
    $stmt = $pdo->prepare("UPDATE users SET daily_streak = ?, last_streak_date = ? WHERE id = ?");
    $stmt->execute([$streak, $today, $user_id]);
    
    if ($bonus > 0) {
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$bonus, $user_id]);
        
        $desc = 'Бонус стрика (день ' . $streak . ')';
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'streak_bonus', ?, NOW())");
        $stmt->execute([$user_id, $bonus, $desc]);
        
        if ($chat_id) {
            sendMessage($chat_id, "🔥 <b>Бонус стрика!</b>\n\nДень " . $streak . " подряд!\n💰 Начислено: <b>" . formatRub($bonus) . "</b>");
        }
    }
}

// ============================================
// 7. 🎯 КВЕСТЫ
// ============================================
function handleQuests($chat_id, $user_id) {
    global $pdo;
    
    checkAndCompleteQuest($user_id, 'check_quests');
    
    $text = "🎯 <b>Квесты (достижения)</b>\n\n";
    $text .= "Выполняй действия в боте и получай награды!\n\n";
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT q.*, uq.status FROM quests q 
                           LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.user_id = ?
                           WHERE q.is_monthly = 0 OR (q.is_monthly = 1 AND uq.status != 'completed')
                           ORDER BY q.is_monthly, q.id");
    $stmt->execute([$user_id]);
    $quests = $stmt->fetchAll();
    
    if (count($quests) == 0) {
        $text .= "🎉 Все квесты выполнены! Ты настоящий профи!";
        sendMessage($chat_id, $text, mainKeyboard());
        return;
    }
    
    $text .= "📋 <b>Доступные квесты:</b>\n\n";
    foreach ($quests as $q) {
        $status = $q['status'] == 'completed' ? '✅' : '⬜';
        $text .= $status . " " . $q['name'] . "\n";
        $text .= "   💰 Награда: " . formatRub($q['reward']) . "\n";
        if ($q['requirement_days'] > 0) {
            $text .= "   ⏳ Требование: " . $q['requirement_days'] . " дней в проекте\n";
        }
        $text .= "\n";
    }
    
    $text .= "\n📅 <b>Ежемесячные квесты:</b>\n\n";
    $stmt = $pdo->prepare("SELECT q.*, uq.status FROM quests q 
                           LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.user_id = ?
                           WHERE q.is_monthly = 1");
    $stmt->execute([$user_id]);
    $monthly = $stmt->fetchAll();
    
    foreach ($monthly as $q) {
        $status = $q['status'] == 'completed' ? '✅' : '⏳';
        $text .= $status . " " . $q['name'] . " — " . formatRub($q['reward']) . "\n";
    }
    
    sendMessage($chat_id, $text, mainKeyboard());
}

// ============================================
// 8. 🏆 ТОП-ЛИДЕРЫ
// ============================================
function handleTop($chat_id, $user_id) {
    global $pdo;
    
    checkAndCompleteQuest($user_id, 'check_top');
    
    $text = "🏆 <b>Топ-лидеры</b>\n\n";
    
    $text .= "👥 <b>Топ по рефералам:</b>\n";
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT u.id, u.username, COUNT(r.id) as refs 
                           FROM users u 
                           LEFT JOIN users r ON r.ref_id = u.id 
                           GROUP BY u.id 
                           ORDER BY refs DESC 
                           LIMIT 10");
    $stmt->execute();
    $top_refs = $stmt->fetchAll();
    
    $i = 1;
    foreach ($top_refs as $t) {
        $text .= $i . ". @" . $t['username'] . " — " . $t['refs'] . " рефералов\n";
        $i++;
    }
    
    $text .= "\n💰 <b>Топ по заработку:</b>\n";
    $stmt = $pdo->prepare("SELECT u.username, COALESCE(SUM(t.amount), 0) as earned 
                           FROM users u 
                           LEFT JOIN transactions t ON u.id = t.user_id AND t.type = 'task' 
                           GROUP BY u.id 
                           ORDER BY earned DESC 
                           LIMIT 10");
    $stmt->execute();
    $top_earned = $stmt->fetchAll();
    
    $i = 1;
    foreach ($top_earned as $t) {
        $text .= $i . ". @" . $t['username'] . " — " . formatRub($t['earned']) . "\n";
        $i++;
    }
    
    $text .= "\n⚔️ <b>Топ по дуэлям (победы):</b>\n";
    $stmt = $pdo->prepare("SELECT u.username, u.duel_wins, u.duel_losses 
                           FROM users u 
                           ORDER BY u.duel_wins DESC, u.duel_losses ASC 
                           LIMIT 10");
    $stmt->execute();
    $top_duels = $stmt->fetchAll();
    
    $i = 1;
    foreach ($top_duels as $t) {
        $text .= $i . ". @" . $t['username'] . " — " . $t['duel_wins'] . " побед, " . $t['duel_losses'] . " поражений\n";
        $i++;
    }
    
    $text .= "\n📊 Обновляется еженедельно!";
    
    sendMessage($chat_id, $text, mainKeyboard());
}

// ============================================
// 9. 🎲 КЕЙСЫ / БОКСЫ
// ============================================
function handleCases($chat_id, $user_id) {
    global $pdo;
    
    checkAndCompleteQuest($user_id, 'check_cases');
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT cases_keys FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $keys = $stmt->fetchColumn() ?: 0;
    
    $text = "🎲 <b>Кейсы</b>\n\n";
    $text .= "🔑 Твои ключи: <b>" . $keys . "</b>\n";
    $text .= "💡 1 ключ выдаётся за каждое выполненное задание!\n\n";
    
    $stmt = $pdo->prepare("SELECT * FROM cases ORDER BY keys_required");
    $cases = $stmt->fetchAll();
    
    $text .= "📋 <b>Доступные кейсы:</b>\n\n";
    $inlineKeyboard = ['inline_keyboard' => []];
    
    foreach ($cases as $case) {
        $can_open = $keys >= $case['keys_required'];
        $status = $can_open ? '🟢' : '🔴';
        $text .= $case['emoji'] . " <b>" . $case['name'] . "</b>\n";
        $text .= "   🔑 Требуется: " . $case['keys_required'] . " ключей\n";
        $text .= "   💰 Награда: " . formatRub($case['min_reward']) . " - " . formatRub($case['max_reward']) . "\n";
        $text .= "   " . $status . " " . ($can_open ? "Доступен" : "Не хватает ключей") . "\n\n";
        
        if ($can_open) {
            $inlineKeyboard['inline_keyboard'][] = [['text' => $case['emoji'] . ' Открыть ' . $case['name'] . ' (' . $case['keys_required'] . ' ключей)', 'callback_data' => 'case_open_' . $case['id']]];
        }
    }
    $inlineKeyboard['inline_keyboard'][] = [['text' => '🔄 Обновить', 'callback_data' => 'cases_refresh']];
    
    $stmt = $pdo->prepare("SELECT uc.*, c.name, c.emoji FROM user_cases uc 
                           JOIN cases c ON uc.case_id = c.id 
                           WHERE uc.user_id = ? 
                           ORDER BY uc.opened_at DESC 
                           LIMIT 5");
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll();
    
    if (count($history) > 0) {
        $text .= "\n📜 <b>Последние открытия:</b>\n";
        foreach ($history as $h) {
            $text .= $h['emoji'] . " " . $h['name'] . " → +" . formatRub($h['reward_amount']) . "\n";
        }
    }
    
    sendMessage($chat_id, $text, $inlineKeyboard);
}

function openCase($chat_id, $user_id, $case_id, $callback_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch();
    
    if (!$case) {
        sendMessage($chat_id, "❌ Кейс не найден!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT cases_keys FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $keys = $stmt->fetchColumn() ?: 0;
    
    if ($keys < $case['keys_required']) {
        sendMessage($chat_id, "❌ Не хватает ключей! Нужно: " . $case['keys_required'] . ", у тебя: " . $keys, mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE users SET cases_keys = cases_keys - ? WHERE id = ?");
    $stmt->execute([$case['keys_required'], $user_id]);
    
    $reward = mt_rand($case['min_reward'], $case['max_reward']);
    $reward = floor($reward);
    
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$reward, $user_id]);
    
    $stmt = $pdo->prepare("INSERT INTO user_cases (user_id, case_id, reward_amount, opened_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$user_id, $case_id, $reward]);
    
    $desc = 'Открыт кейс: ' . $case['name'];
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'case_reward', ?, NOW())");
    $stmt->execute([$user_id, $reward, $desc]);
    
    $stmt = $pdo->prepare("SELECT cases_keys FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $new_keys = $stmt->fetchColumn() ?: 0;
    
    $text = "🎉 <b>Кейс открыт!</b>\n\n";
    $text .= $case['emoji'] . " " . $case['name'] . " кейс\n";
    $text .= "💰 Награда: <b>" . formatRub($reward) . "</b>\n";
    $text .= "🔑 Осталось ключей: " . $new_keys . "\n\n";
    $text .= "Поздравляем! 🎊";
    
    sendMessage($chat_id, $text, mainKeyboard());
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

// ============================================
// ОСТАЛЬНЫЕ ФУНКЦИИ (showTasks, doTask и т.д.)
// ============================================

function showTasks($chat_id, $user_id) {
    global $pdo;
    
    $completed_tasks = getUserCompletedTasks($user_id);
    $completed_ids = !empty($completed_tasks) ? implode(',', array_map('intval', $completed_tasks)) : '0';
    
    if (!isDBConnected()) reconnectDB();
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
    
    if (!isDBConnected()) reconnectDB();
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
    
    if (!isDBConnected()) reconnectDB();
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

function doTask($chat_id, $user_id, $task_id, $username, $callback_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        sendMessage($chat_id, "❌ Задание не найдено", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    if (!checkUserDaysRequirement($user_id, $task['requirement_days'])) {
        sendMessage($chat_id, "❌ Для выполнения этого задания нужно быть в проекте минимум " . $task['requirement_days'] . " дней!\n\nТы в проекте: " . getDaysInProject($user_id) . " дней", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM user_tasks WHERE user_id = ? AND task_id = ?");
    $stmt->execute([$user_id, $task_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        sendMessage($chat_id, "❌ Ты уже выполнил это задание!", mainKeyboard());
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    if ($task['type'] == 'telegram' && !empty($task['channel_id'])) {
        $can_check = canCheckSubscription($task['channel_id']);
        
        if (!$can_check) {
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
            sendMessage(ADMIN_ID, $admin_text);
            
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            return;
        }
        
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
    
    $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status, completed_at) VALUES (?, ?, 'completed', NOW())");
    $stmt->execute([$user_id, $task_id]);
    
    $stmt = $pdo->prepare("UPDATE tasks SET completed_count = completed_count + 1 WHERE id = ?");
    $stmt->execute([$task_id]);
    
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$task['reward'], $user_id]);
    
    $desc = 'Выполнение задания: ' . $task['title'];
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'task', ?, NOW())");
    $stmt->execute([$user_id, $task['reward'], $desc]);
    
    $stmt = $pdo->prepare("UPDATE users SET cases_keys = cases_keys + ? WHERE id = ?");
    $stmt->execute([CASES_KEYS_PER_TASK, $user_id]);
    
    $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $ref_id = $stmt->fetchColumn();
    
    if ($ref_id > 0) {
        $ref_percent = getReferralPercent($ref_id);
        $ref_bonus = $task['reward'] * ($ref_percent / 100);
        
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$ref_bonus, $ref_id]);
        
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'ref_bonus', 'Бонус за реферала (" . $ref_percent . "%)', NOW())");
        $stmt->execute([$ref_id, $ref_bonus]);
        
        $stmt = $pdo->prepare("INSERT INTO referrals (user_id, ref_user_id, income, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$ref_id, $user_id, $ref_bonus]);
    }
    
    $text = "✅ <b>Задание выполнено!</b>\n\n";
    $text .= "💰 Начислено: <b>" . formatRub($task['reward']) . "</b>\n";
    if ($ref_id > 0) {
        $text .= "👥 Реферальный бонус: <b>" . formatRub($ref_bonus) . "</b>\n";
    }
    $text .= "🔑 Получен ключ для кейса!\n";
    $text .= "\n💵 Твой баланс обновлён!";
    sendMessage($chat_id, $text, mainKeyboard());
    
    botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}

function checkSubscriptionCallback($chat_id, $user_id, $task_id, $callback_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
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
    
    $can_check = canCheckSubscription($task['channel_id']);
    
    if (!$can_check) {
        $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status, completed_at) VALUES (?, ?, 'pending', NOW())");
        $stmt->execute([$user_id, $task_id]);
        
        $stmt = $pdo->prepare("UPDATE tasks SET completed_count = completed_count + 1 WHERE id = ?");
        $stmt->execute([$task_id]);
        
        sendMessage($chat_id, "⏳ Задание отправлено на проверку администратору!\n\nОжидай подтверждения.", mainKeyboard());
        
        $admin_text = "📋 Задание требует ручной проверки!\n\n";
        $admin_text .= "👤 Пользователь: @" . $username . "\n";
        $admin_text .= "📌 Задание: " . $task['title'] . "\n";
        $admin_text .= "💰 Награда: " . formatRub($task['reward']) . "\n";
        $admin_text .= "📢 Канал: " . $task['channel_id'] . "\n";
        sendMessage(ADMIN_ID, $admin_text);
        
        botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        return;
    }
    
    $is_subscribed = checkSubscription($user_id, $task['channel_id']);
    
    if ($is_subscribed) {
        if (!checkUserDaysRequirement($user_id, $task['requirement_days'])) {
            sendMessage($chat_id, "❌ Нужно быть в проекте минимум " . $task['requirement_days'] . " дней!\n\nТы в проекте: " . getDaysInProject($user_id) . " дней", mainKeyboard());
            botRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            return;
        }
        
        $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status, completed_at) VALUES (?, ?, 'completed', NOW())");
        $stmt->execute([$user_id, $task_id]);
        
        $stmt = $pdo->prepare("UPDATE tasks SET completed_count = completed_count + 1 WHERE id = ?");
        $stmt->execute([$task_id]);
        
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$task['reward'], $user_id]);
        
        $desc = 'Выполнение задания: ' . $task['title'];
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'task', ?, NOW())");
        $stmt->execute([$user_id, $task['reward'], $desc]);
        
        $stmt = $pdo->prepare("UPDATE users SET cases_keys = cases_keys + ? WHERE id = ?");
        $stmt->execute([CASES_KEYS_PER_TASK, $user_id]);
        
        $stmt = $pdo->prepare("SELECT ref_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $ref_id = $stmt->fetchColumn();
        
        if ($ref_id > 0) {
            $ref_percent = getReferralPercent($ref_id);
            $ref_bonus = $task['reward'] * ($ref_percent / 100);
            
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$ref_bonus, $ref_id]);
            
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'ref_bonus', 'Бонус за реферала (" . $ref_percent . "%)', NOW())");
            $stmt->execute([$ref_id, $ref_bonus]);
            
            $stmt = $pdo->prepare("INSERT INTO referrals (user_id, ref_user_id, income, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$ref_id, $user_id, $ref_bonus]);
        }
        
        $text = "✅ <b>Подписка подтверждена! Задание выполнено!</b>\n\n";
        $text .= "💰 Начислено: <b>" . formatRub($task['reward']) . "</b>\n";
        if ($ref_id > 0) {
            $text .= "👥 Реферальный бонус: <b>" . formatRub($ref_bonus) . "</b>\n";
        }
        $text .= "🔑 Получен ключ для кейса!\n";
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
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() ?: 0;
}

// ============================================
// ВЫВОД СРЕДСТВ
// ============================================
function showWithdrawMenu($chat_id, $user_id) {
    global $pdo;
    
    if (hasActiveWithdraw($user_id)) {
        sendMessage($chat_id, "⚠️ У тебя уже есть активная заявка на вывод!\n\nОжидай её обработки.", mainKeyboard());
        return;
    }
    
    if (!isDBConnected()) reconnectDB();
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
    
    if (!isDBConnected()) reconnectDB();
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
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT id, balance, username, last_withdraw_method, withdraw_waiting_text FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();
    
    if (!$user || $user['withdraw_waiting_text'] != 'yes') return;
    
    if (hasActiveWithdraw($user['id'])) {
        sendMessage($chat_id, "⚠️ У тебя уже есть активная заявка на вывод!", mainKeyboard());
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
    
    $stmt = $pdo->prepare("INSERT INTO withdraws (user_id, amount, method, details, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$user['id'], $amount, $method, $details]);
    $withdraw_id = $pdo->lastInsertId();
    
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
    
    $admin_text = "💳 <b>НОВАЯ ЗАЯВКА НА ВЫВОД!</b>\n\n";
    $admin_text .= "👤 Пользователь: @" . $user['username'] . "\n";
    $admin_text .= "💰 Сумма: " . formatRub($amount) . " (" . rubToEur($amount) . " €)\n";
    $admin_text .= "🏦 Способ: " . $method_name . "\n";
    $admin_text .= "📝 Реквизиты: " . $details . "\n";
    $admin_text .= "📋 Заявка #" . $withdraw_id . "\n";
    $admin_text .= "📅 " . date('d.m.Y H:i:s');
    
    sendMessage(ADMIN_ID, $admin_text);
}

function showMyWithdraws($chat_id, $user_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
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

function showProfile($chat_id, $user_id) {
    global $pdo;
    
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    $rank = getUserRank($user_id);
    $ref_percent = getReferralPercent($user_id);
    
    $stmt = $pdo->prepare("SELECT cases_keys FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $keys = $stmt->fetchColumn() ?: 0;
    
    $text = "👤 <b>Твой профиль</b>\n\n";
    $text .= $rank['icon'] . " <b>" . $rank['name'] . "</b>\n";
    $text .= "👥 Реферальный процент: <b>" . $ref_percent . "%</b>\n";
    $text .= "🔑 Ключей для кейсов: <b>" . $keys . "</b>\n\n";
    $text .= "🆔 ID: " . $user['id'] . "\n";
    $text .= "👤 Username: @" . $user['username'] . "\n";
    $text .= "💰 Баланс: <b>" . formatRub($user['balance']) . "</b>\n";
    $text .= "💶 ≈ " . rubToEur($user['balance']) . " €\n";
    $text .= "📅 В проекте: " . round((time() - strtotime($user['created_at'])) / 86400) . " дней\n";
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(amount) as earned FROM transactions WHERE user_id = ? AND type = 'task'");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();
    
    $text .= "\n\n📊 <b>Статистика</b>\n";
    $text .= "📋 Выполнено заданий: " . ($stats['total'] ?? 0) . "\n";
    $text .= "💰 Заработано: <b>" . formatRub($stats['earned'] ?? 0) . "</b>\n";
    $text .= "⚔️ Дуэли: " . ($user['duel_wins'] ?? 0) . " побед, " . ($user['duel_losses'] ?? 0) . " поражений";
    
    sendMessage($chat_id, $text, mainKeyboard());
}

function showHelp($chat_id) {
    $text = "❓ <b>Помощь</b>\n\n";
    $text .= "📌 <b>Как заработать?</b>\n";
    $text .= "1. Нажми «📋 Задания»\n";
    $text .= "2. Выбери задание и выполни его\n";
    $text .= "3. Получи награду на баланс\n\n";
    $text .= "💰 <b>Вывод средств:</b>\n";
    $text .= "Минимальная сумма: " . formatRub(MIN_WITHDRAW_RUB) . " (≈" . rubToEur(MIN_WITHDRAW_RUB) . " €)\n";
    $text .= "Способы: USDT TRC20 или на карту\n\n";
    $text .= "👥 <b>Реферальная программа:</b>\n";
    $text .= "Приглашай друзей и получай до 40% от их заработка!\n\n";
    $text .= "🏖️ <b>Отпуск:</b>\n";
    $text .= "Можно взять 1 день отпуска раз в 14 дней\n\n";
    $text .= "⚔️ <b>Дуэли:</b>\n";
    $text .= "Соревнуйся с другими пользователями и выигрывай!\n\n";
    $text .= "🎲 <b>Кейсы:</b>\n";
    $text .= "За задания получай ключи и открывай кейсы с наградами!\n\n";
    $text .= "📨 <b>Переводы:</b>\n";
    $text .= "Создай пригласительный перевод и перешли его другу!\n\n";
    $text .= "📧 По всем вопросам: @artawork_support";
    
    sendMessage($chat_id, $text, mainKeyboard());
}

function getUserBalance($user_id) {
    global $pdo;
    if (!isDBConnected()) reconnectDB();
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() ?: 0;
}

// ============================================
// ОСНОВНОЙ ЦИКЛ С ПЕРЕПОДКЛЮЧЕНИЕМ
// ============================================
echo "🤖 Бот ArtaWork запущен!\n";
echo "Нажми Ctrl+C для остановки\n\n";

$last_update_id = 0;
$reconnect_attempts = 0;

while (true) {
    try {
        // Проверяем соединение с БД перед каждым циклом
        if (!isDBConnected()) {
            $reconnect_attempts++;
            if ($reconnect_attempts > 3) {
                echo "⚠️ Попытка переподключения к БД...\n";
                if (reconnectDB()) {
                    $reconnect_attempts = 0;
                    echo "✅ Переподключение успешно!\n";
                } else {
                    echo "❌ Ошибка переподключения, ждем 5 секунд...\n";
                    sleep(5);
                    continue;
                }
            } else {
                reconnectDB();
                sleep(1);
                continue;
            }
        }
        $reconnect_attempts = 0;
        
        $updates = botRequest('getUpdates', [
            'offset' => $last_update_id + 1,
            'timeout' => 30
        ]);
        
        if (isset($updates['ok']) && $updates['ok'] === true && isset($updates['result']) && count($updates['result']) > 0) {
            foreach ($updates['result'] as $update) {
                $last_update_id = $update['update_id'];
                processUpdate($update);
                echo "✅ Обработано обновление #" . $update['update_id'] . "\n";
            }
        } elseif (isset($updates['error']) && strpos($updates['error'], 'timeout') !== false) {
            // Игнорируем таймаут
        } elseif (isset($updates['error'])) {
            error_log("Telegram API error: " . $updates['error']);
            echo "⚠️ Ошибка API, продолжаем...\n";
        }
        
    } catch (Exception $e) {
        error_log("Bot error: " . $e->getMessage());
        echo "❌ Ошибка: " . $e->getMessage() . "\n";
        sleep(3);
    }
    
    usleep(500000);
}
?>