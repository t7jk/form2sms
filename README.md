# Form2SMS

> WordPress plugin — wysyła SMS przez [SMSAPI.pl](https://smsapi.pl) po każdym zgłoszeniu formularza Contact Form 7 lub WPForms.

---

## Co robi

Po wypełnieniu formularza przez użytkownika wtyczka natychmiast wysyła powiadomienie SMS na numer administratora. Wiadomość jest budowana z szablonu, w którym możesz osadzić dane przesłane przez formularz (imię, email, telefon itd.).

## Wymagania

| Zależność | Minimalna wersja |
|-----------|-----------------|
| WordPress | 6.0 |
| PHP | 7.4 |
| Contact Form 7 **lub** WPForms | dowolna aktualna |
| Konto SMSAPI.pl (token OAuth) | — |

## Instalacja

1. Pobierz release `.zip` z sekcji [Releases](../../releases) lub sklonuj repozytorium do katalogu `wp-content/plugins/form2sms`.
2. Aktywuj wtyczkę w panelu WordPress → **Wtyczki**.
3. Przejdź do **Narzędzia → Form2SMS** i wprowadź:
   - **Token API** — Bearer token z panelu SMSAPI.pl
   - **Numer telefonu admina** — numer, na który mają trafiać SMS-y
   - **Szablon wiadomości** — możesz użyć tagów `{field_name}` odpowiadających nazwom pól formularza

## Przykładowy szablon

```
Nowe zgłoszenie!
Imię: {your-name}
Email: {your-email}
Tel: {your-phone}
```

## Funkcje

- Obsługa **Contact Form 7** i **WPForms** (każdy z osobną konfiguracją szablonu)
- Podgląd wiadomości z podstawionymi tagami przed wysłaniem
- Przycisk **Wyślij test** — sprawdza połączenie z API bez konieczności wypełniania formularza
- Automatyczne usuwanie polskich znaków diakrytycznych (kompatybilność z bramką SMS)
- Logowanie błędów do `error_log` WordPress

## Struktura projektu

```
form2sms/
├── form2sms.php                  # Główny plik wtyczki
├── includes/
│   ├── class-settings.php        # Strona ustawień (Narzędzia → Form2SMS)
│   ├── class-sms-sender.php      # Budowanie wiadomości + wywołanie SMSAPI
│   ├── class-cf7-handler.php     # Integracja z Contact Form 7
│   └── class-wpforms-handler.php # Integracja z WPForms
├── assets/css/
│   └── admin-settings.css        # Style strony ustawień
└── tests/                        # Testy jednostkowe (PHPUnit + WP test suite)
```

## Testy

```bash
composer install
# skonfiguruj środowisko testowe (skopiuj .env.example → .env i wypełnij dane DB)
./vendor/bin/phpunit
```

## Licencja

GPL-2.0-or-later — zgodnie z ekosystemem WordPress.
