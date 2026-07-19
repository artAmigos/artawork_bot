# ArtaWork Bot - Заработок в Telegram

## 📦 Установка

1. Создай бота у @BotFather и получи токен
2. Создай базу данных в phpMyAdmin
3. Импортируй db.sql
4. Настрой config.php
5. Загрузи все файлы на хостинг
6. Открой в браузере: https://твойсайт/bot.php?setwebhook

## 🚀 Запуск

- Бот готов к работе!
- Админка: https://твойсайт/admin.php (пароль: admin123)

## 📱 Команды бота

- /start - Регистрация
- Кнопки для всего остального

## ⚙️ Настройка

Измени в config.php:
- BOT_TOKEN
- BOT_USERNAME  
- ADMIN_ID
- Пароль в admin.php

## 💰 Способы вывода

- USDT TRC20
- Минимальная сумма: 5 €


# ArtaWork Bot 🤖

Telegram бот для заработка на подписках.

## 📦 Установка

1. Клонируй репозиторий
2. Создай `config.php` на основе `config.example.php`
3. Настрой БД
4. Запусти `php bot.php`

## 🔧 Переменные окружения

Создай `config.php` со следующими настройками:

```php
define('DB_HOST', 'your_host');
define('DB_NAME', 'your_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('BOT_TOKEN', 'your_bot_token');
define('BOT_USERNAME', 'your_bot_username');
define('ADMIN_ID', 123456789);