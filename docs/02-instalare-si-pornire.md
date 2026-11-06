# 02 – Instalare și pornire

## Cerințe

| Ce           | Versiune                                                     | Observații                                                                    |
| ------------ | ------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| PHP          | 8.5 (minim 8.3)                                              | pe PC: XAMPP `C:\xampp\php_8.5.12`, ales automat prin fișierul `.php-version` |
| Extensii PHP | `openssl`, `pdo_sqlite`, `sodium`, `zip`, `phar`, `mbstring` | toate sunt active în XAMPP 8.5                                                |
| Composer     | 2.x                                                          | `C:\xampp\php\composer.phar`                                                  |
| Node.js      | 22+ (testat cu 26)                                           | pentru interfață și pentru build-urile locale ale proiectelor                 |
| Git          | orice versiune recentă                                       | pentru build local (citește commit-ul)                                        |
| Git Bash     | doar pentru servere de tip „Acest calculator" pe Windows     | `C:\Program Files\Git\bin\bash.exe`                                           |

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

Dacă workerul nu rulează, deploy-urile rămân „În coadă" și aplicația afișează un avertisment galben
cu comanda de pornire.

Pentru dezvoltare (cu hot reload):

```bash
composer dev
```

`composer dev` rulează `php artisan dev` (server, coadă, Vite, loguri). Pe Windows poți folosi și
separat: `php artisan serve --port=8077`, `php artisan queue:work --timeout=3600`, `npm run dev`.

## Variabile `.env` specifice

| Variabilă                        | Implicit | Ce face                                                                                                          |
| -------------------------------- | -------- | ---------------------------------------------------------------------------------------------------------------- |
| `APP_LOCALE`                     | `en`     | limba implicită a interfeței; fiecare utilizator o poate schimba din bara de sus (EN / RO)                       |
| `DB_QUEUE_RETRY_AFTER`           | `4000`   | **obligatoriu mai mare decât durata unui deploy** (secunde); altfel coada poate porni același deploy de două ori |
| `AUTODEPLOY_LOCAL_SHELL`         | gol      | bash-ul pentru servere „Acest calculator"; gol = `/bin/bash` pe Linux, Git Bash pe Windows                       |
| `AUTODEPLOY_CONNECT_TIMEOUT`     | `20`     | secunde de așteptare la conectarea SSH                                                                           |
| `AUTODEPLOY_COMMAND_TIMEOUT`     | `3600`   | durata maximă a unei comenzi pe server                                                                           |
| `AUTODEPLOY_LOCAL_BUILD_TIMEOUT` | `1800`   | durata maximă a unui `npm run build` local                                                                       |
| `AUTODEPLOY_MAX_UPLOAD_MB`       | `200`    | mărimea maximă a unei arhive de build urcate din browser                                                         |

Secretele serverelor (chei private lipite în aplicație, parole, passphrase) se salvează **criptate cu
`APP_KEY`**. Dacă schimbi `APP_KEY`, trebuie reintroduse.

## Instalare pe un server Linux (opțional, pe viitor)

```bash
git clone https://github.com/BranDavidIonel/AutoDeploy.git /var/www/autodeploy
cd /var/www/autodeploy
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed --force
npm ci && npm run build        # sau urcă public/build făcut pe PC
php artisan optimize
```

Apoi:

- Nginx cu `root /var/www/autodeploy/public` (ideal în spatele unei autentificări / doar pe VPN —
  AutoDeploy poate rula comenzi ca root pe server);
- un worker Supervisor pentru coadă:

```ini
[program:autodeploy-queue]
command=php /var/www/autodeploy/artisan queue:work --timeout=3600 --tries=1
user=root
autostart=true
autorestart=true
stopwaitsecs=3610
```

- schimbă parola adminului imediat.
