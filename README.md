# Mystery Tag Test

Невеликий Laravel-сервіс приймає рекламні постбеки, зберігає події в MySQL, дедуплікує їх через Redis і асинхронно передає нові події партнеру.

## Технології

- PHP 8.4 і Laravel 13
- MySQL 8.4 — події та черга завдань
- Redis 7.4 — коротке резервування під час дедуплікації
- Docker Compose — локальний запуск API, worker, scheduler, MySQL, Redis і необов’язкового тестового партнера

## Запуск

Потрібні Docker із Compose v2 та Git. Скопіюйте приклад конфігурації й замініть локальні паролі на власні:

```powershell
Copy-Item .env.example .env
```

Створіть ключ Laravel і вставте виведене значення `base64:...` у `APP_KEY` у файлі `.env`:

```sh
docker compose run --rm --no-deps --build app php artisan key:generate --show
```

Запустіть сервіси:

```sh
docker compose --profile mock up --build -d
docker compose ps
```

Compose запускає MySQL і Redis, чекає їхньої готовності, виконує міграції, а потім запускає API, worker і scheduler. Профіль `mock` додає тестового партнера у внутрішню мережу Compose; його можна не запускати, якщо в `.env` задано адресу реального партнера. HTTP-порт API прив’язаний до `127.0.0.1:8080`; MySQL і Redis не публікують портів на хост.

Після зміни коду або схеми перебудуйте образ, явно застосуйте міграції та перезапустіть довгі процеси:

```sh
docker compose build
docker compose run --rm migrate
docker compose --profile mock up -d --force-recreate app worker scheduler
```

Команда `run --rm migrate` створює новий одноразовий контейнер і повторно запускає міграції, навіть якщо попередній `migrate` вже завершився. Перевірте `docker compose ps` і журнали після перезапуску.

Перевірте стан API:

```sh
curl.exe -i http://127.0.0.1:8080/up
```

Зупиніть сервіси, зберігши дані MySQL:

```sh
docker compose down
```

## Перевірка API

Наведені HTTP-команди розраховані на Git Bash, Linux або macOS. У Windows PowerShell викликайте `curl.exe` замість `curl`.

Надішліть новий постбек:

```sh
curl -i "http://127.0.0.1:8080/postback?click_id=clk_123&event=purchase&amount=49.90"
```

Очікувана відповідь — `200`:

```json
{"status":"accepted","message":"Event received"}
```

Надішліть числово еквівалентну суму `49.9`. У межах 24 годин відповідь буде `duplicate`; нова подія та нова передача партнеру не створюються:

```sh
curl -i "http://127.0.0.1:8080/postback?click_id=clk_123&event=purchase&amount=49.9"
```

```json
{"status":"duplicate","message":"Event already received"}
```

Невалідні параметри, наприклад порожній `click_id`, повертають `422`:

```sh
curl -i "http://127.0.0.1:8080/postback?click_id=&event=purchase&amount=49.90"
```

Перегляньте події й результат обробки:

```sh
curl "http://127.0.0.1:8080/api/events?per_page=20&page=1"
```

Можна фільтрувати за `status=pending`, `status=sent` або `status=failed`. `amount` у відповіді є точним десятковим рядком. Поле `attempt_count` показує кількість зарезервованих передач партнеру, а `last_error` — короткий контрольований код помилки.

Тестовий партнер за замовчуванням відповідає HTTP 200. Щоб перевірити повтори, у `.env` змініть `MOCK_PARTNER_STATUS=503`, а потім пересоздайте контейнер:

```sh
docker compose --profile mock up -d --force-recreate mock-partner
```

Новий постбек отримає до трьох POST із затримками 1 і 5 секунд та завершиться зі станом `failed`, якщо всі відповіді залишаються 503. Для імітації повільної відповіді задайте `MOCK_PARTNER_DELAY_MS` від 0 до 30000. Після зміни `.env` використовуйте `--force-recreate`, щоб передати нові змінні контейнеру.

