# 02 – Instalare și pornire

## Cerințe

| Ce           | Versiune                                                     | Observații                                                                          |
| ------------ | ------------------------------------------------------------ | ----------------------------------------------------------------------------------- |
| PHP          | 8.5 (minim 8.3)                                              | pe PC: XAMPP `C:\xampp\php` sau `C:\xampp\php_<versiune>`, ales după `.php-version` |
| Extensii PHP | `openssl`, `pdo_sqlite`, `sodium`, `zip`, `phar`, `mbstring` | toate sunt active în XAMPP 8.5                                                      |
| Composer     | 2.x                                                          | `C:\xampp\php\composer.phar`                                                        |
| Node.js      | 22+ (testat cu 26)                                           | pentru interfață și pentru build-urile locale ale proiectelor                       |
| Git          | orice versiune recentă                                       | pentru build local (citește commit-ul)                                              |
| Git Bash     | doar pentru servere de tip „Acest calculator" pe Windows     | `C:\Program Files\Git\bin\bash.exe`                                                 |

Baza de date este **SQLite** (`database/database.sqlite`): nu are nevoie de MySQL pornit și nu
se amestecă cu bazele altor proiecte din XAMPP.

Pe server (ținta deploy-urilor) trebuie să existe: `git`, `php`, `composer`, `tar`, opțional
`node`/`npm` (doar pentru build pe server) și `supervisor` (îl poate instala AutoDeploy).

## Prima instalare (Windows)

Dublu-click pe **`setup-autodeploy.bat`**. Face, în ordine:

1. `composer install`;
2. `.env` din `.env.example` + `php artisan key:generate`;
3. baza SQLite + `php artisan migrate` + `php artisan db:seed` (userul admin);
4. `npm install`;
5. `npm run build`.

Poate fi rulat de mai multe ori fără probleme.

Manual, echivalentul este:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

## Pornire

Dublu-click pe **`start-autodeploy.bat`**:

- pornește serverul web pe <http://127.0.0.1:8077> (`php artisan serve`);
- pornește într-o fereastră minimizată **workerul de coadă** (`php artisan queue:work --timeout=3600`),
  care rulează efectiv deploy-urile;
- deschide browserul.

Autentificare cu emailul și parola de admin din `.env` (`AUTODEPLOY_ADMIN_EMAIL`, `AUTODEPLOY_ADMIN_PASSWORD`,
create de `DatabaseSeeder`; fără parolă în `.env` se generează una și se afișează o dată). Parola se poate
schimba din „Contul meu".

### Cu Apache (XAMPP), fără `start-autodeploy.bat`

AutoDeploy poate fi servit și de Apache-ul din XAMPP (un `VirtualHost` cu `DocumentRoot` pe folderul
`public/` al lui AutoDeploy). Nu mai trebuie pornit niciun worker de mână: când un deploy intră în coadă
și nu rulează niciun worker, AutoDeploy pornește singur unul în fundal, fără fereastră:

```text
php artisan queue:work database --stop-when-empty --timeout=3600 --tries=1 --sleep=1
```

- workerul se oprește singur când coada e goală; la următorul deploy pornește altul;
- output-ul lui (joburile rulate, erorile) e în `storage/logs/queue-worker.log`;
- PHP-ul de linie de comandă e găsit automat (sub Apache: `php.exe` din folderul lui `php.ini`, de ex.
  `C:\xampp\php\php.exe`, apoi `PATH`); dacă nu e găsit, setează `AUTODEPLOY_PHP_BINARY` în `.env`;
- un worker care rulează deja (din `start-autodeploy.bat`, din terminal sau din Supervisor) e recunoscut
  după „heartbeat"-ul scris în cache, deci nu se pornește un al doilea lângă el;
- pornirea automată se oprește cu `AUTODEPLOY_AUTO_WORKER=false` în `.env` (atunci pornești tu workerul).

Dacă workerul nu pornește (sau pornirea automată e oprită), deploy-ul rămâne „În coadă", iar pagina lui
spune după câteva secunde de ce și afișează comanda de pornire manuală (vezi și
[06 – Probleme frecvente](06-probleme-frecvente.md)).

Pentru dezvoltare (cu hot reload):

```bash
composer dev
```

`composer dev` rulează `php artisan dev` (server, coadă, Vite, loguri). Pe Windows poți folosi și
separat: `php artisan serve --port=8077`, `php artisan queue:work --timeout=3600`, `npm run dev`.
Coada din `composer dev` (`queue:listen`) nu scrie heartbeat, deci AutoDeploy poate porni încă un worker
lângă ea; nu strică nimic (un job e luat o singură dată), dar îl poți evita cu `AUTODEPLOY_AUTO_WORKER=false`.

## Variabile `.env` specifice

| Variabilă                        | Implicit | Ce face                                                                                                           |
| -------------------------------- | -------- | ----------------------------------------------------------------------------------------------------------------- |
| `APP_LOCALE`                     | `en`     | limba implicită a interfeței; fiecare utilizator o poate schimba din bara de sus (EN / RO)                        |
| `DB_QUEUE_RETRY_AFTER`           | `4000`   | **obligatoriu mai mare decât durata unui deploy** (secunde); altfel coada poate porni același deploy de două ori  |
| `AUTODEPLOY_LOCAL_SHELL`         | gol      | bash-ul pentru servere „Acest calculator"; gol = `/bin/bash` pe Linux, Git Bash pe Windows                        |
| `AUTODEPLOY_CONNECT_TIMEOUT`     | `20`     | secunde de așteptare la conectarea SSH                                                                            |
| `AUTODEPLOY_COMMAND_TIMEOUT`     | `3600`   | durata maximă a unei comenzi pe server                                                                            |
| `AUTODEPLOY_LOCAL_BUILD_TIMEOUT` | `1800`   | durata maximă a unui `npm run build` local                                                                        |
| `AUTODEPLOY_MAX_UPLOAD_MB`       | `200`    | mărimea maximă a unei arhive de build urcate din browser                                                          |
| `AUTODEPLOY_AUTO_WORKER`         | `true`   | pornește singur un worker de coadă când un deploy intră în coadă și nu rulează niciunul; `false` = îl pornești tu |
| `AUTODEPLOY_PHP_BINARY`          | gol      | PHP-ul de linie de comandă pentru acel worker; gol = detectat automat                                             |

Secretele serverelor (chei private lipite în aplicație, parole, passphrase) se salvează **criptate cu
`APP_KEY`**. Dacă schimbi `APP_KEY`, trebuie reintroduse.

## Instalare pe un server Linux

Instalarea pe un server Ubuntu (Apache, PHP-FPM, worker systemd, HTTPS, mutarea datelor de pe PC) e
descrisă pas cu pas în [07 · Instalare pe server](07-instalare-pe-server.md). Scripturile sunt în
`deploy/server/`.
