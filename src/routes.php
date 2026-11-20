<?php
/** Wszystkie adresy aplikacji. 'auth' => true wymaga zalogowania. POST zawsze sprawdza token CSRF (brak tokenu = odpowiedź 403). */

declare(strict_types=1);

use App\Core\Router;
use App\Http\AuditController;
use App\Http\AuthController;
use App\Http\CompanyController;
use App\Http\ContentController;
use App\Http\CronController;
use App\Http\DashboardController;
use App\Http\InboundController;
use App\Http\LibraryController;
use App\Http\OfferController;
use App\Http\OutreachController;
use App\Http\SettingsController;
use App\Http\TenderController;
use App\Http\WidgetController;

return static function (Router $r): void {
    $a = ['auth' => true];

    // Strony publiczne i konto
    $r->get('/', [AuthController::class, 'home']);
    $r->get('/logowanie', [AuthController::class, 'loginForm']);
    $r->post('/logowanie', [AuthController::class, 'login']);
    $r->get('/rejestracja', [AuthController::class, 'registerForm']);
    $r->post('/rejestracja', [AuthController::class, 'register']);
    $r->post('/wyloguj', [AuthController::class, 'logout']);
    $r->get('/haslo', [AuthController::class, 'forgotForm']);
    $r->post('/haslo', [AuthController::class, 'forgot']);
    $r->get('/haslo/{token}', [AuthController::class, 'resetForm']);
    $r->post('/haslo/{token}', [AuthController::class, 'reset']);

    // Raport audytu treści dla klienta: link z losowym tokenem, bez logowania
    $r->get('/r/{token}', [ContentController::class, 'publicReport']);

    // Zadania cykliczne (wywołuje GitHub Actions lub cron hostingu z tokenem)
    $r->get('/cron/run', [CronController::class, 'run']);
    $r->post('/cron/run', [CronController::class, 'run'], ['csrf' => false]);

    // Widżet zapytań na stronie firmy: bez logowania, bez sesji i bez tokenu CSRF. Chroni go lista dozwolonych stron i limity.
    $r->get('/w/{file}', [WidgetController::class, 'script']);
    $r->post('/w/{id}/start', [WidgetController::class, 'start'], ['csrf' => false]);
    $r->post('/w/{id}/answer', [WidgetController::class, 'answer'], ['csrf' => false]);
    $r->post('/w/{id}/ask', [WidgetController::class, 'ask'], ['csrf' => false]);
    $r->post('/w/{id}/lead', [WidgetController::class, 'lead'], ['csrf' => false]);

    $r->get('/pulpit', [DashboardController::class, 'index'], $a);

    // Zapytania ze strony
    $r->get('/zapytania', [InboundController::class, 'index'], $a);
    $r->get('/zapytania/ustawienia', [InboundController::class, 'settings'], $a);
    $r->post('/zapytania/ustawienia', [InboundController::class, 'saveSettings'], $a);
    $r->get('/zapytania/podglad', [InboundController::class, 'preview'], $a);
    $r->get('/zapytania/wyniki', [InboundController::class, 'results'], $a);
    $r->get('/zapytania/wiedza', [InboundController::class, 'knowledge'], $a);
    $r->post('/zapytania/wiedza', [InboundController::class, 'knowledgeAdd'], $a);
    $r->post('/zapytania/wiedza/{id}/usun', [InboundController::class, 'knowledgeDelete'], $a);
    $r->get('/zapytania/{id}', [InboundController::class, 'show'], $a);
    $r->post('/zapytania/{id}/status', [InboundController::class, 'status'], $a);
    $r->post('/zapytania/{id}/usun', [InboundController::class, 'delete'], $a);

    // Przetargi
    $r->get('/przetargi', [TenderController::class, 'index'], $a);
    $r->get('/przetargi/szukaj', [TenderController::class, 'search'], $a);
    $r->get('/przetargi/{id}', [TenderController::class, 'show'], $a);
    $r->post('/przetargi/{id}/status', [TenderController::class, 'status'], $a);
    $r->post('/przetargi/{id}/dokumenty', [TenderController::class, 'upload'], $a);
    $r->post('/przetargi/{id}/dokumenty/{doc}/usun', [TenderController::class, 'deleteDocument'], $a);
    $r->post('/przetargi/{id}/analiza', [TenderController::class, 'analyze'], $a);
    $r->post('/przetargi/{id}/pytanie', [TenderController::class, 'ask'], $a);
    $r->get('/przetargi/{id}/kalendarz', [TenderController::class, 'calendar'], $a);
    $r->get('/profile', [TenderController::class, 'profiles'], $a);
    $r->get('/profile/nowy', [TenderController::class, 'profileForm'], $a);
    $r->post('/profile', [TenderController::class, 'profileSave'], $a);
    $r->get('/profile/{id}', [TenderController::class, 'profileForm'], $a);
    $r->post('/profile/{id}', [TenderController::class, 'profileSave'], $a);
    $r->post('/profile/{id}/usun', [TenderController::class, 'profileDelete'], $a);

    // Oferty, wnioski i biblioteka firmy
    $r->get('/oferty', [OfferController::class, 'index'], $a);
    $r->post('/oferty', [OfferController::class, 'create'], $a);
    $r->get('/oferty/{id}', [OfferController::class, 'show'], $a);
    $r->post('/oferty/{id}', [OfferController::class, 'update'], $a);
    $r->post('/oferty/{id}/sekcje/{key}', [OfferController::class, 'saveSection'], $a);
    $r->post('/oferty/{id}/sekcje/{key}/ai', [OfferController::class, 'aiSection'], $a);
    $r->post('/oferty/{id}/dokumenty', [OfferController::class, 'upload'], $a);
    $r->get('/oferty/{id}/docx', [OfferController::class, 'docx'], $a);
    $r->post('/oferty/{id}/usun', [OfferController::class, 'delete'], $a);
    $r->get('/biblioteka', [LibraryController::class, 'index'], $a);
    $r->post('/biblioteka', [LibraryController::class, 'save'], $a);
    $r->get('/biblioteka/{id}', [LibraryController::class, 'edit'], $a);
    $r->post('/biblioteka/{id}', [LibraryController::class, 'save'], $a);
    $r->post('/biblioteka/{id}/usun', [LibraryController::class, 'delete'], $a);

    // Kontrahenci i należności
    $r->get('/kontrahenci', [CompanyController::class, 'index'], $a);
    $r->get('/kontrahenci/sprawdz', [CompanyController::class, 'check'], $a);
    $r->post('/kontrahenci/alerty', [CompanyController::class, 'readAlerts'], $a);
    $r->get('/kontrahenci/{nip}', [CompanyController::class, 'show'], $a);
    $r->post('/kontrahenci/{nip}/odswiez', [CompanyController::class, 'refresh'], $a);
    $r->post('/kontrahenci/{nip}/obserwuj', [CompanyController::class, 'watch'], $a);
    $r->post('/kontrahenci/{nip}/naleznosci', [CompanyController::class, 'addReceivable'], $a);
    $r->post('/naleznosci/{id}', [CompanyController::class, 'updateReceivable'], $a);
    $r->get('/naleznosci/{id}/wezwanie', [CompanyController::class, 'demand'], $a);

    // Klienci i wiadomości
    $r->get('/klienci', [OutreachController::class, 'index'], $a);
    $r->get('/klienci/nowy', [OutreachController::class, 'contactForm'], $a);
    $r->post('/klienci', [OutreachController::class, 'contactSave'], $a);
    $r->get('/klienci/{id}', [OutreachController::class, 'contactForm'], $a);
    $r->post('/klienci/{id}', [OutreachController::class, 'contactSave'], $a);
    $r->post('/klienci/{id}/wypisz', [OutreachController::class, 'unsubscribe'], $a);
    $r->get('/klienci/{id}/wiadomosc', [OutreachController::class, 'compose'], $a);
    $r->post('/klienci/{id}/wiadomosc', [OutreachController::class, 'saveMessage'], $a);
    $r->get('/wiadomosci/{id}', [OutreachController::class, 'message'], $a);
    $r->get('/wiadomosci/{id}/eml', [OutreachController::class, 'eml'], $a);
    $r->post('/wiadomosci/{id}/wyslana', [OutreachController::class, 'markSent'], $a);

    // Audyt strony, konkurencja i reklamy
    $r->get('/audyt', [AuditController::class, 'index'], $a);
    $r->post('/audyt', [AuditController::class, 'run'], $a);
    $r->get('/audyt/porownanie', [AuditController::class, 'compare'], $a);
    // Audyt treści pod wyszukiwarki i AI (trasy muszą być przed /audyt/{id})
    $r->get('/audyt/tresc', [ContentController::class, 'index'], $a);
    $r->post('/audyt/tresc', [ContentController::class, 'add'], $a);
    $r->get('/audyt/wytyczne', [ContentController::class, 'guidelines'], $a);
    $r->get('/audyt/tresc/mapa', [ContentController::class, 'sitemap'], $a);
    $r->post('/audyt/tresc/mapa', [ContentController::class, 'sitemapAdd'], $a);
    $r->post('/audyt/tresc/audyt/{id}/krok', [ContentController::class, 'step'], $a);
    $r->post('/audyt/tresc/audyt/{id}/dokoncz', [ContentController::class, 'finish'], $a);
    $r->get('/audyt/tresc/{id}', [ContentController::class, 'show'], $a);
    $r->post('/audyt/tresc/{id}/audyt', [ContentController::class, 'run'], $a);
    $r->get('/audyt/tresc/{id}/porownanie', [ContentController::class, 'compare'], $a);
    $r->post('/audyt/tresc/{id}/pytania', [ContentController::class, 'questions'], $a);
    $r->post('/audyt/tresc/{id}/link', [ContentController::class, 'share'], $a);
    $r->post('/audyt/tresc/{id}/usun', [ContentController::class, 'delete'], $a);
    $r->get('/audyt/{id}', [AuditController::class, 'show'], $a);
    $r->get('/reklamy', [AuditController::class, 'ads'], $a);

    // Ustawienia
    $r->get('/ustawienia', [SettingsController::class, 'index'], $a);
    $r->post('/ustawienia', [SettingsController::class, 'save'], $a);
    $r->post('/ustawienia/haslo', [SettingsController::class, 'password'], $a);
    $r->post('/ustawienia/zespol', [SettingsController::class, 'invite'], $a);
    $r->post('/ustawienia/test-ai', [SettingsController::class, 'testAi'], $a);
    $r->post('/ustawienia/wyglad', [SettingsController::class, 'appearance'], $a);
    $r->post('/ustawienia/test-poczty', [SettingsController::class, 'testMail'], $a);
    $r->post('/ustawienia/przeglad', [SettingsController::class, 'digest'], $a);
    $r->get('/ustawienia/uokik', [SettingsController::class, 'uokik'], $a);
    $r->post('/ustawienia/uokik', [SettingsController::class, 'uokikUpload'], $a);
};