Кількість POST тестового партнера можна перевірити так:

```sh
docker compose --profile mock exec mock-partner php -r "echo file_get_contents('http://127.0.0.1:9000/stats'), PHP_EOL;"
```

Лічильник існує лише поки працює контейнер mock-партнера і скидається після його пересоздання.

## Тестування

Повний набір, включно з ізольованими MySQL/Redis інтеграційними тестами, запускається в окремому Compose-проєкті:

```sh
docker compose -p mystery-tag-test-it --profile test run --build --rm test
```

Тестовий Compose-проєкт використовує окремі MySQL `mystery_tag_test` і Redis без опублікованих портів та постійних томів. Тести запускають `migrate:fresh` лише в цій тимчасовій БД; не змінюйте її налаштування на робочу базу. Для запуску лише локальних unit/feature тестів на хості встановіть PHP 8.4 з `pdo_mysql` та Composer, потім виконайте `composer install` і `composer test`.

Після тестування зупиніть та видаліть ресурси ізольованого Compose-проєкту:

```sh
docker compose -p mystery-tag-test-it --profile test down
```

## Дедуплікація та передавання

Fingerprint постбека залежить від точних `click_id`, `event` і канонічної числової суми. Redis тимчасово резервує одночасне приймання, а MySQL зберігає остаточне 24-годинне вікно та прийняту подію. Після приймання job надсилає партнеру POST JSON. Будь-який 2xx є успіхом; мережеві помилки, timeout, 429 і 5xx повторюються, максимум три POST загалом. Інші HTTP 4xx не повторюються.

Тестовий партнер можна замінити: задайте `PARTNER_API_URL` у `.env` на HTTP/HTTPS адресу, доступну з контейнерів, і пересоздайте `app`, `worker` та `scheduler`:

```sh
docker compose up -d --force-recreate app worker scheduler
```

Відповідь `accepted` означає, що сервіс зберіг подію й завдання; вона ще не підтверджує доставку партнеру.

Якщо worker зупиниться після резервування спроби, фактичних POST може бути менше трьох. Якщо партнер отримав POST, але worker зупинився до запису відповіді в MySQL, наступна спроба може повторити доставку. API партнера не надає контракту ідемпотентності, тому сервіс не обіцяє рівно одного отримання події партнером. Завдання, вручну видалене з черги, автоматично не відновлюється.

## Діагностика недоставленої події

1. Перевірте відповідь `GET /postback`, а потім знайдіть подію через `GET /api/events` за `click_id`, `status`, `attempt_count` і `last_error`.
2. Перевірте контейнери та журнали:

   ```sh
   docker compose ps
   docker compose --profile mock logs --tail=100 app worker scheduler mock-partner
   ```

3. Перевірте стан подій і черги в MySQL. Відкрийте shell контейнера, потім клієнт MySQL з обліковими даними контейнера:

   ```sh
   docker compose exec mysql sh
   MYSQL_PWD="$MYSQL_PASSWORD" mysql --user="$MYSQL_USER" --database="$MYSQL_DATABASE"
   ```

   У запрошенні `mysql>` виконайте:

   ```sql
   SELECT id, status, attempt_count, last_error FROM events ORDER BY id DESC LIMIT 20;
   SELECT id, queue, attempts, available_at FROM jobs ORDER BY id DESC LIMIT 20;
   ```

   Вийдіть із MySQL і shell командами `exit` двічі. Потім перевірте Redis:

   ```sh
   docker compose exec redis redis-cli ping
   ```

4. Звірте `PARTNER_API_URL`, мережеву доступність адреси з worker та HTTP-статус партнера. Для локального mock перевірте `/stats` і його журнали. Після зміни `.env` пересоздайте відповідні контейнери.
5. Зіставте очищені журнали за `event_id`. Не записуйте в журнали URL із токенами, заголовки чи тіло відповіді партнера.

`docker compose down` зберігає volume MySQL. Видалення volume стирає локальні події та дані черги.
