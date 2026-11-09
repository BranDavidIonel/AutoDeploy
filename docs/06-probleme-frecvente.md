# 06 – Probleme frecvente

**Deploy-ul stă „În coadă" și nu pornește.**
Workerul de coadă nu rulează. Pornește AutoDeploy cu `start-autodeploy.bat` (pornește și workerul) sau
rulează `php artisan queue:work --timeout=3600` în folderul AutoDeploy. Dacă un deploy a rămas blocat,
apasă de două ori „Anulează" (a doua oară = oprire forțată).

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

**„… exists and is a normal folder, not a link".**
În Deploy folder există deja un `current` care e folder normal. Mută-l (e probabil o copie veche a
site-ului) sau alege alt Deploy folder.

**Build-ul pe server e oprit („Killed", exit code 137).**
Lipsă de RAM. Folosește „Build local" sau „Build salvat"; după upgrade-ul de RAM poți reveni la „Build pe
server" din setările proiectului.

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
