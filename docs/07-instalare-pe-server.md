# 07 – Instalare pe server (Ubuntu)

AutoDeploy poate rula chiar pe serverul pe care face deploy (de exemplu după un upgrade de RAM), în loc
de PC. Kitul din `deploy/server/` îl instalează pe Ubuntu 22.04 / 24.04 cu Apache 2.4 și PHP-FPM, fără
să atingă celelalte site-uri, și mută datele (servere, proiecte, istoric, contul de admin) din instalarea
de pe PC.

## Ce conține kitul

| Fișier                                             | Ce face                                                                                         |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `deploy/server/install.sh`                         | instalarea completă (rulată ca root); se poate rula din nou oricând                             |
| `deploy/server/update.sh`                          | actualizarea: cod nou, composer, build, migrări, cache-uri, restart worker                      |
| `deploy/server/import-local-data.sh`               | mută baza SQLite de pe PC + același `APP_KEY`, opțional build-urile salvate                     |
| `deploy/server/lib.sh`                             | funcțiile comune ale celor trei scripturi                                                       |
| `deploy/server/templates/env.production`           | `.env`-ul de producție (făcut o singură dată, nu e suprascris)                                  |
| `deploy/server/templates/php-fpm-pool.conf`        | pool-ul PHP-FPM al lui AutoDeploy (user propriu, socket propriu)                                |
| `deploy/server/templates/apache-vhost-*.conf`      | site-ul Apache: HTTP → HTTPS, `DocumentRoot` pe `public/`, PHP prin pool, headere de securitate |
| `deploy/server/templates/apache-access.conf`       | exemple comentate: listă de IP-uri permise sau parolă în plus (HTTP basic auth)                 |
| `deploy/server/templates/autodeploy-queue.service` | workerul de coadă ca serviciu systemd (`Restart=always`)                                        |

## Cum arată pe server

```text
/var/www/autodeploy/                 codul, .env, database/database.sqlite (user autodeploy, închis altor useri)
/home/autodeploy/.ssh/autodeploy_root_ed25519   cheia cu care AutoDeploy intră ca root pe 127.0.0.1
/home/autodeploy/bin/php -> /usr/bin/php8.4     „php" și „composer" ale instalării, primele în PATH
/etc/php/8.4/fpm/pool.d/autodeploy.conf         pool PHP-FPM [autodeploy] -> /run/php/php8.4-fpm-autodeploy.sock
/etc/apache2/sites-available/autodeploy.conf    site-ul https://deploy.example.com
/etc/systemd/system/autodeploy-queue.service    workerul care rulează deploy-urile
/etc/autodeploy/install.conf                    setările instalării (citite de update.sh și la rularea din nou)
/etc/autodeploy/apache-access.conf              cine are voie în interfață (nu e suprascris niciodată)
/etc/autodeploy/backups/<data>/                 copiile fișierelor de sistem schimbate de installer
/usr/local/bin/autodeploy-artisan               „php artisan" rulat corect, ca userul autodeploy
```

- **userul `autodeploy`** (fără shell de login) rulează și PHP-FPM-ul interfeței, și workerul de coadă.
  Celelalte site-uri (pool-urile lor, `mod_php` ca `www-data`) nu pot citi `.env`, baza sau cheia SSH;
  doar `public/` e citibil de Apache;
- **pool-ul PHP-FPM** e separat (`pm = ondemand`, max. 5 procese: RAM puțin); upload-ul maxim vine din
  `AUTODEPLOY_MAX_UPLOAD_MB`;
- **workerul** e un serviciu systemd: pornește la boot, repornește singur, iar la oprire își termină întâi
  deploy-ul în curs (`KillMode=mixed`, `TimeoutStopSec=3660`). De aceea `.env` are
  `AUTODEPLOY_AUTO_WORKER=false`;
- **scheduler-ul** nu e necesar: AutoDeploy nu are taskuri programate (`routes/console.php` nu are niciunul).

## Drepturile: cum ajunge AutoDeploy la root

