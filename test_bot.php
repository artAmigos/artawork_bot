<?php
require_once 'config.php';

echo "🔍 Проверка бота ArtaWork...\n\n";

// Проверяем подключение к БД
try {
    $pdo->query("SELECT 1");
    echo "✅ База данных: работает\n";
} catch (Exception $e) {
    echo "❌ База данных: " . $e->getMessage() . "\n";
}

// Проверяем токен бота
$result = botRequest('getMe');
if ($result['ok']) {
    echo "✅ Бот: работает\n";
    echo "   Имя: " . $result['result']['first_name'] . "\n";
    echo "   Username: @" . $result['result']['username'] . "\n";
} else {
    echo "❌ Бот: " . $result['description'] . "\n";
}

// Проверяем таблицы
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll();
    echo "✅ Таблицы в БД: " . count($tables) . " шт.\n";
    foreach ($tables as $table) {
        echo "   - " . reset($table) . "\n";
    }
} catch (Exception $e) {
    echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
}

echo "\n📌 Настройки:\n";
echo "💰 Минимальный вывод: " . formatRub(MIN_WITHDRAW_RUB) . " (≈" . rubToEur(MIN_WITHDRAW_RUB) . " €)\n";
echo "👥 Реферальный процент: " . REFERRAL_PERCENT . "%\n";
echo "🔥 Бонусы активности: +" . BONUS_3_DAYS . "% (3 дня), +" . BONUS_7_DAYS . "% (7 дней), +" . BONUS_14_DAYS . "% (14 дней)\n";

echo "\n📌 Запусти бота: php bot.php\n";