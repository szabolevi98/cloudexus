# ☁️ Cloudexus

**Felhő alapú, moduláris ügyviteli és vállalatirányítási rendszer.**

A Cloudexus egy helyen kezeli a teljes kereskedelmi folyamatot: részletes terméktörzs és partnerkezelés, több raktáras készlet- és raktárkezelés vonalkódos rögzítéssel, raktárközi átadással és leltározással, vevői rendeléstől a számlázásig, beszerzéstől a bejövő számláig, pénztár és számlakiegyenlítés, valamint CRM teendők és ügyfélkapcsolat-történet. Mindezt egy letisztult, valós idejű vezérlőpult, keresők, szűrők és riportok fogják össze — kifejezetten nagy raktárkészlettel dolgozó, aktív kereskedelmi tevékenységet folytató vállalkozásokra szabva.

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-11.4-003545?logo=mariadb&logoColor=white)
![Twig](https://img.shields.io/badge/Twig-3.x-8BC34A?logo=symfony&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)
![Status](https://img.shields.io/badge/St%C3%A1tusz-akt%C3%ADv%20fejleszt%C3%A9s-blue)

![Cloudexus vezérlőpult](Cloudexus.png)

---

## ✨ Fő funkciók

### 📊 Vezérlőpult
- Valós idejű forgalmi grafikon (Chart.js) az elmúlt 10 nap rendeléseiről
- Kintlevőség és kötelezettség kártyák **lejárt / nem lejárt** bontásban, egy kattintásra szűrt listával
- Top termékkategóriák 30 napos értékesítési érték szerint
- Legutóbbi számlák gyorsáttekintés

### 📦 Törzsadatok
- Terméktörzs cikkszámmal, vonalkóddal, kategóriafával, választható mennyiségi egységgel, méretekkel és élő készletadattal
- Rövid/hosszú leírás (önállóan hosztolt TinyMCE szerkesztővel), több kép (feltöltés vagy URL), termékparaméterek, több kategória, kapcsolódó/helyettesítő termékek
- Nettó ár + ÁFA, opcionális **akciós ár**, és **vevőcsoportos árazás**: egy partner egy vevőcsoporthoz tartozhat, a csoportnak pedig termékenként saját fix ára (és akciós ára) lehet, amit a rendelés/számla tételsora automatikusan figyelembe vesz
- Partnertörzs (vevő / szállító / mindkettő) adószámmal, elérhetőségekkel és vevőcsoport-hozzárendeléssel
- Kereshető, szűrhető, lapozható listák minden modulban, nagy listákhoz (termékek, kategóriák, partnerek) önállóan hosztolt Select2 AJAX kereséssel
- Pénznemek elsődleges pénznemmel és váltószámokkal; az összegek mindig az elsődleges pénznemben jelennek meg
- Nyelvenként tárolt terméknév, leírások, kategória-, mértékegység- és paraméternevek, nyelvi fülekkel a szerkesztőkben

### 🏭 Készletkezelés
- Több raktár és telephely kezelése
- Raktári bevét és kiadás bizonylatolása (túladás elleni védelemmel)
- **Raktárközi átadás** tranzakcionális ki-/bevét párokkal
- Élő raktárkészlet-összesítő raktár- és termékszűrővel
- A készlet mindig a mozgásokból számolódik — konstrukciójából adódóan konzisztens

### 🧾 Értékesítés
- Vevői rendelések dinamikus tételsorokkal, automatikus árkitöltéssel és élő végösszeg-számítással
- Számlázás önállóan vagy **egy kattintással rendelésből** (tételek előtöltve)
- Opcionális automatikus raktári kiadás számlázáskor, készletellenőrzéssel
- **ÁFA soronként** a termék kulcsával: nettó, ÁFA és bruttó összeg, ÁFA-összesítő kulcsonként, a szállítási és a fizetési költség is ÁFÁ-val; forintnál egész összegekre kerekítve
- A vevő és az eladó neve, adószáma és címe **a kiállításkor rögzül** a számlán; teljesítési dátum és fizetési mód (átutalás, készpénz, bankkártya, utánvét)
- **Sztornó számla**: kiállított számlát nem törlünk és nem írunk át, hanem ellentételező bizonylattal vonunk vissza — a kiadott áru visszakerül a raktárba, a rendelés újra számlázható lesz
- Egy rendelésből egy élő számla; számla-életciklus: fizetésre vár → kifizetve / sztornózva, lejárt kiemeléssel
- **Hézagmentes, évenkénti sorszámozás** minden bizonylatnál (számla, rendelés, beszerzés, bejövő számla, pénztár, leltár): a sorszámot a mentés adja, zárolt számlálóból, így két egyidejű mentés sem ütközik, és törölt bizonylat száma sem kerül újra kiosztásra

### 🚚 Beszerzés
- Szállítói rendelések és bejövő számlák
- Bejövő számla rögzítésekor **automatikus raktári bevét** a választott raktárba
- Rendelésből előtöltött számlázás a vevői oldallal azonos élménnyel

### 💰 Pénztár
- Bevételi és kiadási pénztárbizonylatok
- Számlához kapcsolt bizonylat = automatikus kiegyenlítés (a számla kifizetetté válik)
- Élő pénzkészlet-egyenleg

### ⚙️ Rendszer
- Bejelentkezés felhasználónévvel vagy e-maillel, bcrypt jelszó-hash
- Score-alapú Google reCAPTCHA v3 a bejelentkezésen (configból ki-/bekapcsolható)
- Szerepkörök és jogosultság-mátrix: hat beépített szerepkör (szuper admin, vezető, pénzügy, értékesítő, raktáros, csak olvasó) és saját szerepkörök; a mátrixban műveletenként állítható, ki mit tehet (pl. számla kiállítása, sztornó, kifizetettnek jelölés, készletmozgás)
- A jogosultságot a szerver ellenőrzi minden kérésnél, a mobil API-n is; a menü és a gombok csak azt mutatják, amit a szerepkör elérhet, és egy szerepkör-változás a következő kattintástól él
- A szuper admin mindenhez hozzáfér, és az utolsó aktív szuper admin nem veszítheti el a szerepkörét, így a rendszer nem zárható ki
- Audit napló: belépések, sikertelen belépések, elutasított hozzáférések, jogosultság- és felhasználóváltozások (mit adott hozzá, mit vett el), számla kiállítása, sztornó, kifizetés, pénztár, leltár, cégadatok; szűrhető felhasználóra, műveletre, tárgyra és dátumra
- Saját profil és jelszóváltás
- CSRF-védelem minden űrlapon, HttpOnly + SameSite session süti
- Világos és sötét téma, vagy a rendszer beállítása szerint; a választás sütiben marad meg, és a belépőoldalra is érvényes
- Kétnyelvű felület (magyar / angol) nyelvváltóval, a választás sütiben megjegyezve; a nyelvválasztás a törzsadatok szövegeire is érvényes (lásd [Adatok nyelvesítése](#-adatok-nyelvesítése)), a partner- és rendelésadatok viszont nem fordulnak
- Pénznemek kezelése elsődleges pénznemmel és váltószámokkal, MNB közép­árfolyam-lekéréssel (gombbal vagy cronból)
- Nyelvek kezelése alapnyelv-kijelöléssel; a fordítható törzsadatok nyelvenként tárolódnak, hiányzó fordítás esetén az alapnyelv jelenik meg

---

## 🏢 Működési modell

A Cloudexus az *1 telepítés = 1 cég* modellt követi: minden vállalat a saját, elkülönített példányát futtatja, a felhasználók pedig ugyanazon cég munkatársai. Így nincs bérlők közti adatmegosztás, és a rendszer teljesen az adott vállalat igényeire hangolható.

---

## 🛠️ Technológia

| Réteg | Megoldás |
|---|---|
| Backend | PHP 8.4, egyedi könnyűsúlyú MVC (framework nélkül) |
| Adatbázis | MySQL / MariaDB, PDO prepared statement-ekkel |
| Sablonozás | [Twig 3](https://twig.symfony.com/) |
| Frontend | [Bootstrap 5.3](https://getbootstrap.com/) + [Bootstrap Icons](https://icons.getbootstrap.com/) + [Select2](https://select2.org/) + egyedi CSS design-rendszer |
| Grafikonok | [Chart.js 4](https://www.chartjs.org/) |
| Szövegszerkesztő | [TinyMCE](https://www.tiny.cloud/) (termékleírásokhoz) |
| Levelezés | [PHPMailer](https://github.com/PHPMailer/PHPMailer) (tranzakciós e-mailekhez) |

> Minden front-end függőség lokálisan, a `web/assets/vendor/` mappából töltődik be — a rendszer internet nélkül is működik. Egyetlen kivétel a bejelentkezés opcionális Google reCAPTCHA v3 védelme, ami internetkapcsolatot igényel; ez configból teljesen kikapcsolható.

A publikus dokumentumgyökér kizárólag a `web/` mappa — minden alkalmazáskód, konfiguráció és futásidejű adat azon kívül él.

## 🚀 Telepítés

**Követelmények:** PHP ≥ 8.4 (`pdo_mysql`), MySQL/MariaDB, Composer, Apache (mod_rewrite).

```bash
# 1. Klónozás
git clone https://github.com/szabolevi98/Cloudexus.git
cd Cloudexus

# 2. Függőségek
composer install

# 3. Konfiguráció
cp config/config.ini.dist config/config.ini
#    → állítsd be az adatbázis-elérést, a base_url-t, és opcionálisan a
#      [recaptcha] szekció kulcsait (enabled = 0 esetén a reCAPTCHA kikapcsol)

# 4. Adatbázis-séma
php database/migrate.php

# 5. Admin felhasználó
php database/create_admin.php admin titkosjelszo

# 6. (Opcionális) Gazdag demo adatok
php database/seed_demo.php
```

Ezután nyisd meg a `config.ini`-ben beállított `base_url`-t (pl. `http://localhost/Cloudexus/web`), és jelentkezz be.

> A `database/seed_demo.php` bármikor újrafuttatható: minden üzleti táblát kiürít (a felhasználókat nem), és valósághű magyar demo adatokkal tölti fel — 48 termék (köztük akciós áras és vevőcsoportos áras is), 17 partner, 3 vevőcsoport, 3 raktár, 300+ készletmozgás (köztük raktárközi átadások), 130 rendelés számlákkal és kiegyenlítésekkel, valamint 3 pénznem (forint elsődlegesként, euró, dollár). A terméknevek, leírások és kategóriák magyarul és angolul is felkerülnek, hogy a nyelvváltás azonnal látszódjon.

## 📁 Projektstruktúra

```
Cloudexus/
├── bin/
│   ├── build_css.php    # a rétegzett CSS forrásokat egyesíti web/assets/css/app.css-be
│   ├── clear_cache.php  # var/cache kiürítése (opcionálisan log és session is)
│   └── sync_currency_rates.php  # MNB közép-árfolyamok frissítése (cronhoz)
├── config/              # config.ini (gitignore-olt) + .dist sablon
├── database/
│   ├── core/            # sorszámozott SQL migrációk; a korábbi 24 fájl egy
│   │                    #   induló 01_base.sql-be lett összevonva, innentől
│   │                    #   új sémaváltozás új, rákövetkező fájlként kerül be
│   ├── migrate.php      # migrációfuttató
│   ├── create_admin.php # kezdő admin létrehozása
│   └── seed_demo.php    # újrafuttatható demo-adat generátor
├── src/
│   ├── Core/            # Config, DB, Router, Session, Auth, Csrf, Paginator,
│   │                    #   Lang (feliratok), Language + Translation (adatnyelv), Currency, …
│   ├── Controller/      # egy controller / erőforrás
│   ├── Language/        # hu/ és en/, szekciónkénti nyelvi fájlok (common, nav, products, …)
│   ├── Model/           # modulonként: Core, Account, Sales, Purchasing, Cash
│   └── View/
│       ├── Twig/        # sablonok (közös layout + modul-nézetek)
│       └── Css/         # rétegzett forrás-CSS (base/layout/components/pages)
├── var/                 # cache + naplók (gitignore-olt)
└── web/                 # publikus gyökér: index.php front controller + assetek
```

> A CSS-t nem Sass/bundler építi: a `src/View/Css/` alatti fájlokat a `php bin/build_css.php`
> fűzi össze egyetlen `web/assets/css/app.css`-be, fix sorrendben. CSS-módosításnál mindig a
> forrásfájlokat szerkeszd, utána futtasd a scriptet — az `app.css`-t sose kézzel.

> Éles környezetben (`debug = 0`) a Twig a lefordított sablonokat a `var/cache/` alá írja.
> Sablon- vagy fordításmódosítás után a `php bin/clear_cache.php` üríti ezt. Alapból csak a
> cache-t törli; a `--logs` a naplókat, a `--sessions` a session fájlokat is (utóbbi mindenkit
> kiléptet), a `--all` mindhármat, a `--dry-run` pedig csak kilistázza, mit törölne.

> A feliratokat a sablonok `{{ t('domain.kulcs') }}`, a controllerek `$this->t('domain.kulcs')`
> hívással kérik; a szövegek a `src/Language/<nyelv>/<domain>.php` fájlokból jönnek. Új nyelvhez
> elég egy új mappa és a `config.ini`-ben az `available_locales` bővítése — a hiányzó kulcsok az
> alapnyelvre, majd magára a kulcsra esnek vissza, így a hiányok láthatók, de nem törik el az oldalt.

## 🧩 Adatmodell-elvek

- **A törzsadatokra mindig külső kulcs mutat, nem szöveg.** A termék mértékegysége
  `products.unit_id` → `units`, a termékparaméterek neve
  `product_parameters.parameter_id` → `parameters`; szabad szöveg csak az érték
  (`product_parameters.value`). A listák és a REST API a feloldott nevet is visszaadják
  (`unit`, `attr_name`), így a felület és az integrációk nem látják a normalizálást.
- **A fordítható szöveg nincs a base táblán.** A `products`, `categories`, `units` és
  `parameters` táblák nem tartalmaznak nevet/leírást — azok a `*_description` táblákban
  vannak, nyelvenként egy sorral (lásd [Adatok nyelvesítése](#-adatok-nyelvesítése)).
- **A kiállított bizonylat nem hivatkozik élő szövegre.** A tételsorok rögzítéskor
  eltárolják a termék akkori nevét, cikkszámát és mértékegységét (`product_name`,
  `product_sku`, `unit_code`), így egy átnevezés vagy nyelvváltás nem írja át a régi
  bizonylatokat. A készletmozgás és a leltár szándékosan élőben old fel.
- **Minden táblán van `created_at` és `updated_at`**, `DEFAULT CURRENT_TIMESTAMP`
  illetve `ON UPDATE CURRENT_TIMESTAMP` beállítással, tehát az adatbázis tartja
  karban őket, PHP-oldali kód nélkül. Erre épül a REST API `updated_since` szűrője.

> A `database/migrate.php` a `database/core/` alatti összes `.sql` fájlt minden futtatáskor
> újra lefuttatja, ezért mindegyiknek idempotensnek kell lennie (`IF [NOT] EXISTS`,
> `INSERT IGNORE`). A séma jelenleg egyetlen fájlban van (`01_base.sql`); ha később bővül,
> az új darab is kövesse ezt a szabályt.

## 🌐 Adatok nyelvesítése

A felület feliratain túl a **törzsadatok szövegei is nyelvenként tárolódnak**, az
OpenCart mintájára külön `*_description` táblákban:

| Tábla | Fordítható mezők |
|---|---|
| `product_description` | terméknév, rövid és hosszú leírás |
| `category_description` | kategórianév és leírás |
| `unit_description` | mértékegység megnevezése (a kód nem fordul) |
| `parameter_description` | paraméternév |
| `product_parameters` | a paraméter értéke (`language_id` oszloppal, nem külön táblában) |

A nyelvek a **Beállítások → Nyelvek** oldalon vehetők fel (megnevezés, kód, sorrend,
állapot), az alapnyelvet a `settings.language.default` kulcs tartja — ugyanúgy, ahogy
a pénznemeknél az elsődleges pénznemet.

- **Egy nyelvváltó mindenre.** A topbar (és a bejelentkezés) nyelvváltója a `languages`
  táblából töltődik, és egyszerre állítja a felirat és az adat nyelvét. Ha egy nyelvhez
  nincs `src/Language/<kód>/` mappa, a feliratok az alapnyelven maradnak, az adatok
  viszont már az új nyelven jönnek.
- **Hiányzó fordítás esetén az alapnyelv látszik.** Minden lekérdezés két nyelvet kap
  (a választottat és az alapnyelvet) és `COALESCE`-szal veszi a szöveget, így soha nem
  jelenik meg névtelen termék. A `src/Core/Translation.php` adja ehhez az SQL-töredékeket.
- **A kiállított bizonylat nem változik.** A rendelés, számla, szállítói rendelés és
  bejövő számla tételsora rögzítéskor eltárolja a termék akkori nevét, cikkszámát és
  mértékegységét (`product_name`, `product_sku`, `unit_code`) az alapnyelven — mint az
  OpenCart `order_product` táblája. Így sem a nyelvváltás, sem egy későbbi átnevezés nem
  írja át visszamenőleg. A készletmozgás és a leltár szándékosan élőben oldja fel a
  nevet: azok belső üzemi nyilvántartások, nem kiadott dokumentumok.
- A szerkesztő űrlapokon a terméknél és a kategóriánál **nyelvenkénti fülek** vannak, a
  mértékegység- és paraméterlistákon soronként nyelvenként egy mező. A kötelező kitöltés
  csak az alapnyelvre vonatkozik.
- A partner- és rendelésadatok szándékosan **nem** fordíthatók (az OpenCartban sem azok).

## 💱 Pénznemek

A **Beállítások → Pénznemek** oldalon vehetők fel a pénznemek (megnevezés, három betűs
ISO-kód, jelölés, váltószám). Pontosan egy pénznem az **elsődleges**, ezt a `settings`
táblában a `currency.primary` kulcs tartja.

- Az alkalmazás **minden összeget az elsődleges pénznemben tárol és jelenít meg** — a
  korábban beégetett `Ft` helyére az elsődleges pénznem jelölése kerül. A sablonokban ez a
  `{{ összeg|money }}` szűrő, beviteli mezők címkéjéhez a `{{ currency_symbol() }}` függvény.
- A többi pénznem váltószáma **csak tájékoztató**: nincs pénznemváltó a felületen, és a
  tárolt adatokat sem írja át semmi.
- A `value` az OpenCart logikáját követi: **1 elsődleges egység ennyi az adott pénznemben**
  (az elsődlegesnél mindig `1`). Átszámítás tehát `összeg * value`.

Az **„MNB közép árfolyam lekérése”** gomb az összes nem elsődleges pénznem váltószámát az MNB
aktuális középárfolyama alapján állítja be. Ugyanez ütemezve is futtatható:

```bash
# hétköznap reggel 7:10 (az MNB délelőtt teszi közzé az aznapi árfolyamot)
10 7 * * 1-5 php /var/www/cloudexus/bin/sync_currency_rates.php --quiet
```

A pontos, bemásolható crontab sort a Pénznemek oldal is kiírja a telepítés valódi útvonalával.
A szkript `0` kilépési kóddal jelzi a sikert, `1`-gyel a hibát, a részletek a `var/log/` alá
kerülnek. Az MNB minden árfolyamot forintban jegyez, ezért az átszámítás a forinton keresztül
történik — így akkor is helyes, ha nem a forint az elsődleges pénznem.

## 📱 Mobil / PDA app

A raktári bevételt, kiadást és raktárközi átadást a raktárosok telefonról vagy kézi vonalkódolvasós PDA-ról
végzik a **[Cloudexus Mobile](https://github.com/szabolevi98/cloudexus-mobile)** Android appal, a saját
felhasználónevükkel. A mozgás az ő nevükre könyvelődik, és azonnal látszik a webes felületen. Az APK a
[Releases](https://github.com/szabolevi98/cloudexus-mobile/releases) oldalról tölthető le; az app a lenti
REST API-t használja.

## 🔌 REST API

Külső integrációkhoz (pl. webshop-szinkronizáláshoz) és a mobil / PDA raktári apphoz van egy
REST API, `Bearer` tokenes hitelesítéssel. Az integrációs tokeneket az **API → API-felhasználók**
admin oldalon lehet létrehozni és kezelni; a munkatársak a saját felhasználónevükkel és jelszavukkal
kapnak személyes tokent (`POST /api/auth/login`).

- Olvasásra: teljes katalógus (termékek, kategóriák, mértékegységek, paraméterek,
  pénznemek, nyelvek, készlet, számlák, árazás)
- Írásra is: partnerek és rendelések teljes CRUD-dal
- Raktári mozgások: vonalkódos termékkeresés, bevét, kiadás és raktárközi átadás tételsorokkal,
  a bejelentkezett munkatárs nevére könyvelve; az `Idempotency-Key` fejléc miatt a gyenge wifin
  újraküldött kérés sem könyvel kétszer
- Az összegek mindig az elsődleges pénznemben jönnek (`meta.currency`); a fordítható
  szövegek nyelve az opcionális `?language=` paraméterrel választható (`meta.language`)
- A válaszok és hibaüzenetek angolul érkeznek

A teljes végpontlista, kérés/válasz formátumok és curl-példák a [web/API.md](web/API.md)
fájlban vannak, a felületen pedig az **API → API dokumentáció** menüpont alatt is
elérhetők.

## 🗺️ Roadmap

**Kész:**

- [x] Bejelentkezés, szerepkör-alapú jogosultság, felhasználó- és profilkezelés
- [x] Törzsadatok: termékek (vonalkóddal, minimum készlettel), kategóriák, partnerek
- [x] Készletkezelés: bevét, kiadás, raktárközi átadás, raktárkészlet-áttekintés
- [x] Vonalkód gyűjtő (kézi leolvasós tömeges készletrögzítés)
- [x] Leltározás készletkorrekcióval
- [x] Értékesítés: vevői rendelés → számlázás, nyomtatható számla
- [x] Beszerzés: szállítói rendelés → bejövő számla automatikus bevéttel
- [x] Pénztár: bizonylatok, számla-kiegyenlítés, pénzkészlet
- [x] CRM: teendők (feladatlista határidővel, partnerhez/felelőshöz kötve)
- [x] Vezérlőpult: forgalmi grafikon, kintlevőség/kötelezettség, top kategóriák, alacsony készlet, teendők
- [x] Keresés, szűrők, lapozás, CSV export a listákban
- [x] CSRF-védelem, cégadat-beállítások

- [x] Részletes terméktörzs: rövid/hosszú leírás (önállóan hosztolt TinyMCE), képek (feltöltés + URL), paraméterek, több kategória, kapcsolódó/helyettesítő termékek, méretek, nettó ár + ÁFA, webshop jelölő
- [x] Tárhely / polc szintű helykódok
- [x] CRM: ügyfélkapcsolat-történet (hívás/e-mail/találkozó napló)
- [x] Akciós ár és vevőcsoportos árazás (partnerenkénti, csoportonkénti fix ár + akciós ár)
- [x] Score-alapú Google reCAPTCHA v3 a bejelentkezésen, configból ki-/bekapcsolható
- [x] REST API (token-alapú auth, olvasható katalógus + teljes CRUD partnerekre/rendelésekre, API-felhasználók admin + dokumentáció)
- [x] Kétnyelvű felület (magyar / angol): nyelvváltó a topbarban és a bejelentkezésen, teljes UI-fordítás a rendszerüzenetekkel együtt
- [x] Pénznemek: elsődleges pénznem + váltószámok, MNB közép-árfolyam lekérése gombbal és cronból, `/api/currencies` végpont
- [x] Adatok nyelvesítése: `*_description` táblák, nyelvek admin felület, bizonylat-névmásolat, `?language=` az API-n

**Következő lépcsők:**

- [ ] NAV Online Számla adatszolgáltatás
- [ ] Futárszolgálat-integrációk (GLS, MPL, Foxpost, …)

## 📄 Licenc

[GNU AGPLv3](LICENSE) — © 2026 [szabolevi98](https://github.com/szabolevi98/Cloudexus)
