<?php
/**
 * ГРОБИ-БОТ: ПЛАНИРОВЩИК ПОСТОВ
 * Настройка: Москва (MSK)
 */

// --- 1. НАСТРОЙКИ ---
$bot_token     = "8518607133:AAF1g9oIOSD1DGP6WfuU_lfpGxHt7Z2gPDo";
$main_chat_id  = "-1003735769568"; // Основной канал/группа
$admin_chat_id = "-1003812180726"; // Админ-чат для команд

$supabase_url  = "https://oanhnetxmrjocchovlbt.supabase.co"; 
$supabase_key  = "sb_publishable_giBDTlWWtHUdtim_PavORw_FUJUBAvM";

// --- 2. ФУНКЦИЯ API SUPABASE ---
function supabase_api($method, $path, $data = null) {
    global $supabase_url, $supabase_key;
    $ch = curl_init("$supabase_url/rest/v1/$path");
    $headers = [
        "apikey: $supabase_key",
        "Authorization: Bearer $supabase_key",
        "Content-Type: application/json",
        "Prefer: return=representation"
    ];
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// --- 3. РЕЖИМ CRON (ПРОВЕРКА ВРЕМЕНИ) ---
if (isset($_GET['cron'])) {
    date_default_timezone_set('Europe/Moscow'); 
    $currentTime = date('H:i');
    $currentDate = date('Y-m-d');

    // Проверяем, есть ли пост на текущую минуту
    $data = supabase_api("GET", "telegram_tasks?id=eq.1&post_date=eq.$currentDate&post_time=eq.$currentTime");
    
    if (!empty($data)) {
        $task = $data[0];
        // Если еще не отправляли сегодня (защита от дублей в ту же минуту)
        if ($task['last_sent_date'] !== $currentDate) {
            $text = urlencode($task['post_text']);
            $send_url = "https://api.telegram.org/bot$bot_token/sendMessage?chat_id=$main_chat_id&text=$text&parse_mode=HTML";
            
            if (file_get_contents($send_url)) {
                // Ставим отметку, что отправлено
                supabase_api("PATCH", "telegram_tasks?id=eq.1", ["last_sent_date" => $currentDate]);
                echo "✅ Пост успешно опубликован!";
            }
        }
    } else {
        echo "В очереди ничего нет на $currentDate $currentTime (МСК)";
    }
    exit;
}

// --- 4. РЕЖИМ WEBHOOK (ПРИЕМ КОМАНД) ---
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (isset($update['message'])) {
    $msg = $update['message'];
    $text = $msg['text'] ?? '';
    $chat_id_from = $msg['chat']['id'];
    
    // Формируем тег пользователя
    $username = $msg['from']['username'] ?? $msg['from']['first_name'];
    $user_tag = "@" . str_replace('@', '', $username);

    // РАБОТАЕМ ТОЛЬКО В АДМИН-ЧАТЕ
    if ($chat_id_from == $admin_chat_id) {
        
        // --- КОМАНДА /day (ДОБАВИТЬ В ОЧЕРЕДЬ) ---
        if (strpos($text, '/day') === 0) {
            $parts = explode(' ', $text, 4);
            if (count($parts) < 4) {
                $reply = "❌ Ошибка! Используй: <code>/day 25.03.2026 18:00 Текст</code>";
            } else {
                $raw_date = trim($parts[1]);
                $time = trim($parts[2]);
                $content = trim($parts[3]);
                
                // Преобразуем дату для базы (ДД.ММ.ГГГГ -> ГГГГ-ММ-ДД)
                $formatted_date = date('Y-m-d', strtotime($raw_date));

                if ($formatted_date && preg_match('/^\d{2}:\d{2}$/', $time)) {
                    supabase_api("PATCH", "telegram_tasks?id=eq.1", [
                        "post_date" => $formatted_date,
                        "post_time" => $time,
                        "post_text" => $content,
                        "username"  => $user_tag,
                        "last_sent_date" => null // Сбрасываем, чтобы новый пост мог отправиться
                    ]);
                    $reply = "$user_tag, ваш пост добавлен в очередь.";
                } else {
                    $reply = "❌ Ошибка в формате даты или времени!";
                }
            }
            file_get_contents("https://api.telegram.org/bot$bot_token/sendMessage?chat_id=$admin_chat_id&parse_mode=HTML&text=".urlencode($reply));
        }

        // --- КОМАНДА /e (ПОСМОТРЕТЬ ОЧЕРЕДЬ) ---
        if ($text == '/e') {
            $data = supabase_api("GET", "telegram_tasks?id=eq.1");
            if (!empty($data) && !empty($data[0]['post_text'])) {
                $t = $data[0];
                $d = date('d.m.Y', strtotime($t['post_date']));
                $reply = "<b>📋 ТЕКУЩАЯ ОЧЕРЕДЬ:</b>\n\n"
                       . "<b>📅 Дата:</b> {$d}\n"
                       . "<b>⏰ Время:</b> {$t['post_time']} (МСК)\n"
                       . "<b>👤 Автор:</b> {$t['username']}\n"
                       . "<b>📝 Текст:</b>\n<i>{$t['post_text']}</i>";
            } else {
                $reply = "📭 Очередь пуста.";
            }
            file_get_contents("https://api.telegram.org/bot$bot_token/sendMessage?chat_id=$admin_chat_id&parse_mode=HTML&text=".urlencode($reply));
        }
    }
}
