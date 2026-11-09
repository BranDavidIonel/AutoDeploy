# 03 – Ghid de utilizare

Exemplul de mai jos mută un site existent (ex. `/var/www/my-site`, servit acum direct din
folderul lui) sub AutoDeploy, fără downtime. Pentru un site nou e la fel, doar fără importul din
pasul 3.

> Ghidul folosește denumirile din interfața în română. Interfața pornește în engleză; comutatorul
> **EN / RO** din bara de sus schimbă limba (alegerea se ține minte).

## 1. Adaugă serverul

**Servere → Adaugă server**

- Tip: **Server remote (SSH)**.
- Host: IP-ul serverului (ex. `203.0.113.10`), port `22`, user `root`.
- Autentificare: **Fișier cu cheie pe acest calculator** → `~/.ssh/id_ed25519` (aceeași cheie cu care
  intri tu pe server prin `ssh`). Cheia nu se copiază în AutoDeploy, se citește la fiecare conectare.
- Salvează. AutoDeploy se conectează imediat și citește: sistemul, RAM, disc, versiunile de PHP,
  Composer, Node, Git, Nginx, MySQL, Supervisor.

La prima conectare se salvează **amprenta cheii serverului** (host key). Dacă se schimbă (ex. ai
reinstalat serverul), conexiunea e refuzată până apeși „Uită cheia serverului" – protecție împotriva
unui server fals.

Pe pagina serverului vezi și **cheia SSH publică a serverului** (sau butonul care o creează). Pe aceasta
o adaugi în GitHub → repository → _Settings → Deploy keys_, ca serverul să poată face `git fetch`.

## 2. Creează proiectul

**Proiecte → Proiect nou**

- **Repository**: `git@github.com:user/my-site.git`, branch `main`.
- **Deploy folder**: un folder **nou**, ex. `/var/www/my-site-app`. AutoDeploy creează în el
  `releases/`, `shared/` și `current`. Nu folosi folderul vechi al site-ului.
- **PHP / Composer**: `php`, `composer` (sau calea completă, ex. `php8.4`).
- **User web**: `www-data` – proprietarul pentru `storage` și `bootstrap/cache`.
- **Release-uri păstrate**: `3`.
- **Build front-end**: **Build local**, cu _Folder local_ = folderul proiectului de pe PC
  (ex. `C:\Users\tu\Desktop\work\my-site`) și comanda `npm run build`.
- **Shared**: `storage` (foldere) și `.env` (fișiere) – rămân la fel între release-uri.
- **Îndreaptă serverul web spre current** (opțional, oprit implicit): după fiecare activare, AutoDeploy găsește
  site-ul din Apache / Nginx al host-ului din **URL-ul site-ului** (ex. `https://app.example.com` →
  `app.example.com`) și îi mută `DocumentRoot` / `root` pe `current/public` (vezi pasul 5). Cere URL-ul site-ului
  și, pe server, root sau `sudo` fără parolă. Oprit: schimbi tu o singură dată configurația serverului web.

La creare proiectul primește comenzile Laravel standard (vezi pasul 6).

## 3. Quick setup

Tab-ul **Quick setup** al proiectului:

1. Lista de verificări arată ce lipsește pe server (folderul, `.env`, `APP_KEY`, accesul la repository,
   Nginx, Supervisor, userul web, Node…), fiecare cu explicația cum se repară.
2. Completează **„Importă .env și storage dintr-un folder existent"** cu `/var/www/my-site` și
   apasă **Rulează quick setup**. Se creează structura, se copiază o singură dată `.env` și `storage/`
   (upload-urile, logurile) în `shared/`, se adaugă `github.com` în `known_hosts`, se verifică accesul
   la repository și se repară proprietarul lui `storage`.
   `storage/` se importă întâi cu hard link-uri (`cp -al`): instant și fără spațiu în plus pe disc, chiar
   cu multe upload-uri. Folderul vechi rămâne o copie completă: ștergerea sau înlocuirea unui fișier
   într-o parte nu o atinge pe cealaltă (fișierele scrise pe loc, ca logurile, își împart conținutul până
   sunt înlocuite). Dacă hard link-urile nu merg (de ex. alt sistem de fișiere), se face o copie
   normală (`cp -a`). Importul se face doar dacă `shared/storage` nu există încă.
3. Rezultatul apare într-o fereastră cu tot output-ul serverului.

Cardul **Server web** de pe aceeași pagină spune cine mută site-ul pe `current/public`: cu setarea „Îndreaptă
serverul web spre current" activă, primul deploy (configurația afișată e doar de referință); altfel tu, o
singură dată după primul deploy, cu configurația afișată (sau activezi setarea din **Setări**).

## 4. Verifică `.env`

Tab-ul **Environment** editează direct `shared/.env` de pe server (versiunea anterioară rămâne în
`.env.backup`). Butoane utile: „Generează APP_KEY", șablon Laravel de producție. Modificările se
aplică la următorul deploy (care rulează `php artisan optimize`).

## 5. Primul deploy

Butonul **Deploy** (sus, pe orice tab al proiectului) deschide opțiunile:

- de unde vin asset-urile (build local / build salvat / build pe server / fără build) – implicit cel
  din setări;
- pentru build salvat: care arhivă;
- branch-ul și o notă opțională.

Până la primul deploy reușit, pagina proiectului și fereastra Deploy amintesc: „Primul deploy: rulează mai
întâi Quick setup" (fără el, pasul „Prepare server" se oprește, pentru că lipsește `shared/.env`).

Pagina deploy-ului arată pașii cu log live, durata, commit-ul. Poți anula (după comanda curentă) sau,
dacă workerul nu mai răspunde, „Oprește forțat". Workerul de coadă pornește singur dacă nu rulează deja;
dacă deploy-ul stă în coadă, pagina spune de ce.

