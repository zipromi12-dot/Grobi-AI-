<?php
/**
 * ГРОБИ-БОТ: ПУБЛИКАЦИЯ + ЗАКРЕП В ОСНОВНОМ ЧАТЕ
 * Время: Москва (MSK)
 */

// --- 1. НАСТРОЙКИ ---
$bot_token     = "8518607133:AAF1g9oIOSD1DGP6WfuU_lfpGxHt7Z2gPDo";
$main_chat_id  = "-1003735769568"; // Чат, где ПУБЛИКУЕМ и ЗАКРЕПЛЯЕМ
$admin_chat_id = "-1003812180726"; // Чат, где ПРИНИМАЕМ команды

$supabase_url  = "https://oanhnetxmrjocchovlbt.supabase.co"; 
$supabase_key  = "sb_publishable_giBDTlWWtHUdtim_PavORw_FUJUBAvM";

// --- 2. ФУНКЦИЯ API TELEGRAM ---
function telegram_api($method, $params = []) {
    global $bot_token;
    $url = "https://api.telegram.org/bot$bot_token/$method";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// --- 3. ФУНКЦИЯ API SUPABASE ---
function supabase_api($method, $path, $data = null) {
    global $supabase_url, $supabase_key;
    $ch = curl_init("$supabase_url/rest/v1/$path");
    $headers = ["apikey: $supabase_key", "Authorization: Bearer $supabase_key", "Content-Type: application/json", "Prefer: return=representation"];
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// --- 4. РЕЖИМ CRON (АВТО-ПОСТИНГ + ЗАКРЕП) ---
if (isset($_GET['cron'])) {
    date_default_timezone_set('Europe/Moscow'); 
    $currentTime = date('H:i');
    $currentDate = date('Y-m-d');

    $data = supabase_api("GET", "telegram_tasks?id=eq.1&post_date=eq.$currentDate&post_time=eq.$currentTime");
    
    if (!empty($data)) {
        $task = $data[0];
        if ($task['last_sent_date'] !== $currentDate) {
            // 1. ОТПРАВЛЯЕМ ПОСТ
            $sent_res = telegram_api("sendMessage", [
                "chat_id" => $main_chat_id,
                "text" => $task['post_text'],
                "parse_mode" => "HTML"
            ]);
            
            if ($sent_res['ok']) {
                $msg_id = $sent_res['result']['message_id'];
                
                // 2. ЗАКРЕПЛЯЕМ ПОСТ В ОСНОВНОМ ЧАТЕ
                telegram_api("pinChatMessage", [
                    "chat_id" => $main_chat_id,
                    "message_id" => $msg_id,
                    "disable_notification" => false // Уведомить участников о закрепе
                ]);

                // 3. ОТМЕЧАЕМ В БАЗЕ КАК ОТПРАВЛЕННОЕ
                supabase_api("PATCH", "telegram_tasks?id=eq.1", ["last_sent_date" => $currentDate]);
                echo "✅ Опубликовано и закреплено в основном чате!";
            }
        }
    } else {
        echo "Очередь пуста ($currentDate $currentTime MSK)";
    }
    exit;
}

// --- 5. РЕЖИМ WEBHOOK ---
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (isset($update['message'])) {
    $msg = $update['message'];
    $text = $msg['text'] ?? '';
    $chat_id_from = $msg['chat']['id'];
    $user_name = $msg['from']['username'] ?? $msg['from']['first_name'];
    $user_tag = "@" . str_replace('@', '', $user_name);

    if ($chat_id_from == $admin_chat_id) {
        
        // КОМАНДА /day (ПЛАНИРОВАНИЕ)
        if (strpos($text, '/day') === 0) {
            $parts = explode(' ', $text, 4);
            if (count($parts) < 4) {
                telegram_api("sendMessage", ["chat_id" => $admin_chat_id, "text" => "❌ Формат: /day 22.03.2026 18:00 Текст", "parse_mode" => "HTML"]);
            } else {
                $raw_date = trim($parts[1]);
                $time = trim($parts[2]);
                $content = trim($parts[3]);
                $formatted_date = date('Y-m-d', strtotime($raw_date));

                if ($formatted_date && preg_match('/^\d{2}:\d{2}$/', $time)) {
                    supabase_api("PATCH", "telegram_tasks?id=eq.1", [
                        "post_date" => $formatted_date, "post_time" => $time,
                        "post_text" => $content, "username" => $user_tag, "last_sent_date" => null
                    ]);
                    
                    $status_text = "$user_tag, ваш пост добавлен в очередь.\n"
                                 . "Бот опубликует и закрепит его в основном чате в $time (МСК).";

                    telegram_api("sendMessage", [
                        "chat_id" => $admin_chat_id,
                        "text" => $status_text,
                        "parse_mode" => "HTML"
                    ]);
                }
            }
        }

        // КОМАНДА /e (ПРОСМОТР)
        if ($text == '/e') {
            $data = supabase_api("GET", "telegram_tasks?id=eq.1");
            if (!empty($data) && !empty($data[0]['post_text'])) {
                $t = $data[0];
                $d = date('d.m.Y', strtotime($t['post_date']));
                $reply = "<b>📋 ТЕКУЩИЙ ПЛАН:</b>\n\n📅 $d | ⏰ {$t['post_time']} (МСК)\n👤 Автор: {$t['username']}\n📝 {$t['post_text']}";
            } else {
                $reply = "📭 В очереди пусто.";
            }
            telegram_api("sendMessage", ["chat_id" => $admin_chat_id, "text" => $reply, "parse_mode" => "HTML"]);
        }
    }
}
