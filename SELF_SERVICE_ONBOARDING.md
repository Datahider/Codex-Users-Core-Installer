# Self-Service Onboarding Concept

## Product Model

- Единица тарификации — одно подключённое Core.
- Одному владельцу разрешено оплачивать несколько Core.
- Каждое Core имеет отдельную подписку и отдельный Router principal.
- Количество Telegram private chats, groups, supergroups и forum topics,
  привязанных к оплаченному Core, не ограничивается.
- Forum topic не требует отдельной привязки: он наследует Core от своей
  group/supergroup, а его `thread_id` продолжает создавать отдельную runtime
  session.
- Поддерживаются два deployment mode:
  - `self_hosted`: Core работает на сервере владельца;
  - `hosted`: Core разворачивается на нашей инфраструктуре.
- Приоритет первой реализации — полностью самостоятельный `self_hosted`
  onboarding.

## Identity Model

- Владелец определяется по неизменяемому Telegram `user_id`, а не по
  `username`.
- Оплачиваемая сущность называется `core_slot`.
- У `core_slot` есть стабильный внутренний `core_id`, deployment mode,
  состояние подписки и состояние подключения Core.
- Transport binding связывает Telegram target с `core_id`, а не с username.
- Один target одновременно связан ровно с одним `core_id`.
- Один `core_id` может иметь любое количество target bindings.
- Если у владельца несколько Core, при привязке target он явно выбирает Core.
  Автоматический выбор допустим только когда доступно ровно одно активное Core.

## Self-Hosted Flow

1. Пользователь открывает private chat с ботом и выполняет `/start`.
2. Бот создаёт или находит account по Telegram `user_id`.
3. Пользователь выбирает создание нового self-hosted Core.
4. Onboarding создаёт счёт Robokassa для нового `core_slot`.
5. Core slot не активируется по browser redirect. Он активируется только после
   валидного server-to-server уведомления Robokassa.
6. После оплаты бот выдаёт короткоживущий одноразовый pairing code и инструкцию
   установки Core.
7. Пользователь устанавливает Core на своём сервере и локально авторизует
   Codex. Codex credentials не передаются Router, Transport или Onboarding.
8. Core обменивает pairing code на отдельный bearer token Router.
9. Pairing code атомарно помечается использованным. Повторный обмен запрещён.
10. Core подтверждает готовность успешным authenticated heartbeat.
11. Core slot переходит в `ready`, после чего к нему можно привязывать Telegram
    targets и направлять ingress.

## Hosted Flow

1. Account и Robokassa payment создаются так же, как для self-hosted режима.
2. После подтверждения оплаты provisioning job вызывает штатный hosted
   installer.
3. Installer создаёт отдельного Linux user, Core config и Router principal.
4. Codex authorization выполняется отдельным явно управляемым этапом. Состояние
   `ready` запрещено до подтверждения работоспособности авторизованного Core.
5. Существующий `Tenant-Installer` остаётся operations boundary и не становится
   публичным HTTP endpoint.

## Private Chat Binding

- Первый private chat владельца может быть привязан к Core после оплаты и
  готовности Core.
- При единственном активном Core привязка выполняется явно одной кнопкой.
- При нескольких Core бот требует выбор.
- Для смены Core требуется явная команда и подтверждение. Скрытое
  перепривязывание запрещено.

## Group And Supergroup Binding

1. В private chat владелец выбирает оплаченный и готовый Core и запрашивает
   group pairing code.
2. Onboarding создаёт cryptographically random 256-bit token, представленный
   в URL-safe Base64 без padding. Token действует 15 минут и не имеет лимита
   использований в пределах этого срока.
3. Владелец добавляет bot в нужные groups/supergroups и отправляет token обычным
   сообщением или аргументом команды `/pair`.
4. Transport передаёт token и target identity в Onboarding.
5. Onboarding проверяет hash token, срок, активность подписки и готовность
   выбранного Core.
6. Onboarding атомарно создаёт новый binding target -> `core_id`. Token не
   погашается после привязки и может использоваться в других группах до
   истечения срока.
7. Transport удаляет сообщение с token, если Telegram разрешает удаление, и
   публикует подтверждение без повторения token.
8. После каждой успешной привязки bot открывает владельцу в private chat
   актуальный постраничный список всех bindings выбранного Core, начиная с
   только что добавленной группы.

Group pairing token является временным bearer capability. Telegram identity
отправителя в группе не доказывает владение Core и отдельно не проверяется:
право на привязку подтверждается самим token.

В базе хранится только cryptographic hash token. Полное значение не попадает в
логи, ошибки или повторные ответы bot. Для token не хранится и не изменяется
счётчик использований.

Token разрешает только создание нового binding. Он не может переписать уже
существующий binding, отвязать target, управлять Core или выпускать Router
credentials. Повторное добавление bot также не меняет binding. Перепривязка
группы требует отдельной явной операции.

