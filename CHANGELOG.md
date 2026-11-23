# Changelog SMS-Awarie

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
