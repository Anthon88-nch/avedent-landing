# Лендинг «Одномоментная имплантация» — стоматология «АВЕдент»

Статический одностраничник (HTML/CSS/JS без зависимостей). Сейчас на GitHub Pages: https://anthon88-nch.github.io/avedent-landing/

## Перенос на хостинг (Apache + PHP 8)
1. Залить всё, **кроме** папки `.git` и `README.md`. Файл `.htaccess` обязательно.
2. `send.php` — заполнить блок «НАСТРОЙКИ»:
   - `SECRET` — случайная строка (`openssl rand -hex 20`). Только на хостинге, в репозиторий не коммитить.
   - `ALLOWED_HOSTS` — домен лендинга.
   - `MAIL_TO` / `MAIL_FROM` — куда слать заявки; `MAIL_FROM` должен быть ящиком на домене сайта.
   - `TG_BOT_TOKEN` / `TG_CHAT_ID` — по желанию (Telegram — иностранный сервис, учесть 152-ФЗ).
3. В `index.html` указать `var FORM_ENDPOINT = 'send.php';` и `var YM_ID = <номер счётчика>;`.
4. В `<head>` заменить адреса `anthon88-nch.github.io/avedent-landing/` (og:image, Schema.org) на новый домен.
5. Открыть сайт, F12 → консоль. Нет ошибок CSP — в `.htaccess` заменить `Content-Security-Policy-Report-Only` на `Content-Security-Policy`.
6. Проверить: https://securityheaders.com, отправить тестовую заявку.

## Защита формы от ботов (без капчи)
- Клиент: поле-ловушка `website`, минимум 5 с на квиз, нужны реальные клики/нажатия, отсев `navigator.webdriver`.
- Сервер (`send.php`): подписанный токен времени (GET `?token=1`), проверка Origin/Referer, лимит 3 заявки / 10 мин с IP (IP хранится только хэшем), валидация имени и телефона, лимит размера. Боту отвечаем «успехом».

## Настройки в конце index.html
- `FORM_ENDPOINT` — обработчик заявок. Пусто — заявка уходит в WhatsApp.
- `YM_ID` — номер счётчика Яндекс Метрики.
- `WA_PHONE`, `PRICE`.

## Осталось
- Фото ресепшена и микроскопа (`img/clinic-1.webp`, `img/clinic-3.webp`) пока подтягиваются с tildacdn.
