# 06 – Probleme frecvente

**Deploy-ul stă „În coadă" și nu pornește.**
Când un deploy intră în coadă și nu rulează niciun worker, AutoDeploy pornește singur unul în fundal
(dacă `AUTODEPLOY_AUTO_WORKER` nu e `false`). După ~10 secunde, pagina deploy-ului spune ce se întâmplă:

- „Se pornește workerul de coadă…" – a fost pornit acum, deploy-ul începe în câteva secunde;
- „Un worker de coadă rulează, dar nu a preluat încă acest deploy" – e ocupat cu alt deploy, așteaptă;
- „Workerul de coadă nu a pornit" – vezi `storage/logs/queue-worker.log` (output-ul workerului: erori
  PHP, extensii lipsă, versiune de PHP greșită) și `storage/logs/laravel*.log` (liniile „AutoDeploy could
  not start a queue worker", de ex. când PHP-ul de linie de comandă nu e găsit). Setează PHP-ul corect în
  `.env`: `AUTODEPLOY_PHP_BINARY=C:\xampp\php\php.exe` (sau `/usr/bin/php8.4` pe Linux). `proc_open` nu
  trebuie să fie în `disable_functions` din `php.ini`. AutoDeploy încearcă din nou după un minut;
- pornirea automată e oprită – pornește tu workerul.

Oricând poți porni workerul de mână: `start-autodeploy.bat` (pornește și workerul) sau
`php artisan queue:work --timeout=3600` în folderul AutoDeploy. Dacă un deploy a rămas blocat, apasă de
două ori „Anulează" (a doua oară = oprire forțată).

**„Login refused for root@…" / „Key file not found".**
Calea cheii e pe calculatorul care rulează AutoDeploy (ex. `~/.ssh/id_ed25519` sau
`C:\Users\tu\.ssh\id_ed25519`) și trebuie să fie cheia **privată** (fără `.pub`). Verifică întâi
din terminal: `ssh -i ~/.ssh/id_ed25519 root@IP`.

**„The host key of … changed".**
Serverul are altă cheie decât la prima conectare (reinstalare / IP refolosit). Dacă știi de ce, pe pagina
serverului apasă „Uită cheia serverului". Altfel nu continua.

**„Could not fetch this commit / the branch".**
Serverul nu poate citi repository-ul: creează cheia SSH a serverului (pagina serverului) și adaug-o în
GitHub ca _Deploy key_; pentru build local, commit-ul trebuie să fie `git push`-uit.

**„Deploy oprit: modificări necomise" (build local).**
Folderul local al proiectului are fișiere modificate și necomise (lista e în log-ul pasului „Build
assets locally"). Asset-urile ar fi construite din ele, dar serverul ia codul din ultimul commit, deci nu
s-ar potrivi. Fă commit (și push) la modificări sau pune-le deoparte (`git stash`), apoi deployează din
nou. Fișierele noi, neadăugate în git, nu opresc deploy-ul.

**„Deploy oprit: commit-ul … nu e încă push-uit".**
Commit-ul din folderul local nu există pe `origin/<branch>`, iar serverul îl ia din repository. Rulează
`git push`, apoi deployează din nou. Dacă AutoDeploy nu poate contacta repository-ul (offline, fără
acces), apare doar un avertisment și deploy-ul continuă.

**„Missing …/shared/.env".**
Primul deploy are nevoie de `.env`: Quick setup cu import din folderul vechi sau tab-ul Environment.
Până la primul deploy reușit, pagina proiectului și fereastra Deploy afișează această amintire.

**„… exists and is a normal folder, not a link".**
În Deploy folder există deja un `current` care e folder normal. Mută-l (e probabil o copie veche a
site-ului) sau alege alt Deploy folder.

**Build-ul pe server e oprit („Killed", exit code 137).**
Lipsă de RAM. Folosește „Build local" sau „Build salvat"; după upgrade-ul de RAM poți reveni la „Build pe
server" din setările proiectului.

**Site-ul arată tot versiunea veche după primul deploy (sau dă eroare 500).**
Apache / Nginx servește încă folderul vechi al site-ului, iar deploy-ul a rulat deja migrările noi pe baza de date
comună, așa că, de obicei, codul vechi dă erori. Site-ul trebuie mutat pe `<Deploy folder>/current/public`:

- activează **„Îndreaptă serverul web spre current"** în setările proiectului (cere „URL-ul site-ului") și fă
  deploy din nou: pasul „Point the web server to current" schimbă `DocumentRoot` / `root`, face backup în
  `.autodeploy/webserver/<release>/`, testează configurația și dă reload;
- sau schimbă-l de mână cu configurația de pe pagina Quick setup.

Mesajele pasului „Point the web server to current":

- „WARNING: no Apache / Nginx site lists …" – niciun `ServerName` / `ServerAlias` / `server_name` nu are exact
  host-ul din URL-ul site-ului (ex. URL-ul e `www.example.com`, dar site-ul are doar `example.com`). Corectează
  URL-ul sau schimbă site-ul de mână; deploy-ul nu e oprit;
- „… not an app public folder (a redirect?): left as it is" – un vhost cu acel host servește alt folder decât un
  `…/public` (de obicei un vhost care doar redirecționează la HTTPS); e lăsat intenționat neatins;
- „✖ … is not root and has no passwordless sudo" – userul SSH nu poate scrie în `/etc`: conectează-te ca root,
  permite `sudo` fără parolă sau fă schimbarea de mână (log-ul arată exact ce); deploy-ul e marcat eșuat;
- „✖ The … config test fails already, before any change" – configurația serverului web era deja stricată (alt
  site); nimic nu a fost schimbat; repar-o (`apachectl configtest` / `nginx -t`) și fă deploy din nou;
- „✖ The … config test failed after the change: every file is restored" – fișierele au fost puse la loc din
  backup; output-ul testului arată linia cu problema;
- „WARNING: these files still name the old folder …" – un pool PHP-FPM (`/etc/php/*/fpm/pool.d`,
  `/etc/php-fpm.d`) sau un site mai conține folderul vechi al aplicației, de obicei în `open_basedir`, `chdir`,
  loguri sau `Alias`. AutoDeploy nu le schimbă: cu un `open_basedir` care nu include noul Deploy folder, PHP
  refuză fișierele din `releases/` (erori „open_basedir restriction in effect"). Adaugă Deploy folder-ul în
  `open_basedir`, apoi `systemctl reload php8.4-fpm` (sau versiunea ta).

**Site-ul arată fișiere vechi după deploy.**
OPcache: comanda „Reload PHP-FPM" (după live) trebuie să fie activă. Ea găsește singură serviciul
(`php8.4-fpm`, `php84-fpm` sau `php-fpm`); dacă PHP rulează ca modul Apache (mod_php), înlocuiește-o cu
`systemctl reload apache2`. Cu Nginx, folosește și `$realpath_root` (vezi Quick setup). Fără ele PHP poate
ține în cache calea veche a lui `current`.

**Erori de permisiuni în `storage/logs` după deploy.**
Setează „User web" (`www-data`) în proiect: AutoDeploy schimbă proprietarul lui `shared/storage` și
`bootstrap/cache` la activare și din nou după comenzile „după live" (pasul „Fix storage permissions"),
la deploy și la rollback (doar dacă se conectează ca root sau are `sudo`).

**Upload-ul de build e refuzat ca prea mare.**
Limita e `AUTODEPLOY_MAX_UPLOAD_MB` și limitele PHP (`upload_max_filesize`, `post_max_size`).
În XAMPP 8.5 limitele sunt 100 MB (`php.ini`); un build Vite arhivat are de obicei sub 5 MB.

**Workerul apare „Crashed" (FATAL).**
Vezi „Loguri" la worker. De obicei proiectul nu e încă deployat (`current/artisan` lipsește) sau
conexiunea de coadă (`database`, `redis`) nu e configurată în `.env`.
