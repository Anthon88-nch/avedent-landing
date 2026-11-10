<?php
/**
 * Обработчик заявок с лендинга «АВЕдент».
 * Включается так: в index.html задать  var FORM_ENDPOINT = 'send.php';
 *
 * Защита от ботов без капчи:
 *  1) токен: страница получает подписанную метку времени (GET ?token=1), без неё заявка не принимается;
 *  2) время: заявка раньше 3 секунд после выдачи токена или позже 2 часов — отклоняется;
 *  3) поле-ловушка website: люди его не видят, боты заполняют;
 *  4) проверка Origin/Referer — только с нашего домена;
 *  5) лимит: не больше 3 заявок с одного IP за 10 минут (IP хранится только в виде хэша);
 *  6) строгая проверка имени и телефона, ограничение размера запроса.
 * Боту отвечаем «успехом», чтобы он не подбирал обход.
 */

/* ===== НАСТРОЙКИ — заполнить на хостинге ===== */
const SECRET          = 'ЗАМЕНИТЕ_НА_СЛУЧАЙНУЮ_СТРОКУ_40_СИМВОЛОВ';    // например: openssl rand -hex 20
const ALLOWED_HOSTS   = ['avedent-med.com', 'www.avedent-med.com'];   // домены, где стоит лендинг
const MAIL_TO         = 'redent2007@yandex.ru';                       // куда слать заявки (пусто — не слать)
const MAIL_FROM       = 'no-reply@avedent-med.com';                   // ящик на домене сайта, иначе письма уйдут в спам
const TG_BOT_TOKEN    = '';                                           // токен бота Telegram (необязательно)
const TG_CHAT_ID      = '';                                           // id чата администраторов
const RATE_LIMIT      = 3;                                            // заявок
const RATE_WINDOW     = 600;                                          // за сколько секунд
/* ============================================ */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fake_ok()         { out(200, ['ok' => true]); }   // для ботов
function sign($t)          { return hash_hmac('sha256', (string)$t, SECRET); }

if (SECRET === 'ЗАМЕНИТЕ_НА_СЛУЧАЙНУЮ_СТРОКУ_40_СИМВОЛОВ') out(500, ['ok' => false, 'error' => 'not configured']);

/* --- проверка источника запроса --- */
$src  = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$host = strtolower(parse_url($src, PHP_URL_HOST) ?: '');
if (!in_array($host, ALLOWED_HOSTS, true)) out(403, ['ok' => false]);

/* --- выдача токена --- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['token'])) out(405, ['ok' => false]);
    $t = time();
    out(200, ['t' => $t, 'h' => sign($t)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false]);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) out(413, ['ok' => false]);

/* --- лимит частоты (IP хранится как хэш, сами адреса не сохраняются) --- */
$dir = sys_get_temp_dir() . '/avedent_rl';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$ipKey = $dir . '/' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . SECRET);
$now = time();
$hits = array_filter(array_map('intval', @file($ipKey, FILE_IGNORE_NEW_LINES) ?: []), function ($x) use ($now) { return $x > $now - RATE_WINDOW; });
if (count($hits) >= RATE_LIMIT) out(429, ['ok' => false, 'error' => 'too many']);
$hits[] = $now;
@file_put_contents($ipKey, implode("\n", $hits), LOCK_EX);
foreach (glob($dir . '/*') ?: [] as $f) if (@filemtime($f) < $now - 86400) @unlink($f);   // чистка старых

/* --- антибот --- */
if (!empty($_POST['website'])) fake_ok();                                     // ловушка
$tk = (int)($_POST['tk'] ?? 0);
$hm = (string)($_POST['hm'] ?? '');
if (!$tk || !hash_equals(sign($tk), $hm)) fake_ok();                           // нет/поддельный токен
$age = $now - $tk;
if ($age < 3 || $age > 7200) fake_ok();                                        // слишком быстро или протух

/* --- данные --- */
function clean($v, $max) { $v = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', ' ', (string)$v)); return mb_substr($v, 0, $max); }
$name  = clean($_POST['name'] ?? '', 60);
$phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
if ($name !== '' && !preg_match('/^[\p{L}\s\-\.]{2,60}$/u', $name)) out(422, ['ok' => false, 'error' => 'name']);   // имя необязательно
if ($name === '') $name = 'не указано';
if (!preg_match('/^[78]\d{10}$/', $phone))                             out(422, ['ok' => false, 'error' => 'phone']);
if (empty($_POST['agree']))                                              out(422, ['ok' => false, 'error' => 'agree']);
$phone = '+7' . substr($phone, 1);

$f = [];
foreach (['contact' => 'Как связаться', 'tooth' => 'Зуб', 'state' => 'Состояние', 'when' => 'Когда', 'pay' => 'Оплата', 'service' => 'Услуга'] as $k => $label) {
    $f[$label] = clean($_POST[$k] ?? '—', 80);
}

$text = "Новая заявка с лендинга\n\nИмя: $name\nТелефон: $phone\n";
foreach ($f as $label => $v) $text .= "$label: $v\n";
$text .= "\nСогласие на обработку ПДн: да\nВремя: " . date('d.m.Y H:i');

/* --- отправка --- */
$sent = false;
if (MAIL_TO) {
    $subj = '=?UTF-8?B?' . base64_encode('Заявка: имплантация — ' . $phone . ($name !== 'не указано' ? ', ' . $name : '')) . '?=';
    $hdr  = "From: " . MAIL_FROM . "\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit";
    $sent = @mail(MAIL_TO, $subj, $text, $hdr) || $sent;
}
if (TG_BOT_TOKEN && TG_CHAT_ID) {
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['chat_id' => TG_CHAT_ID, 'text' => $text]),
        'timeout' => 8,
    ]]);
    $r = @file_get_contents('https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage', false, $ctx);
    $sent = ($r !== false) || $sent;
}

$sent ? out(200, ['ok' => true]) : out(502, ['ok' => false, 'error' => 'delivery']);
