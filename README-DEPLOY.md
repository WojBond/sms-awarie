# SMS-Awarie v3.0 — pakiet wdrożeniowy Windows 10/11

Kompletny **kod aplikacji** z ostatniego stable candidate, Watchdog v3, PHP API, frontend Next.js oraz skrypty do instalacji zadań Windows i konfiguracji Apache. Szczegółowa instrukcja: `docs/WDROZENIE-WINDOWS-LAN.md`. Procedura odzyskiwania: `docs/ODZYSKIWANIE.md`.

**Nie zawiera** PHP, Apache/XAMPP, Node.js, `node_modules`, buildu `.next`, danych SQLite, `config.json`, sekretów ani `package-lock.json`. Jest to pakiet źródłowy do przygotowania i przetestowania na docelowej maszynie, nie obraz gotowy do uruchomienia bez zależności. Zachowaj istniejący lockfile lub wygeneruj i zweryfikuj nowy na środowisku testowym.

Wdrożenie na nowym komputerze wymaga osobnego, spójnego przeniesienia danych i konfiguracji oraz kontroli uprawnień. Skrypt instalacji nie nadpisuje danych. Nie uruchamiaj instalatora w trakcie pracy odbiornika. Dostęp firmowy wymaga HTTPS, a nie tylko otwarcia portu HTTP.
