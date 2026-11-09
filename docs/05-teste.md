# 05 – Teste

## Testele automate (Pest)

```bash
php artisan test            # sau: vendor/bin/pest
composer test               # + Pint (stil) + Larastan
npm run types:check         # TypeScript (vue-tsc)
```

Testele rulează pe SQLite în memorie, cu coada pe `sync` (un deploy rulează imediat, în test) și
**nu ating niciun server**: `fakeServer()` (din `tests/Pest.php`) înlocuiește orice conexiune cu
`tests/Support/FakeConnection.php`, care doar înregistrează scripturile, upload-urile și fișierele
scrise. Implicit toate comenzile reușesc; un „responder" poate face o comandă să eșueze:

```php
$server = fakeServer(fn (string $script) => str_contains($script, 'artisan migrate')
    ? new CommandResult(1, "SQLSTATE[HY000] Connection refused\n")
    : null);
```

Testele nu pornesc nici workeri de coadă reali: `tests/TestCase.php` înlocuiește `WorkerLauncher` cu
`tests/Support/FakeWorkerLauncher.php`, care doar notează workerii care ar fi fost porniți
(`fakeWorkerLauncher()->launched`).

| Fișier                         | Ce verifică                                                                                                                                                                                             |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Feature/DeploymentTest.php`   | deploy complet, oprire la pasul care eșuează + ștergerea release-ului, un singur deploy odată, rollback, anulare, comenzi custom cu variabile, permisiunile după comenzile „după live"                  |
| `Feature/LocalBuildTest.php`   | build local cu un repository git temporar: modificări necomise și commit ne-push-uit opresc deploy-ul înainte de server, fetch eșuat doar avertizează, branch local push-uit, folder fără git           |
| `Feature/ProjectSetupTest.php` | quick setup: importul lui `storage` cu hard link-uri (altfel copie normală), doar când e cerut                                                                                                          |
| `Feature/BuildTest.php`        | upload `.zip` (găsește `public/build`), păstrarea ultimelor N, blocarea „zip slip", deploy cu build salvat (upload SFTP + `tar`)                                                                        |
| `Feature/WorkerTest.php`       | quick setup worker, configurația Supervisor, scheduler on/off                                                                                                                                           |
| `Feature/WebhookTest.php`      | ping GitHub, push pe branch-ul corect / alt branch, opțiune oprită, token greșit                                                                                                                        |
| `Feature/AuthTest.php`         | redirect la login, login cu adminul din seeder, parolă greșită, logout                                                                                                                                  |
| `Feature/PagesTest.php`        | fiecare pagină se încarcă; creare proiect cu comenzile implicite; validări; salvarea `.env`; mesajul „Primul deploy: rulează Quick setup" până la primul deploy reușit                                  |
| `Feature/QueueWorkerTest.php`  | pornirea automată a workerului la un job pus în coadă (nu lângă un worker cu heartbeat, nu cu `AUTODEPLOY_AUTO_WORKER=false`, nu pe `sync`, o singură dată), heartbeat-ul, starea pe pagina deploy-ului |
| `Unit/DeployScriptsTest.php`   | scripturile bash (quote, variabile, activare atomică, shared, cleanup, permisiuni), citirea markerilor, recunoașterea repo-urilor GitHub                                                                |
| `Unit/WorkerLauncherTest.php`  | comanda care pornește workerul pe Windows și Linux (căi cu spații), găsirea PHP-ului de linie de comandă                                                                                                |

## Test end-to-end real (fără server de producție)

Pe lângă testele cu server fals, motorul a fost rulat de la cap la coadă pe un „server" local:

- un repository git de test (bare) + o copie de lucru cu un `build.js` care imită `vite build`;
- deploy-uri succesive cu: build local, build salvat, fără build, iar build local; apoi rollback;
- o dată cu un server de tip **„Acest calculator"** (Git Bash local) și o dată prin **SSH real**, cu un
  server SSH/SFTP de test pornit local (Node.js + pachetul `ssh2`, port 2222, cheie ed25519).

Rezultat: build local salvat și urcat, `.env` lipsă oprește deploy-ul la „Prepare", commit-ul exact
clonat, `composer install`, link-uri `shared`, comenzi custom (afișate înainte de rulare), activare,
păstrarea exactă a ultimelor 3 release-uri, ștergerea release-ului unui deploy eșuat, rollback.
Testul a găsit două bug-uri reale (variabila `COMPOSER`, marcarea release-ului live la curățenie),
reparate și acoperite acum de teste.

Ca să refaci un astfel de test, ai nevoie de un server Linux de test (o mașină virtuală sau un droplet
mic de test) – **nu** folosi serverul de producție pentru experimente.
