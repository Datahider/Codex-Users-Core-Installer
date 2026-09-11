# Codex Multitenant Tenant Installer

Operations-установщик нового single-user Core и его production-привязок.

## Запуск

```bash
php bin/install-tenant.php /home/web/tmp/tenant.ini
```

Без параметров и с `--help` установщик показывает usage, полный пример INI и команду создания шаблона.

Создать INI-шаблон:

```bash
php bin/install-tenant.php --create /home/web/tmp/tenant.ini
php bin/install-tenant.php -c /home/web/tmp/tenant.ini
```

Существующий файл эта команда не перезаписывает.

Обновить уже установленный Core:

```bash
php bin/install-tenant.php --update USERNAME
php bin/install-tenant.php -u USERNAME
```

Обновление обязано отказаться при изменённых tracked-файлах. При чистом дереве оно выполняет `git pull --ff-only`, повторно устанавливает production-зависимости, перезапускает и проверяет service. Персональный config, runtime-данные, Router и Telegram-привязки не изменяются.

INI-файл должен существовать и быть доступен на чтение. Владелец и режим файла не входят в контракт установщика:

```ini
username=<username>
telegram_chat_id=<telegram_chat_id>
transcription_api_key=<transcription_api_key>
```

## Контракт

Установщик обязан:

- принять единственным параметром путь к INI-файлу;
- показывать без параметров и с `--help` usage, пример INI и `-c|--create PATH`;
- по `-c|--create PATH` создавать новый INI-шаблон и отказываться перезаписывать существующий файл;
- по `-u|--update USERNAME` проверять tracked-файлы Core, выполнять только fast-forward update, обновлять Composer-зависимости и перезапускать service;
- отклонить несуществующий или нечитаемый файл, неизвестные или невалидные поля;
- не передавать `transcription_api_key` в command-line arguments;
- создать Linux-пользователя штатной командой ограниченного sudo;
- клонировать `Datahider/Codex-Users-Core` из remote в `/home/USERNAME/Codex-Users-Core`;
- установить production Composer-зависимости;
- создать config с отдельным случайным `core_token`, переданным API key и `--dangerously-bypass-approvals-and-sandbox`;
- не копировать и не изменять Codex-авторизацию;
- записать tenant и SHA-256 core token в production Router;
- записать `TELEGRAM_CHAT_ID -> USERNAME` в production Transport-Telegram;
- включить, перезапустить и проверить `codex-core@USERNAME.service`;
- завершаться при любой ошибке без fallback.
