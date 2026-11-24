# Procedura odzyskiwania SMS-Awarie po awarii

## 1. Klasyfikacja problemu
- **Panel nie działa, dane są poprawne:** sprawdź `C:\xampp\apache\logs\error.log`; w razie potrzeby przywróć pliki programu z `C:\SMS-Awarie-Backups\pre-...\app`; zachowaj aktualną SQLite.
- **Receiver zawieszony:** sprawdź `watchdog-state.json`, `heartbeat.json`, proces PID, `watchdog-events.jsonl`, limity restartów i `watchdog.pause`. Nie uruchamiaj drugiego odbiornika ręcznie, jeśli pierwszy działa.
- **Niepewna wysyłka SMS:** nie zmieniaj automatycznie statusów `W_TRAKCIE` / `NIEPEWNY`; porównaj z historią modemu i dokumentacją operacyjną. `PRZYJETY` nie oznacza doręczenia.
- **Uszkodzona baza:** najpierw zabezpiecz oryginał wraz z WAL/SHM, następnie wykonaj diagnostykę i odzyskuj na kopii.

## 2. Bezpieczny rollback kodu
1. Utwórz `<katalog aplikacji>\data\watchdog.pause`, wyłącz nowe zadanie Harmonogramu i inne autostarty.
2. Zweryfikuj pełną ścieżkę i PID `receiver.php`. Poczekaj na koniec przetwarzania; zatrzymaj proces dopiero w oknie serwisowym.
3. Zabezpiecz aktualną bazę, konfigurację, heartbeat, logi i kopię obecnego kodu (materiał do analizy).
4. Przywróć poprzednie pliki programu z `C:\SMS-Awarie-Backups\pre-...\app` (bez katalogu `data`) oraz `httpd.conf` z tej samej kopii. Nie podmieniaj `awarie.sqlite`.
5. Zrestartuj Apache (przy powrocie do 3.x włącz zadanie `SMS-Awarie-v3-Web`); usuń `watchdog.pause` dopiero po zakończeniu prac.
6. Sprawdź autoryzację, zgłoszenia, heartbeat i statusy `outbox`.

## 3. Przywracanie SQLite — tylko po potwierdzonej awarii danych
1. Zatrzymaj wszystkie procesy korzystające z SQLite (receiver, Apache, zadania backupu); zabezpiecz oryginalne `awarie.sqlite`, `awarie.sqlite-wal`, `awarie.sqlite-shm` razem w odrębnej lokalizacji.
2. Zweryfikuj datę i integralność kopii logicznej (`PRAGMA integrity_check = ok`) na **osobnej kopii roboczej**.
3. Ustal punkt odtworzenia i porównaj z dziennikami/wiadomościami modemu. Przywrócenie wcześniejszego pliku może utracić SMS, zgłoszenia i zmiany statusów utworzone po backupie.
4. Po zgodzie właściciela danych podmień bazę na zweryfikowaną kopię logiczną **przy zatrzymanych procesach**; nie mieszaj starej bazy z nowymi plikami WAL/SHM. Zostaw niezmienioną konfigurację.
5. Włącz aplikację i sprawdź `PRAGMA integrity_check`, liczbę `sms`, `tickets`, `outbox` oraz pojedynczy proces odbiornika. Odtwórz brakujące zgłoszenia tylko po porównaniu z rzeczywistą historią, bez automatycznego ponawiania wysyłek.

## 4. Komendy diagnostyczne
```powershell
Get-ScheduledTask | Where-Object TaskName -like '*SMS*'
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*receiver.php*' | Select-Object ProcessId,CommandLine
Get-Content 'C:\xampp\htdocs\sms-awarie\data\watchdog-state.json' -Raw
Get-Content 'C:\xampp\htdocs\sms-awarie\data\heartbeat.json' -Raw
Get-Content 'C:\xampp\htdocs\sms-awarie\logs\watchdog-events.jsonl' -Tail 50
& 'C:\xampp\php\php.exe' -r '$db=new PDO("sqlite:C:/xampp/htdocs/sms-awarie/data/awarie.sqlite"); echo $db->query("PRAGMA integrity_check")->fetchColumn();'
```

## 5. Postępowanie przy braku możliwości odzyskania
Zabezpiecz bazę i logi, nie wysyłaj ponownie wiadomości o niepewnym wyniku, przełącz obsługę awarii na procedurę ręczną i skonsultuj przywrócenie z administratorem SQL/SQLite.