Компрометация token в пределах 15 минут позволяет привязать чужую группу к
Core владельца. Владелец видит результат каждой привязки в актуальном списке и
может отозвать token или отвязать группу. После истечения срока token становится
недействительным.

## Binding List And Unpair

- Команда `/unpair` в private chat показывает bindings выбранного Core.
- Список не ограничивает общее количество bindings и выводится страницами.
- На странице показывается столько targets, сколько полностью помещается в
  Telegram message. Inline-кнопки `Назад` и `Дальше` позволяют пройти весь
  список.
- Каждый target показывается отдельной строкой с полным Telegram title. Title
  является текстом постоянной deep link вида
  `https://t.me/<bot>?start=unpair_<chat_id>_<panel_message_id>`.
- Telegram `chat_id` не считается секретом и передаётся в start payload
  напрямую. Ссылка не имеет собственного срока жизни.
- После перехода Telegram создаёт в private chat команду `/start` с payload.
  Bot проверяет, что отправивший её Telegram `user_id` владеет `core_id`, к
  которому сейчас привязан указанный `chat_id`. Знание или перебор чужого
  `chat_id` не даёт права управления.
- Bot удаляет сообщение с `/start` и редактирует исходное panel message, ID
  которого передан в payload.
- Panel message показывает полный title и `chat_id` выбранной группы. Под ним
  находятся inline-кнопки управления, включая `Отвязать` и `Назад к списку`.
- Отвязка выполняется только после отдельного явного подтверждения inline-
  кнопкой.
- Чтобы получить `panel_message_id` для deep links, bot сначала отправляет
  пустую panel-заготовку, получает её Telegram message ID и затем редактирует
  её в список.
- После привязки bot показывает тот же постраничный список, что и `/unpair`, с
  новой группой на первой позиции.
- Telegram UI не задаёт максимальное количество групп: одновременно
  отображается только одна страница.

## Subscription And Routing Rules

- Подписка относится к `core_slot`, а не к Telegram target.
- Routing разрешён только при одновременно выполненных условиях:
  - subscription state равен `active`;
  - Core connection state равен `ready`;
  - target имеет binding к этому `core_id`.
- Истёкшая подписка блокирует новый ingress для всех bindings Core, но не
  удаляет bindings, tenant data или Core credentials.
- После продления routing возобновляется без повторного pairing групп.
- Удаление Core и отвязка target — отдельные явные операции.

## Robokassa Contract

- Для каждого нового платёжного периода создаётся отдельный invoice с нашим
  уникальным `invoice_id` и ссылкой на конкретный `core_slot`.
- Источником истины об оплате является подписанное server-to-server
  уведомление (`ResultURL`/`ResultUrl2`), а не `SuccessURL`.
- Обработка уведомления идемпотентна по invoice ID.
- Сумма, merchant и подпись проверяются до изменения subscription state.
- Секреты Robokassa не передаются в Transport, Core, URL или логи.
- Автоматические recurrent charges включаются только если эта услуга отдельно
  активирована в Robokassa. До подтверждения используется оплата каждого
  периода новым invoice.

## Component Boundaries

- `Transport-Telegram`:
  - принимает Telegram updates;
  - передаёт Telegram identity и предъявленный group pairing token;
  - показывает onboarding UI;
  - не принимает решения об оплате и не создаёт Router core tokens.
- `Onboarding`:
  - хранит accounts, core slots, invoices, pairing и group claims;
  - интегрируется с Robokassa;
  - оркестрирует hosted provisioning;
  - является источником entitlement state.
- `Router`:
  - создаёт и аутентифицирует Core principals;
  - хранит routing bindings по `core_id`;
  - применяет entitlement при ingress;
  - не содержит Telegram API и Robokassa client.
- `Core`:
  - выполняет pairing;
  - хранит выданный Router token;
  - сообщает heartbeat;
  - хранит Codex authorization только локально.

## Required State Machines

`core_slot`:

`awaiting_payment -> paid -> pairing -> ready -> suspended`

`invoice`:

`created -> paid | expired | cancelled`

`core_pairing_code`:

`active -> consumed | expired`

`group_pairing_token`:

`active -> expired | revoked`

Переходы выполняются атомарно. Повтор события не создаёт второй Core, invoice,
token или binding.

## First Delivery Slice

1. Private Telegram account identity.
2. Robokassa one-period invoice and verified callback.
3. One self-hosted `core_slot` per invoice.
4. One-time Core pairing and Router token issue.
5. Core heartbeat and `ready` state.
6. Unlimited private/group bindings to that Core.
7. Reusable group pairing token with unlimited uses during 15 minutes.
8. Forum topics routed automatically through their parent group binding.

Hosted provisioning, recurrent charges and multiple-Core selection follow after
the first self-hosted slice, while preserving the data model above.

## Decisions Still Required Before Endpoint Contracts

- Цена и длительность одного оплачиваемого периода.
- Grace period после окончания оплаты.
- Подтверждение, активированы ли recurrent payments в кабинете Robokassa.
- Публичный hostname для Onboarding callback и installation instructions.
