# AGENTS.md

## Проект

- `Tenant-Installer` — отдельный operations-проек семейства `Codex-Multitenant`.
- Проект создаёт новый single-user Core и связывает его с production Router и Transport-Telegram.

## Границы

- Проект не содержит runtime-логику Core, Router или transport.
- Проект не копирует и не изменяет Codex-авторизацию tenant.
- Секреты не должны передаваться в command-line arguments дочерних процессов.
- Ошибки завершают установку; fallback запрещён.

## Разработка

- Изменения начинать с `README.md`, затем добавлять fail-first тест, затем код.
- Перед кодовым шагом проверять чистоту git-дерева.
