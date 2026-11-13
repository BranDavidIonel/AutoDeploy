# Documentația AutoDeploy

AutoDeploy este aplicația ta de deploy pentru proiectele Laravel de pe serverul DigitalOcean
(sau orice server Linux cu SSH). Seamănă cu Envoyer / DeployBot / Forge, dar e făcută pentru
situația ta: serverul are puțin RAM, așa că build-ul de front-end (`npm run build`) se face pe
PC și doar rezultatul se urcă pe server.

| Document                                                | Pentru cine       | Ce găsești                                                                                        |
| ------------------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------- |
| [01 – Cum funcționează](01-cum-functioneaza.md)         | toată lumea       | ideea, conceptele, ce se întâmplă la un deploy, rollback, structura de pe server                  |
| [02 – Instalare și pornire](02-instalare-si-pornire.md) | cine o instalează | cerințe, `setup-autodeploy.bat`, `start-autodeploy.bat`, variabile `.env`, rulare pe Linux        |
| [03 – Ghid de utilizare](03-ghid-de-utilizare.md)       | cine face deploy  | pas cu pas: server, proiect, quick setup, primul deploy, build salvat, workeri, rollback, webhook |
| [04 – Arhitectura codului](04-arhitectura.md)           | developeri        | structura codului, modele și tabele, motorul de deploy, frontend, securitate                      |
| [05 – Teste](05-teste.md)                               | developeri        | testele Pest, conexiunea falsă, testul end-to-end local și prin SSH                               |
| [06 – Probleme frecvente](06-probleme-frecvente.md)     | toată lumea       | erori des întâlnite și cum se rezolvă                                                             |
| [07 – Instalare pe server](07-instalare-pe-server.md)   | cine o instalează | `install.sh` pe Ubuntu (Apache + PHP-FPM), SSH la root@127.0.0.1, mutarea datelor de pe PC        |

Pe scurt, pentru a porni aplicația pe Windows:

1. dublu-click pe `setup-autodeploy.bat` (doar prima dată);
2. dublu-click pe `start-autodeploy.bat`;
3. intră pe <http://127.0.0.1:8077> cu emailul și parola de admin din `.env`
   (`AUTODEPLOY_ADMIN_EMAIL` / `AUTODEPLOY_ADMIN_PASSWORD`).

Pe un server Ubuntu (Apache + PHP-FPM), AutoDeploy se instalează cu `deploy/server/install.sh` și își ia
datele de pe PC cu `deploy/server/import-local-data.sh`: vezi
[07 – Instalare pe server](07-instalare-pe-server.md).