**Serverul web.** Până când site-ul servește `current/public`, Apache / Nginx servește tot folderul vechi, deși
deploy-ul a rulat deja migrările noi pe baza de date comună: codul vechi poate da erori (500). Deci imediat după
primul deploy:

- **automat**, cu setarea „Îndreaptă serverul web spre current" (pasul 2): primul deploy are, imediat după
  „Activate release", pasul „Point the web server to current". Log-ul lui arată fiecare fișier schimbat
  (`fișier: /var/www/my-site/public → /var/www/my-site-app/current/public`), folderul de backup
  (`.autodeploy/webserver/<release>/`, cu `original-paths.txt` = de unde vine fiecare fișier), rezultatul
  `configtest` / `nginx -t` și reload-ul. Deploy-urile următoare doar afișează „already serves current". Dacă
  pasul eșuează (fără root/sudo, test de configurație picat – fișierele sunt puse la loc automat), deploy-ul e
  marcat eșuat: release-ul e live, dar site-ul trebuie mutat de mână, cu instrucțiunile din log;
- **de mână**, cu configurația de mai jos. AutoDeploy detectează dacă serverul folosește Apache sau Nginx și
  arată configurația potrivită pe pagina Quick setup.

**Apache** (cu PHP-FPM): în ambele fișiere ale site-ului (http și
`-le-ssl.conf`) schimbi doar `DocumentRoot` și `<Directory>`; `SetHandler` rămâne la fel:

```apache
DocumentRoot /var/www/my-site-app/current/public
<Directory /var/www/my-site-app/current/public>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

apoi `apachectl configtest && systemctl reload apache2`.

**Nginx:**

```nginx
root /var/www/my-site-app/current/public;
# în blocul PHP:
fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
fastcgi_param DOCUMENT_ROOT $realpath_root;
```

apoi `nginx -t && systemctl reload nginx`.

Folderul vechi `/var/www/my-site` poate rămâne ca backup.

## 6. Comenzile custom („Deploy script")

Tab-ul **Deploy script** arată tot pipeline-ul în ordine. Pașii gri sunt ficși; în cele trei zone
poți adăuga comenzile tale (Laravel, npm, bash):

| Zonă             | Când rulează                                  | Exemple                                                 |
| ---------------- | --------------------------------------------- | ------------------------------------------------------- |
| Înainte de build | după `composer install`, înainte de asset-uri | `npm ci` custom, generare de fișiere                    |
| Înainte de live  | în noul release, înainte să devină `current`  | `$PHP artisan migrate --force`, `$PHP artisan optimize` |
| După live        | imediat după activare (și la rollback)        | `$PHP artisan queue:restart`, reload PHP-FPM            |

Fiecare comandă rulează cu `bash`, `set -e`, în folderul noului release. Variabile disponibile:
`$PHP`, `$COMPOSER_BIN`, `$RELEASE_PATH`, `$SHARED_PATH`, `$CURRENT_PATH`, `$DEPLOY_PATH`,
`$RELEASE`, `$BRANCH`, `$COMMIT`, `$WEB_USER`. O comandă care eșuează oprește deploy-ul (site-ul
rămâne neatins dacă e înainte de activare). Comenzile rulează ca userul SSH; după cele „după live",
`storage` și `bootstrap/cache` sunt date din nou userului web (pasul automat „Fix storage permissions"). Comenzile se pot activa/dezactiva, reordona și adăuga din
presetări (migrate, seed, optimize, storage:link, queue:restart, Horizon, Filament, PHP-FPM…).

## 7. Build salvat (upload)

Tab-ul **Builds**:

1. pe PC, în proiect: `npm run build`;
2. arhivează conținutul lui `public/build`, de exemplu în PowerShell:

    ```powershell
    Compress-Archive -Path public\build\* -DestinationPath build.zip
    ```

    (merge și arhiva folderului `build` sau `public/build` – AutoDeploy caută `manifest.json`);

3. trage arhiva în pagina Builds; opțional pui o etichetă și commit-ul din care e făcută
   (atunci se face deploy exact la acel commit); poți bifa „Deploy imediat după upload".

Se păstrează ultimele N build-uri (setare „Build-uri păstrate"). Fiecare se poate re-deploya,
descărca sau șterge. Build-urile locale făcute de varianta „Build local" apar tot aici.

## 8. Workeri (coadă) și scheduler

Tab-ul **Workers**:

- **Quick setup** – un singur buton: instalează Supervisor dacă lipsește, creează workerul
  „Default queue" și îl pornește. Rezultatul apare într-o fereastră.
- Pentru fiecare worker: aplică configurația, start / stop / restart, status, loguri, editare
  (conexiune, cozi, procese, timeout, memorie, user), configurația Supervisor generată.
- **Scheduler** – activează/dezactivează linia de cron pentru `php artisan schedule:run`.

## 9. Rollback

Tab-ul **Releases** – release-urile păstrate pe server, cel live marcat. „Revino la acesta" face un
deploy de tip rollback (instant). „Sincronizează cu serverul" recitește folderul `releases/` dacă ai
modificat ceva manual. Migrările nu se anulează.

## 10. Deploy automat la `git push` (opțional)

În **Settings** ai URL-ul de webhook și opțiunea „Deploy la git push". În GitHub → _Settings →
Webhooks_: Payload URL = acel URL, content type `application/json`, evenimentul _push_. Doar
push-urile pe branch-ul proiectului pornesc un deploy. Funcționează doar dacă AutoDeploy e accesibil
din internet (de ex. instalat pe server).