Un deploy face lucruri pe care doar root le poate face: creează foldere în `/var/www` care aparțin altor
useri, face `chown` pe `storage`, `systemctl reload` pentru PHP-FPM / Apache, editează vhost-uri (pasul
„Îndreaptă serverul web spre current"), scrie configurări Supervisor și crontab-uri, rulează hook-urile
custom. Azi toate trec prin SSH ca `root`. Nu doar coada le face – și unele pagini, direct în cererea web:

| Unde rulează                | Ce                                                                                                                                                                                                                                                                                |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **coada** (`RunDeployment`) | deploy, redeploy, rollback, backup, restore (toți pașii, hook-urile, `web_server`, `permissions`)                                                                                                                                                                                 |
| **cererea web** (PHP-FPM)   | Testează conexiunea / cheia SSH a serverului, Quick setup (verificări + pregătire), Environment (citire / scriere `.env`), Workeri (instalare Supervisor, start / stop / status / loguri), scheduler (crontab), sincronizarea release-urilor, descărcarea / ștergerea unui backup |

Toate trec prin aceeași interfață `Connection`, deci contează doar **ce conexiune are serverul** în
AutoDeploy.

### Recomandarea: SSH la root@127.0.0.1 cu o cheie dedicată

Serverul rămâne în AutoDeploy de tip **„Server remote (SSH)"**, doar că:

| Câmp                   | Valoare                                         |
| ---------------------- | ----------------------------------------------- |
| Conexiune              | „Server remote (SSH)"                           |
| Host                   | `127.0.0.1`                                     |
| Port                   | `22` (sau portul SSH al serverului)             |
| Utilizator             | `root`                                          |
| Autentificare          | „Fișier cu cheie pe acest calculator"           |
| Fișierul cheii private | `/home/autodeploy/.ssh/autodeploy_root_ed25519` |

`install.sh --setup-local-ssh` face cheia (ed25519, fără parolă, `600`, a userului `autodeploy`) și adaugă
în `/root/.ssh/authorized_keys` o linie restrânsă:

```text
from="127.0.0.1,::1",restrict ssh-ed25519 AAAA... autodeploy-local:autodeploy
```

- `from="127.0.0.1,::1"` – cheia merge doar de pe server; copiată în altă parte nu folosește la nimic;
- `restrict` – fără port / agent / X11 forwarding și fără terminal; comenzile și SFTP (de care are nevoie
  AutoDeploy) merg;
- la sfârșit installer-ul testează conexiunea ca `autodeploy` → `root@127.0.0.1`.

De ce așa:

- **zero cod nou**: aceleași scripturi, testate deja prin SSH, merg și în cererile web, și în coadă;
- **aplicația rulează neprivilegiată**: nici PHP-FPM, nici workerul nu sunt root; privilegiul e într-un
  singur loc, vizibil, și se retrage ștergând o linie din `authorized_keys`;
- **comenzile rulează ca root, exact ca azi**: `git fetch` folosește în continuare cheia root-ului pentru
  GitHub, hook-urile și `$SUDO` se comportă la fel;
- **datele de pe PC merg mai departe**: serverul importat schimbă doar host-ul și cheia; amprenta host key
  rămâne aceeași (același `sshd`).

Alternativele:

| Variantă                              | De ce nu                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| „Acest calculator" + worker ca root   | acoperă doar coada. Paginile din tabelul de mai sus rulează în PHP-FPM: pool-ul ar trebui să fie root (php-fpm refuză fără `-R`, iar master-ul e comun cu celelalte site-uri) – altfel „Permission denied". O aplicație web ca root e cel mai mare risc posibil                                                                                                           |
| „Acest calculator" + `sudo` (sudoers) | scripturile nu folosesc `sudo` peste tot (`mkdir` în `/var/www`, `git` cu cheia root, hook-urile custom), deci ar trebui ca `LocalConnection` să ruleze totul prin `sudo -n bash` (cod nou). O regulă restrânsă e imposibilă (hook-urile sunt bash arbitrar), rămâne `NOPASSWD: ALL` – la fel de puternic ca SSH la root, plus cod și `NoNewPrivileges` scos din serviciu |

> Indiferent de variantă, **cine controlează AutoDeploy controlează serverul** (asta e treaba lui). De
> aceea: parolă puternică, login limitat (5 încercări / minut) și, ideal, acces doar de la IP-urile tale
> sau cu o parolă în plus – vezi [Accesul la interfață](#accesul-la-interfață).

`sshd` trebuie să accepte root cu cheie (`PermitRootLogin prohibit-password`, implicit pe Ubuntu). Dacă
ai pus `PermitRootLogin no`, permite-l doar pentru localhost, la sfârșitul lui `/etc/ssh/sshd_config`:

```text
Match Address 127.0.0.1,::1
    PermitRootLogin prohibit-password
```

apoi `sshd -t && systemctl reload ssh`.

## Cerințe

| Ce               | Versiune / pachet                                                                                           |
| ---------------- | ----------------------------------------------------------------------------------------------------------- |
| Ubuntu           | 24.04 (merge și pe 22.04)                                                                                   |
| Apache           | 2.4 cu `mpm_prefork` sau `event`; installer-ul activează `proxy`, `proxy_fcgi`, `rewrite`, `headers`, `ssl` |
| PHP              | 8.3+ CLI și FPM, de ex. `php8.4-cli php8.4-fpm` din PPA-ul `ondrej/php` (`/usr/bin/php8.4`)                 |
| Extensii PHP     | `php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip` (+ recomandat `bcmath gmp intl`)         |
| Composer         | 2.x (`/usr/local/bin/composer` sau în `PATH`)                                                               |
| Node.js          | 22.18+ sau 24.11+ pentru build-ul interfeței – sau build pe PC și `--skip-build`                            |
| git, rsync, curl | din Ubuntu                                                                                                  |
| certbot          | pentru HTTPS (`apt-get install certbot`)                                                                    |
| DNS              | un subdomeniu nou (ex. `deploy.example.com`) cu record A / AAAA spre server                                 |

`--install-packages` instalează singur PHP-ul și extensiile lipsă (`apt-get`), dar nu Node: pentru Node 22
LTS folosește pachetele NodeSource (instrucțiunile de pe <https://github.com/nodesource/distributions>).

## Instalare pas cu pas

1. **DNS**: adaugă un record A (și AAAA, dacă ai IPv6) pentru subdomeniul interfeței, de ex.
   `deploy.example.com` → IP-ul serverului. Folosește un nume nou: installer-ul se oprește dacă numele e deja
   în alt site Apache (inclusiv un `ServerAlias *.example.com`).
2. **Codul** (repository-ul e public, merge pe HTTPS, fără cheie):

    ```bash
    git clone https://github.com/<contul-tău>/AutoDeploy.git /var/www/autodeploy
    ```

3. **Instalarea** (ca root). Parola de admin se dă prin mediu, nu ca opțiune (nu rămâne în istoric /
   lista de procese); fără ea se generează una și se afișează o dată:

    ```bash
    AUTODEPLOY_ADMIN_PASSWORD='o-parolă-lungă' bash /var/www/autodeploy/deploy/server/install.sh \
        --domain deploy.example.com --admin-email tu@example.com \
        --setup-local-ssh --certbot
    ```

    Pentru primul certificat pe un cont certbot nou adaugă `--certbot-email tu@example.com` (înseamnă și
    acceptarea termenilor Let's Encrypt). Dacă urmează importul de pe PC, `--admin-email` poate lipsi:
    contul vine din baza importată.

4. **Login** pe `https://deploy.example.com` și, dacă parola a fost generată, schimb-o din „Contul meu".
5. **Datele**: fie [le muți de pe PC](#mutarea-datelor-de-pe-pc), fie adaugi serverul de la zero
   (Servere → Adaugă server, cu valorile din [recomandare](#recomandarea-ssh-la-root127001-cu-o-cheie-dedicată))
   și apeși „Testează conexiunea".
6. **Accesul**: limitează cine poate deschide interfața – [Accesul la interfață](#accesul-la-interfață).

Ce face `install.sh`, în ordine:

1. verifică sistemul (root, systemd, PHP ≥ 8.3, extensiile, git, Composer);
2. creează userul `autodeploy` (fără login), `~/bin/php` → PHP-ul ales, `~/bin/composer`;
3. codul: îl lasă dacă există deja în folder; altfel `git clone` (`--repo`) sau copiere (`--source` /
   checkout-ul din care rulează scriptul);
4. `.env` din `templates/env.production` (doar dacă lipsește): `APP_ENV=production`, `APP_DEBUG=false`,
   SQLite, coadă `database`, `AUTODEPLOY_AUTO_WORKER=false`, `APP_URL=https://<domeniu>`;
5. `composer install --no-dev --optimize-autoloader`, `key:generate` doar dacă nu există cheie;
6. `database/database.sqlite` + `migrate --force`; contul de admin cu `DatabaseSeeder` (doar dacă nu există
   niciun user, sau dacă ai dat `AUTODEPLOY_ADMIN_PASSWORD` – atunci îi resetează parola);
7. `npm ci && npm run build`, doar dacă lipsește `public/build` (`--build` / `--skip-build`);
8. `artisan optimize` și drepturile (tot folderul al lui `autodeploy`, închis altora, `public/` citibil);
9. pool-ul PHP-FPM (cu `php-fpm8.4 -t` înainte de reload; la eroare pune fișierul înapoi);
10. site-ul Apache: întâi doar HTTP (redirect + ACME), certificatul cu
    `certbot certonly --webroot` (fără să lase certbot să editeze vhost-urile), apoi HTTPS; `apache2ctl
    configtest` înainte de orice reload – la eroare totul se pune înapoi și Apache nu e reîncărcat;
11. cheia SSH locală (`--setup-local-ssh`) + test;
12. serviciul systemd al workerului (`enable` + start / restart);
13. `/usr/local/bin/autodeploy-artisan`, `/etc/autodeploy/install.conf`, test `https://<domeniu>/up`.

### Opțiunile lui `install.sh`

| Opțiune                                                        | Implicit                                   | Ce face                                                                    |
| -------------------------------------------------------------- | ------------------------------------------ | -------------------------------------------------------------------------- |
| `--app-dir DIR`                                                | `/var/www/autodeploy`                      | folderul aplicației                                                        |
| `--domain NUME`                                                | –                                          | numele interfeței; fără el nu se face site Apache                          |
| `--php BIN`                                                    | `/usr/bin/php8.4`                          | PHP-ul de linie de comandă (FPM-ul se deduce din versiune)                 |
| `--user NUME`                                                  | `autodeploy`                               | userul de sistem al aplicației                                             |
| `--repo URL` / `--branch NUME`                                 | – / `main`                                 | clonează codul, dacă folderul nu are încă AutoDeploy                       |
| `--source DIR`                                                 | checkout-ul scriptului                     | copiază codul dintr-un alt folder de pe server (rsync)                     |
| `--admin-email EMAIL`                                          | –                                          | contul de admin (parola: `AUTODEPLOY_ADMIN_PASSWORD` în mediu)             |
| `--app-key KEY`                                                | –                                          | folosește acest `APP_KEY` (refuză să schimbe cheia unei instalări cu date) |
| `--build` / `--skip-build`                                     | build doar fără `public/build`             | forțează / sare peste `npm ci && npm run build`                            |
| `--setup-local-ssh`                                            | –                                          | cheia pentru `root@127.0.0.1` + linia din `authorized_keys` + test         |
| `--ssh-port PORT`                                              | din `sshd`, altfel `22`                    | portul SSH                                                                 |
| `--certbot` / `--certbot-email`                                | –                                          | certificat Let's Encrypt (webroot) / email pentru un cont nou              |
| `--ssl-cert` / `--ssl-key`                                     | `/etc/letsencrypt/live/<domeniu>/…`        | un certificat existent                                                     |
| `--install-packages`                                           | –                                          | `apt-get install` PHP CLI / FPM, extensiile, git, rsync, curl              |
| `--fpm-pool-dir`, `--fpm-service`, `--fpm-bin`, `--fpm-socket` | `/etc/php/<v>/fpm/pool.d`, `php<v>-fpm`, … | pentru un PHP-FPM instalat altfel (de ex. compilat în `/opt`)              |
| `--site-name NUME`                                             | `autodeploy`                               | numele site-ului Apache, al pool-ului și al serviciului (`<nume>-queue`)   |
| `--no-apache` / `--no-worker`                                  | –                                          | fără site Apache / fără worker systemd (atunci pornirea automată rămâne)   |
| `--force`                                                      | –                                          | continuă când domeniul e și în alt site sau `--app-key` ar schimba cheia   |

Opțiunile pot veni și din mediu (`INSTALL_DOMAIN`, `INSTALL_PHP`, `INSTALL_REPO`, …) și din
`/etc/autodeploy/install.conf`: **rulat din nou fără opțiuni, `install.sh` refolosește setările de data
trecută**. Schimbă doar ce lipsește sau e diferit, iar fișierele de sistem pe care le schimbă sunt copiate
înainte în `/etc/autodeploy/backups/<data-ora>/`.

## Mutarea datelor de pe PC

Baza `database/database.sqlite` de pe PC are serverul, proiectele, comenzile custom, workerii, istoricul
deploy-urilor și contul tău. Secretele serverelor (chei lipite, parole) sunt **criptate cu `APP_KEY`**, deci
baza merge doar împreună cu `APP_KEY`-ul aceleiași instalări.

1. **Pe PC**: oprește AutoDeploy (închide ferestrele lui `start-autodeploy.bat`) și nu porni deploy-uri
   până la capăt. Din folderul AutoDeploy, în PowerShell (`SERVER` = aliasul / IP-ul serverului tău):

    ```powershell
    ssh root@SERVER "install -d -m 700 /root/autodeploy-import"
    scp database\database.sqlite root@SERVER:/root/autodeploy-import/
    scp .env root@SERVER:/root/autodeploy-import/pc.env
    # opțional, build-urile salvate (tab-ul Builds):
    scp -r storage\app\private\builds root@SERVER:/root/autodeploy-import/
    ```

2. **Pe server** (după `install.sh ... --setup-local-ssh`):

    ```bash
    bash /var/www/autodeploy/deploy/server/import-local-data.sh \
        --sqlite /root/autodeploy-import/database.sqlite \
        --env-file /root/autodeploy-import/pc.env \
        --builds /root/autodeploy-import/builds \
        --local-server 1
    ```

    `1` e numărul serverului din AutoDeploy (se vede în adresa paginii lui, `/servers/1`).

Ce face scriptul – întâi doar verificări, pe o copie, fără să schimbe nimic:

- verifică fișierul (SQLite valid, `integrity_check`, tabelele AutoDeploy) și afișează serverele și
  proiectele găsite; avertizează dacă baza are migrări pe care codul de pe server nu le are (actualizează
  codul cu `update.sh` la aceeași versiune ca pe PC);
- citește `APP_KEY` din `.env`-ul PC-ului și **verifică** că deschide secretele criptate (după MAC, fără să
  decripteze nimic); cu o cheie greșită se oprește;
- se oprește dacă pe PC au rămas deploy-uri în coadă / în curs (workerul de pe server le-ar rula);
  `--clear-queue` le marchează eșuate și golește coada;
- golește sesiunile și cache-ul PC-ului; cu `--local-server N` schimbă serverul N în
  `root@127.0.0.1`, „Fișier cu cheie pe acest calculator", cheia făcută de `--setup-local-ssh` (secretele
  vechi ale lui sunt șterse);
- refuză să înlocuiască o instalare care are deja servere / proiecte, fără `--yes`.

Apoi: oprește workerul, pune aplicația în mentenanță, face backup la baza și `.env`-ul de pe server
(`database/backups/before-import-<data-ora>/`), copiază baza, pune `APP_KEY`-ul PC-ului în `.env`, copiază
build-urile, rulează `migrate --force` + `optimize`, repornește tot și testează serverul ca userul
`autodeploy` (la fel ca „Testează conexiunea").

**După import:**

1. te loghezi cu contul de pe PC (același email și aceeași parolă);
2. **Servere**: fără `--local-server`, editează serverul de mână cu valorile din
   [recomandare](#recomandarea-ssh-la-root127001-cu-o-cheie-dedicată) și apasă „Testează conexiunea". Dacă
   spune că host key-ul s-a schimbat, apasă „Uită cheia serverului" și testează din nou;
3. **Proiectele** au setări care țineau de PC și nu se potrivesc pe server: folderul local de pe Windows
   (`C:\...`), binarul PHP (de ex. `/usr/local/bin/php8.4` → `/usr/bin/php8.4` după trecerea la pachetele
   Ubuntu), comanda Composer și strategia de build „local". Ajustarea lor e un pas separat, făcut pentru
   fiecare proiect înainte de următorul deploy;
4. **nu mai folosi AutoDeploy de pe PC**: două instalări ar face deploy pe același server (redenumește-i
   baza sau șterge-l);
5. șterge fișierele copiate, au secrete în ele: `rm -rf /root/autodeploy-import`.

## Actualizare

```bash
bash /var/www/autodeploy/deploy/server/update.sh
```

Face: verifică să nu ruleze niciun deploy (altfel se oprește; `--force` = workerul repornește după jobul
lui) → mentenanță (`artisan down`) → `git pull --ff-only` pe branch-ul salvat (sau rsync din `--source`) →
`composer install --no-dev` → `npm ci && npm run build` (`--skip-build` dacă ai copiat `public/build` de pe
PC) → `migrate --force` → `optimize` → drepturi → reload PHP-FPM (golește OPcache; `--no-fpm-reload`) →
`queue:restart` + restart la worker → `artisan up` → test `/up`.

> Nu face deploy la AutoDeploy cu AutoDeploy: workerul și-ar schimba codul sub el în timpul jobului.
> Folosește `update.sh`.

## Accesul la interfață

AutoDeploy poate rula orice comandă ca root, deci pe lângă login-ul lui merită o barieră în plus.
`/etc/autodeploy/apache-access.conf` (inclus de site, creat o dată, **niciodată suprascris**) are două
exemple comentate:

1. **doar anumite IP-uri** (`Require ip ...`) – cel mai sigur, dacă ai IP fix sau VPN;
2. **o parolă în plus** (HTTP basic auth, fișierul `/etc/autodeploy/htpasswd` făcut cu `htpasswd`).

Ambele lasă să treacă `Require local` (testul installer-ului) și webhook-urile GitHub (`POST /webhook/...`,
recunoscute după linia originală a cererii, pentru că Laravel rescrie URL-ul în `/index.php`). Scoate
linia cu webhook-ul dacă nu-l folosești. După modificare: `apache2ctl configtest && systemctl reload apache2`.

Site-ul mai are: redirect HTTP → HTTPS, HSTS, `X-Robots-Tag: noindex`, headerele de securitate și pentru
fișierele statice, refuz pentru orice fișier cu punct în față (`.env`, `.git`) și cookie-uri de sesiune
doar pe HTTPS (`SESSION_SECURE_COOKIE=true`).

## Comenzi utile

```bash
systemctl status autodeploy-queue          # workerul de coadă
journalctl -u autodeploy-queue -f          # ce rulează acum
autodeploy-artisan about                   # php artisan ca userul autodeploy (cere root / sudo)
autodeploy-artisan optimize                # după o modificare în .env (config-ul e în cache)
tail -f /var/www/autodeploy/storage/logs/laravel-*.log
tail -f /var/log/apache2/autodeploy-error.log
```

Nu rula `php artisan` ca root în `/var/www/autodeploy`: fișierele create în `storage/` ar rămâne ale lui
root, iar interfața nu le mai poate scrie. Pentru git: `sudo -u autodeploy git -C /var/www/autodeploy status`.

## Probleme frecvente pe server

**„Permission denied" în `storage/logs` sau `bootstrap/cache` (eroare 500).**
Cineva a rulat `php artisan` ca root. Repară cu `bash /var/www/autodeploy/deploy/server/install.sh` (refă
drepturile) sau `chown -R autodeploy:autodeploy /var/www/autodeploy`.

**„Login refused for root@127.0.0.1".**
Linia din `/root/.ssh/authorized_keys` lipsește sau `sshd` nu primește root: rulează din nou
`install.sh --setup-local-ssh` (testează și spune ce vede `sshd`). Verifică
`sshd -T -C user=root,host=localhost,addr=127.0.0.1 | grep -Ei 'permitrootlogin|allowusers|listenaddress'`.

**„Key file not found or not readable".**
Calea cheii trebuie să fie cea absolută (`/home/autodeploy/.ssh/autodeploy_root_ed25519`) și fișierul al
userului `autodeploy` (`600`); o cale de pe Windows (`C:\Users\...`) importată de pe PC nu merge aici.

**Eroare 504 la Quick setup / instalarea Supervisor.**
Apache așteaptă cel mult 900 s (`ProxyTimeout` în site), PHP tot 900 s (`max_execution_time` în pool). Acțiunea
continuă oricum pe server; reîncarcă pagina după ce se termină.

**Deploy-ul stă „În coadă".**
`systemctl status autodeploy-queue` și `journalctl -u autodeploy-queue -n 50`. Workerul trebuie să fie
`active (running)`; `AUTODEPLOY_AUTO_WORKER=false` e corect aici.

**Build-ul interfeței se oprește la `install.sh` / `update.sh`.**
Node prea vechi (trebuie 22.18+ / 24.11+). Ori instalezi Node 22 LTS, ori faci `npm run build` pe PC,
copiezi `public/build` în `/var/www/autodeploy/public/build` și rulezi cu `--skip-build`.

**Am schimbat `.env` și nu se vede.**
Config-ul e în cache: `autodeploy-artisan optimize` (și `systemctl restart autodeploy-queue` pentru worker).

## Dezinstalare

```bash
systemctl disable --now autodeploy-queue && rm /etc/systemd/system/autodeploy-queue.service && systemctl daemon-reload
a2dissite autodeploy && rm /etc/apache2/sites-available/autodeploy.conf && systemctl reload apache2
rm /etc/php/8.4/fpm/pool.d/autodeploy.conf && systemctl reload php8.4-fpm
sed -i '/autodeploy-local:autodeploy$/d' /root/.ssh/authorized_keys
rm -rf /var/www/autodeploy /etc/autodeploy /usr/local/bin/autodeploy-artisan && userdel -r autodeploy
```

Certificatul rămâne în certbot (`certbot delete --cert-name deploy.example.com`, dacă vrei să-l ștergi).
