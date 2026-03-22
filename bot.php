<?php
/**
 * ПОЛНЫЙ КОД БОТА (bot.php)
 * Работает через Supabase REST API (anon key)
 */

// --- 1. НАСТРОЙКИ (ОБЯЗАТЕЛЬНО ЗАПОЛНИ) ---
$bot_token    = "8518607133:AAF1g9oIOSD1DGP6WfuU_lfpGxHt7Z2gPDo";
$chat_id      = "-1003735769568"; // Пример: -100123456789
$supabase_url = "https://oanhnetxmrjocchovlbt.supabase.co"; 
$supabase_key = "sb_publishable_giBDTlWWtHUdtim_PavORw_FUJUBAvM";

// --- 2. ФУНКЦИЯ ДЛЯ СВЯЗИ С SUPABASE ---
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
// Этот блок срабатывает, когда вы вызываете: bot.php?cron=1
if (isset($_GET['cron'])) {
    date_default_timezone_set('Europe/Moscow');
    $currentTime = date('H:i');
    $currentDate = date('Y-m-d');

    // Запрашиваем из базы задачу с ID=1
    $data = supabase_api("GET", "telegram_tasks?id=eq.1");
    
    if (!empty($data)) {
        $task = $data[0];
        
        // Если время совпало И сегодня еще не отправляли
        if ($task['post_time'] == $currentTime && $task['last_sent_date'] != $currentDate) {
            $text = urlencode($task['post_text']);
            $send_url = "https://api.telegram.org/bot$bot_token/sendMessage?chat_id=$chat_id&text=$text&parse_mode=HTML";
            
            if (file_get_contents($send_url)) {
                // Записываем дату отправки, чтобы не дублировать в эту же минуту
                supabase_api("PATCH", "telegram_tasks?id=eq.1", ["last_sent_date" => $currentDate]);
                echo "✅ Пост успешно отправлен в группу!";
            }
        } else {
            echo "Ожидание... Сейчас в МСК: $currentTime. В базе: " . $task['post_time'];
        }
    }
    exit; // Останавливаем выполнение, чтобы не сработал Webhook
}

// --- 4. РЕЖИМ WEBHOOK (ПРИЕМ КОМАНДЫ /day) ---
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (isset($update['message'])) {
    $msg = $update['message'];
    $text = $msg['text'] ?? '';
    $sender_id = $msg['from']['id'];

    // Обработка команды /day ЧЧ:ММ Текст
    if (strpos($text, '/day') === 0) {
        $parts = explode(' ', $text, 3);
        
        if (count($parts) < 3) {
            $reply = "❌ Ошибка! Пиши так: <code>/day 12:00 Твой текст</code>";
        } else {
            $time = trim($parts[1]);
            $content = trim($parts[2]);

            // Валидация времени (ЧЧ:ММ)
            if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                // Обновляем строку в Supabase
                supabase_api("PATCH", "telegram_tasks?id=eq.1", [
                    "post_time" => $time,
                    "post_text" => $content,
                    "last_sent_date" => null // Сбрасываем дату, чтобы можно было отправить сегодня
                ]);
                $reply = "✅ <b>Готово!</b>\nВремя: $time (МСК)\nТекст: $content";
            } else {
                $reply = "❌ Неверный формат времени! Нужно ЧЧ:ММ (например, 08:30).";
            }
        }
        
        // Ответ админу в личку
        file_get_contents("https://api.telegram.org/bot$bot_token/sendMessage?chat_id=$sender_id&parse_mode=HTML&text=" . urlencode($reply));
    }
}
