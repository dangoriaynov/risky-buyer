# Central sync — план и спецификация

Споделена централна база на проблемни клиенти + синхронизация в плъгина
Problem Client, с подготовка за публикуване в WordPress.org.

> Решения (2026-06-13): сървър = лек **FastAPI** сервиз; **нов отделен домейн**;
> достъп = **четенето е отворено, запис само с ключ + allowlist на домейни**.

---

## 1. Цел и UX

- Всеки сайт с плъгина пази **своя** локален списък (както сега). Това не зависи от сървъра.
- Нова **опция за синхронизация** (по подразбиране ИЗКЛЮЧЕНА — opt-in):
  - **Включена:** при проверка/маркиране локалният списък се **разширява** с телефони от
    сървъра (създадени от други сайтове). Локалните записи си остават непокътнати.
  - **Изключена:** работи само локалното (нищо не изчезва; кешът от сървъра не се ползва).
- **Запис към сървъра** е възможен само ако сайтът е **оторизиран писач** (засега
  само `dobavki.club`) — проверката е **на сървъра** (ключ + allowlist на домейн), не се
  вярва само на това, което казва клиентът.

## 2. Ограничения от WordPress.org (определят дизайна)

Източници: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
[Review Checklist](https://make.wordpress.org/plugins/handbook/performing-reviews/review-checklist/),
[Plugin Assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/),
[Plugin Check](https://wordpress.org/plugins/plugin-check/).

- **Guideline 7 — съгласие:** sync ИЗКЛЮЧЕН по подразбиране (opt-in). Описание на данните в readme.
- **Guideline 6 — SaaS:** услугата трябва да върши реална работа и да е документирана в readme
  с линк към **Terms of Use** → централният сайт ТРЯБВА да има ToS + Privacy Policy.
- **Guideline 8 — без отдалечен код:** обменяме само JSON данни (ОК).
- **Plugin Check** (категория Security задължителна) + **2FA** на акаунта за нови плъгини.
- Външните заявки се описват в readme (External services секция).

## 3. Архитектура

### 3.1 Плъгин
```
Probclient_Blacklist (сервиз)
   ├── Probclient_Local_Table_Provider   ← ВИНАГИ (собствени записи)
   └── Probclient_Remote_Sync (опция)     ← когато sync е включен
         • PULL  GET /v1/entries?since=…  → кеш (таблица probclient_remote_cache) → разширява индекса
         • PUSH  POST/PATCH/DELETE          → само ако сайтът е writer (ключ+allowlist)
```
- `index()`/`match()`/`possible_matches()` обединяват **локални + кеширани отдалечени** телефони.
- PULL по cron (напр. hourly) + ръчно „Sync now". Кешът се пази локално, така че проверката
  е бърза и работи дори при недостъпен сървър (graceful degradation).

### 3.2 Сървър (нов LXC, FastAPI)
- Endpoints (`/v1`):
  - `GET /entries?since=<ts>` → списък (телефон_norm, name_norm, reason, source_site, updated_at, status). **Отворено четене.**
  - `POST /entries` (bulk/single), `PATCH /entries/{uuid}`, `DELETE /entries/{uuid}` → **изисква API ключ със scope write + домейн в allowlist.**
  - `GET /healthz`.
- БД: SQLite (старт) или Postgres. Таблици: `entries`, `api_keys` (key_hash, site_domain, scope, created_at).
- Auth: header `Authorization: Bearer <key>`. На write: ключът валиден, scope=write, `site_domain` ∈ allowlist.
- Rate-limit + размер на заявката; нормализацията на телефон/име = същата като в плъгина.
- Минимална админ-страница за модерация (преглед/триене) — по желание, защитена.

### 3.3 Централен сайт (наполнение)
- Лендинг: какво е услугата (споделен черен списък за наложен платеж / неизкупени пратки),
  кой може да чете/пише, как да се присъединиш.
- **Terms of Use** и **Privacy Policy** (задължителни за readme линковете и Guideline 6/7).
- (Опц.) публична статистика (брой записи), без лични данни.

## 4. Поток на данни / синхронизация
1. Клиент включва sync → въвежда URL + (по желание) ключ.
2. PULL: дърпа `since` инкрементално → ъпсърт в `probclient_remote_cache` (status=removed → махане от индекса).
3. Проверка обединява local + cache.
4. dobavki.club (writer): при добавяне/триене локално → PUSH към сървъра (фонова опашка/cron, idempotent по uuid).
5. Конфликти: по `uuid` + `updated_at`; собственост по `source_site`.

## 5. Инфраструктура (homelab) — с разузнаване, без отгатване
1. Създаване на LXC `pcblacklist` (до останалите, 10.48.230.x).
2. **Първо да се види как работи `nginx-proxy` (10.48.230.104)** — как другите контейнери
  получават vhost + TLS — и да се повтори същият патерн.
3. DNS за новия домейн → публичния вход на homelab (да се потвърди как точно сайтовете
  гледат навън).
4. TLS по същия механизъм (вероятно Let's Encrypt в proxy).
5. Деплой на FastAPI (systemd/uvicorn зад proxy), бекъп на БД.

## 6. Готовност за WordPress.org — оставащо
- [x] Уникален префикс, i18n (+bg), HPOS, GPL, uninstall, меню, readme каркас.
- [ ] Sync **opt-in** + Settings страница с описание на данните и линкове ToS/Privacy.
- [ ] Readme: секция **External services** (какви данни, кога, ToS/Privacy URL).
- [ ] **Plugin Check** — да мине (особено Security).
- [ ] **Assets** в `/assets` на SVN: `icon-256x256.png`, `banner-772x250.png` (+ retina
  `banner-1544x500.png`), `screenshot-1..N.png` (по номерата в readme).
- [ ] Финален **zip** (без `tests/`, `docs/`, `.git`; с `languages/`).
- [ ] 2FA на акаунта + сабмит през формата (от потребителя).

## 7. Фази
1. **Плъгин: sync-каркас** — `Probclient_Remote_Sync` + Settings (toggle/URL/ключ), read-extend
  срещу мок/стъб (работи и без сървър — деградира до локално).
2. **Сървър** — LXC + FastAPI + БД + сайт (лендинг/ToS/Privacy).
3. **Интеграция** — dobavki.club с write-ключ; останалите само четат.
4. **WP.org финал** — readme external-services, Plugin Check, assets, zip.

## 8. Отворени въпроси
- **Име на домейна** (нов): да се избере и регистрира. Идеи: `codshield.*`, `badclients.*`,
  `neizkupeno.*`, `clientguard.*`, `chernsписък→chernlist.*`. До регистрация — dev на вътрешен IP.
- SQLite vs Postgres за старта (предложение: SQLite, лесна миграция после).
