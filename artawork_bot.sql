-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Хост: 127.0.0.1
-- Время создания: Июл 18 2026 г., 15:49
-- Версия сервера: 10.4.32-MariaDB
-- Версия PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `artawork_bot`
--

-- --------------------------------------------------------

--
-- Структура таблицы `daily_bonuses`
--

CREATE TABLE `daily_bonuses` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `bonus_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `daily_bonuses`
--

INSERT INTO `daily_bonuses` (`id`, `user_id`, `bonus_date`, `amount`) VALUES
(2, 2, '2026-07-16', 50.00),
(3, 3, '2026-07-16', 50.00);

-- --------------------------------------------------------

--
-- Структура таблицы `referrals`
--

CREATE TABLE `referrals` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ref_user_id` int(11) NOT NULL,
  `income` decimal(12,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `reward` decimal(12,2) NOT NULL,
  `limit_count` int(11) DEFAULT 0,
  `completed_count` int(11) DEFAULT 0,
  `status` enum('active','paused','completed') DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `type` varchar(50) DEFAULT 'telegram',
  `channel_id` varchar(255) DEFAULT NULL,
  `requirement_days` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `amount`, `type`, `description`, `created_at`) VALUES
(8, 3, 50.00, 'bonus', 'Бонус за регистрацию', '2026-07-16 21:24:20'),
(9, 3, 50.00, 'daily_bonus', 'Ежедневный бонус', '2026-07-16 21:24:30'),
(10, 3, 222.00, 'task', 'Выполнение задания: dsadas', '2026-07-16 21:25:08'),
(11, 3, 100.00, 'task', 'Выполнение задания (ручное подтверждение): ыавыав', '2026-07-16 21:29:49'),
(12, 3, -47722.00, 'withdraw', 'Вывод средств #4', '2026-07-16 21:36:03');

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `telegram_id` bigint(20) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `balance` decimal(12,2) DEFAULT 0.00,
  `ref_id` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `last_active` datetime DEFAULT NULL,
  `last_withdraw_method` varchar(50) DEFAULT NULL,
  `withdraw_waiting_text` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `telegram_id`, `username`, `balance`, `ref_id`, `created_at`, `last_active`, `last_withdraw_method`, `withdraw_waiting_text`) VALUES
(2, 891643730, 'ebanutaja_dama19', 342.00, 0, '2026-07-16 21:10:52', NULL, NULL, NULL),
(3, 669733760, 'kj_v_dele', 0.00, 0, '2026-07-16 21:24:20', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `user_tasks`
--

CREATE TABLE `user_tasks` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `status` enum('pending','completed','rejected') DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `withdraws`
--

CREATE TABLE `withdraws` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(50) NOT NULL,
  `wallet` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `withdraws`
--

INSERT INTO `withdraws` (`id`, `user_id`, `amount`, `method`, `wallet`, `details`, `status`, `created_at`) VALUES
(4, 3, 47722.00, 'crypto', NULL, 'ввыфвыф', 'approved', '2026-07-16 21:36:03');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `daily_bonuses`
--
ALTER TABLE `daily_bonuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`bonus_date`);

--
-- Индексы таблицы `referrals`
--
ALTER TABLE `referrals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `ref_user_id` (`ref_user_id`);

--
-- Индексы таблицы `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `telegram_id` (`telegram_id`);

--
-- Индексы таблицы `user_tasks`
--
ALTER TABLE `user_tasks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`task_id`),
  ADD KEY `task_id` (`task_id`);

--
-- Индексы таблицы `withdraws`
--
ALTER TABLE `withdraws`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `daily_bonuses`
--
ALTER TABLE `daily_bonuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT для таблицы `referrals`
--
ALTER TABLE `referrals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT для таблицы `user_tasks`
--
ALTER TABLE `user_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT для таблицы `withdraws`
--
ALTER TABLE `withdraws`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `daily_bonuses`
--
ALTER TABLE `daily_bonuses`
  ADD CONSTRAINT `daily_bonuses_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `referrals`
--
ALTER TABLE `referrals`
  ADD CONSTRAINT `referrals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `referrals_ibfk_2` FOREIGN KEY (`ref_user_id`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `user_tasks`
--
ALTER TABLE `user_tasks`
  ADD CONSTRAINT `user_tasks_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `user_tasks_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`);

--
-- Ограничения внешнего ключа таблицы `withdraws`
--
ALTER TABLE `withdraws`
  ADD CONSTRAINT `withdraws_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
