# SMS-Awarie v3.0 — kandydat stabilnego wydania (PL)

## Zakres i ograniczenia
Pakiet scala oryginalny frontend/API z aktualizacją Etap 2 i Watchdog v3. Obejmuje PHP/SQLite, Next.js i skrypty administracyjne. **Nie zawiera** danych produkcyjnych, haseł, `data/config.json`, `awarie.sqlite`, katalogów logów, `.next`, `node_modules` ani binariów Node/PHP. Nie przeprowadzono testów integracyjnych na docelowym Windows/modemie, dlatego wydanie jest **kandydatem do stabilnego wdrożenia**, nie potwierdzoną produkcyjną wersją stabilną.

## A. Przygotowanie bez przerywania pracy
1. Rozpakuj paczkę poza katalogami produkcyjnymi, np. `C:\SMS-Awarie-Release-v3`. **Nie kopiuj jeszcze plików do produkcji**.
2. W PowerShell uruchom `scripts\\01-preflight.ps1` (odczyt stanu). Spisz aktywne zadania Watchdoga i uruchomienia odbiornika; nie dopuszczaj do dwóch mechanizmów autostartu.
3. Zrób spójną kopię SQLite: `& 'C:\xampp\php\php.exe' 'C:\SMS-Awarie-Release-v3\scripts\02-backup-sqlite.php' 'C:\SMS-Awarie\data\awarie.sqlite' 'D:\SMS-Awarie-Backup\awarie-before-v3.sqlite'`. Wybierz istniejący, chroniony katalog docelowy; plik docelowy nie może istnieć. Sprawdź wynik `OK snapshot`.
4. Zabezpiecz `C:\SMS-Awarie\data\config.json` oraz pliki programu i konfigurację Apache w zaszyfrowanej lokalizacji. Ogranicz dostęp do kopii — zawiera hasło modemu/SQL. Zapisz nazwę zadania i konto uruchamiające usługi.
5. Przetestuj pliki PHP z `payload` przez `php.exe -l` oraz kompilację kopii frontendu (`npm.cmd ci`, `npm.cmd run typecheck`, `npm.cmd run build`) na odizolowanej kopii katalogu `web`. Sprawdź, czy `npm.cmd ci` nie zmienia produkcji. `package-lock.json` należy zachować z istniejącej instalacji, jeśli go nie ma w pakiecie.
6. Zrób planowaną przerwę wdrożeniową, powiadom operatorów i zapisz stan kolejki `outbox`, szczególnie `W_TRAKCIE` i `NIEPEWNY`.

## B. Okno wdrożeniowe
1. Wyłącz stare zadanie Watchdoga i inne autostarty; utwórz `C:\SMS-Awarie\data\watchdog.pause`. Uwaga: stare skrypty mogą ignorować tę flagę.
2. Po zakończeniu bieżącej operacji kontrolowanie zatrzymaj pojedynczy proces `receiver.php`; potwierdź PID i pełną ścieżkę przed zatrzymaniem. **Nie usuwaj `receiver.lock`**.
3. Po zamknięciu wszystkich procesów korzystających z bazy wykonaj drugą kopię katalogu `data` z `-wal` i `-shm` (jeśli występują). Spójną kopię `VACUUM INTO` z kroku A zachowaj niezależnie. Skopiuj wszystkie nadpisywane pliki oraz działający build frontendu do folderu rollback poza katalogami aplikacji.
4. Skopiuj `payload\\C-SMS-Awarie\\common.php`, `receiver.php`, `watchdog.ps1` do `C:\SMS-Awarie\`. Skopiuj `payload\\C-SMS-Awarie-Web\\php\\*.php` do `C:\SMS-Awarie-Web\php\` i `payload\\C-SMS-Awarie-Web\\web` do odpowiedniego katalogu aplikacji. **Nie nadpisuj** `data`, `logs`, `config.json`, `awarie.sqlite`, `package-lock.json` ani lokalnej konfiguracji Apache bez ręcznej kontroli różnic.
5. Wykonaj `scripts\\04-verify.ps1`. Zbuduj frontend (`npm.cmd run typecheck`, `npm.cmd run build`), zatrzymaj i uruchom ponownie usługę Node według istniejącej konfiguracji; sprawdź API PHP i autoryzację administratora.
6. Uruchom `receiver.php` w trybie testowym tylko jako **jedną** instancję. Sprawdź heartbeat, odbiór jednego SMS, jeden rekord `sms` i jedno zgłoszenie; nie testuj wysyłania bez zgody. Zatrzymaj testowy odbiornik.
7. Zainstaluj jedno zadanie Watchdoga przez `scripts\\03-install-watchdog-task.ps1` (PowerShell jako administrator). Zweryfikuj konto serwisowe, uprawnienia NTFS, ustawienie „Nie uruchamiaj nowego wystąpienia”. Nie uruchamiaj starego zadania.
8. Usuń `watchdog.pause`, uruchom nowe zadanie i potwierdź jeden proces `receiver.php`, świeży heartbeat, status Dashboardu, wpisy w `watchdog-events.jsonl` i `watchdog-state.json`.
9. Obserwuj przez minimum jeden cykl kontroli, a najlepiej dłużej, i przeprowadź test restartu **tylko w środowisku testowym**. Dopiero po zatwierdzeniu testów oznacz wydanie jako stabilne.

## C. Kryteria akceptacji
- `php -l` poprawne, `npm run typecheck` i `npm run build` kończą się bez błędu.
- Odbiornik ma jeden PID; heartbeat aktualizuje `time`, `pid`, `state`, `phase`.
- Watchdog nie restartuje zdrowego procesu; wykrywa awarię i respektuje limity 3/h oraz cooldown 300 s.
- Nie ma duplikatów `sms`/`tickets` po ponownym pobraniu tej samej wiadomości.
- `outbox.status IN ('W_TRAKCIE','NIEPEWNY')` nie zmienia się samoczynnie i nie jest automatycznie ponawiany.
- Administrator widzi zdarzenia i wiadomości do weryfikacji; operator i `read-only` nie mają dostępu do diagnostyki administracyjnej.
- SQLite `PRAGMA integrity_check` zwraca `ok`; liczby rekordów nie maleją w sposób nieoczekiwany.

## D. Przywrócenie poprzedniej wersji
Szczegóły: `docs\\ODZYSKIWANIE.md`. Nie przywracaj starej bazy tylko z powodu błędu aplikacji — utracisz nowe zgłoszenia.
