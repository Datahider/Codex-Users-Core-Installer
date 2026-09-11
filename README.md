# Codex Multitenant Tenant Installer

Operations-установщик нового single-user Core и его production-привязок.

## Запуск

```bash
php bin/install-tenant.php /home/web/tmp/tenant.ini
```

INI-файл должен существовать и быть доступен на чтение. Владелец и режим файла не входят в контракт установщика:

```ini
username=natali
telegram_chat_id=-1003979829950
transcription_api_key=sk-...
```

## Контракт

Установщик обязан:

- принять единственным параметром путь к INI-файлу;
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
