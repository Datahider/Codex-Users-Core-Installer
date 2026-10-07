# CodexGate Core Installer

Root-инсталлер single-user Core для CodexGate.

## Новая установка

Pairing-код выдаёт `@cdxgate_bot` после Start или `/install`. Код действует 3 часа.

```bash
curl -fsSL https://codexgate.ru/install-core.php.gz | gunzip | sudo php -- --pair XXXX-XXXX-XXXX-XXXX
```

Неинтерактивный запуск:

```bash
curl -fsSL https://codexgate.ru/install-core.php.gz | gunzip | sudo php -- --pair XXXX-XXXX-XXXX-XXXX --user codex --yes
```

## Контракт установки

Инсталлер обязан:

- работать только от `root`;
- принимать `--pair CODE`, необязательные `--user USERNAME` и `--yes`;
- без `--user` спросить имя Linux-пользователя, по умолчанию `codex`;
- создать обычного Linux-пользователя с `/home/USERNAME`, если его нет;
- для существующего пользователя потребовать явное подтверждение, а в неинтерактивном режиме — `--yes`;
- требовать стандартный home `/home/USERNAME`;
- до погашения кода проверить `git`, `composer`, `codex`, `systemctl`, `runuser` и PHP 8.2+;
- клонировать `Datahider/Codex-Users-Core` в `/home/USERNAME/Codex-Users-Core` и установить production Composer dependencies;
- не копировать и не изменять Codex-авторизацию;
- обменять pairing-код через `POST https://cdx-router.botmeister.ru/api/v1/core/install`;
- не выводить и не передавать Core token в command-line arguments;
- записать `/home/USERNAME/.codex-users-core/config.php` с режимом `0600` и владельцем `USERNAME`;
- установить или обновить `/etc/systemd/system/codex-core@.service` из канонического шаблона инсталлера;
- выполнить `systemctl daemon-reload`, `systemctl enable --now codex-core@USERNAME.service` и проверить `is-active`;
- при ошибке после погашения pairing-кода явно сообщить, что нужен новый код;
- завершаться при любой ошибке без fallback.

Pairing-код погашается только после локальных проверок, клонирования Core и установки dependencies, но до создания config и запуска service.

## Обновление

```bash
sudo php bin/install-core.php --update USERNAME
```

Обновление обязано отказаться при изменённых tracked-файлах, выполнить `git pull --ff-only`, обновить dependencies, повторно установить актуальный unit-шаблон, выполнить `daemon-reload`, перезапустить и проверить service. Config, runtime-данные и binding не изменяются.
