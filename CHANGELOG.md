# Changelog SMS-Awarie

## 4.1.0 – 2026-11-24 (minor)
- Aplikacja instalowana w standardowym folderze stron XAMPP: `C:\xampp\htdocs\sms-awarie` (adres bez zmian: `http://serwer/sms-awarie/`). Nie trzeba już dopisywać `Alias`/`Include` w `httpd.conf`.
- `index.php` i `assets/` przeniesione z `public/` do katalogu głównego aplikacji. Główny `.htaccess` blokuje dostęp z przeglądarki do wszystkiego poza `index.php` i `assets/`, a `data`, `logs`, `cli`, `views`, `web` mają własne `Require all denied`.
- Dashboard (dla administratora) ostrzega, gdy `data/config.json` jest dostępny z przeglądarki (brak `AllowOverride All`). `scripts\verify.ps1` sprawdza to samo.
- `scripts\install.ps1`: domyślnie instaluje do htdocs, przenosi bazę, `config.json` i kopie z `C:\SMS-Awarie` (stary katalog zostaje nienaruszony), komentuje stare wpisy `Include` z 3.x i 4.0.0, wyłącza zadania wskazujące starą lokalizację.
- `install-watchdog-task.ps1` i `install-backup-task.ps1`: parametry `-Root` i `-Replace`. Watchdog domyślnie pilnuje `C:\xampp\htdocs\sms-awarie\receiver.php`.
- Usunięty `apache-sms-awarie.conf`.
- W repozytorium i paczce aplikacja leży w `htdocs/sms-awarie/` (zamiast `app/`) – ten katalog można skopiować wprost do `C:\xampp\htdocs\`.

## 4.0.0 – 2026-11-23 (major)
- Panel WWW przepisany na czysty PHP + HTML + CSS + vanilla JS, serwowany przez Apache z XAMPP pod adresem `/sms-awarie`. Usunięte: Next.js, React, Node.js, npm, zadanie `SMS-Awarie-v3-Web`, reverse proxy na porcie 8090 i `api.php`.
- Nowy układ: wszystko w `C:\SMS-Awarie` (`config.php` z wersją, `public/` jako jedyny katalog widoczny przez Apache, `views/`, `web/`, `cli/`). Ścieżki względne zamiast `C:/SMS-Awarie` wpisanego w kodzie.
- Jeden instalator `scripts\install.ps1` (podgląd / `-Apply`) dla nowej instalacji i aktualizacji z 3.x: kopia zapasowa, kopiowanie plików, migracja bazy, wpis w `httpd.conf`, wyłączenie panelu 3.x. Usunięte skrypty 02, 04, 10, 11, 12, 15, 20, 21 i `README-DEPLOY.md`.
- Schemat bazy v3: kolejne numery zgłoszeń w roku (`AW/2026/00042`; starsze zachowują numer z 3.x), wyłączanie pojazdów i kont (soft delete), indeks `tickets(plate)`.
- Zgłoszenia: filtry (status, grupa, wyszukiwanie), paginacja, edycja w rozwijanej karcie, eksport CSV (UTF-8 z BOM, `;`). SMS odebrane/wychodzące z filtrami i paginacją.
- Pierwszy administrator zakładany w przeglądarce, gdy w bazie nie ma kont; `cli\reset-password.php` na wypadek zapomnianego hasła. Edycja ról, haseł i wyłączanie kont.
- Ustawienia modemu w panelu: adres, hasło, usuwanie SMS z modemu, liczba SMS na cykl. Zakładka Baza danych: kopia na żądanie, kontrola integralności, lista kopii.
- Daty w formacie DD.MM.RRRR, plakietka wersji z changelogiem, wygląd na PC i telefonach/Zebrach, wydruk przez `@media print`.
- Usunięta migracja do MS SQL (nie obejmowała grup i nie była używana przez odbiornik).

## 3.1.1 – 2026-11-23 (patch)
- Polskie znaki, nawiasy i cudzysłowy w SMS nie blokują już odbiornika ani wysyłki. Treść powiadomień jest transliterowana do ASCII (ą→a), a wiadomość, której nie da się przetworzyć, dostaje status `BLAD` zamiast zatrzymywać pętlę.
- Odebrane SMS-y są usuwane z modemu dopiero po zapisie w bazie (`delete_after_import`, domyślnie włączone). Na Dashboardzie widać zajętość pamięci SMS modemu.
- Zakładka Ustawienia (autoodpowiedzi) synchronizuje się z grupą Pojazdy – zmiana działa w odbiorniku.
- Migracje schematu uruchamiają się raz (`PRAGMA user_version = 2`), a nie przy każdym zapytaniu API. Dodane indeksy.
- Dziennik odbiornika w panelu czyta `logs\receiver-stdout.log` i `receiver-stderr.log`. Watchdog rotuje logi powyżej 5 MB i zachowuje log poprzedniego uruchomienia (`.1`).
- Listy zgłoszeń i grup bez zapytań N+1; listy zgłoszeń, SMS i outbox ograniczone do 1000 najnowszych.
- Do 5 SMS wysyłanych w jednym cyklu (`send_per_cycle`).
- Odbiornik nie startuje z hasłem `CHANGE_ME`; po odrzuconym logowaniu czeka 5 minut. Obsługa logowania `password_type 4` (nowsze firmware Huawei).
- Nowy `backup.php` i `scripts\16-install-backup-task.ps1` – codzienna kopia SQLite z retencją.
- Strefa czasowa `Europe/Warsaw`, `APP_VERSION` i changelog w panelu.
- Frontend: `skipLibCheck` w `tsconfig.json` – bez tego `npm run typecheck` na świeżej instalacji kończył się błędem w typach `lucide-react`.

## 3.1 
- Grupy zgłoszeń z prefiksami, wielu odbiorców, Watchdog v3.
