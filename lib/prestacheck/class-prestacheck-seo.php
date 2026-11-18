<?php
/**
 * PrestaCheck – audyt SEO (czysty PHP, bez sieci).
 *
 * Dostaje pobrane odpowiedzi (strona główna, robots.txt, mapa strony, karta produktu, sondy)
 * i zwraca wyniki w formacie skanera. Sprawdzenia, dla których nie mamy danych, są pomijane –
 * brak danych nigdy nie jest raportowany jako problem.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRESTACHECK_STANDALONE' ) ) {
	exit;
}

class PrestaCheck_SEO {

	/** @var callable(string,string,string,string,string,?string,?string,int):array */
	private $finding;

	public function __construct( callable $finding ) {
		$this->finding = $finding;
	}

	private function f( $id, $sev, $title, $detail, $fix, $points, $cat = 'seo', $service = 'seo' ) {
		return call_user_func( $this->finding, $id, $cat, $sev, $title, $detail, $fix, 'ok' === $sev ? null : $service, 'ok' === $sev ? 0 : $points );
	}

	/* ------------------------------------------------------------------ */
	/*  Wyszukiwanie adresów do sprawdzenia                                 */
	/* ------------------------------------------------------------------ */

	/** Adresy map strony z robots.txt (Sitemap: ...). */
	public static function robots_sitemaps( $robots_body ) {
		$out = array();
		if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/im', (string) $robots_body, $m ) ) {
			foreach ( $m[1] as $u ) {
				if ( preg_match( '#^https?://#i', $u ) ) {
					$out[] = $u;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Pierwszy link do karty produktu PrestaShop na stronie (…/123-nazwa.html, …/12-34-nazwa.html). */
	public static function find_product_url( $html, $base ) {
		$host = parse_url( $base, PHP_URL_HOST );
		if ( ! preg_match_all( '~href=["\']((?:https?://[^"\'/]+)?/[^"\'\s?#]*?/?\d+(?:-\d+)?-[a-z0-9][^"\'\s?#/]*\.html)["\']~i', (string) $html, $m ) ) {
			return null;
		}
		foreach ( $m[1] as $u ) {
			if ( preg_match( '#/(?:content|cms|blog|module|modules)/#i', $u ) ) {
				continue;
			}
			if ( '/' === $u[0] ) {
				$u = rtrim( $base, '/' ) . $u;
			}
			if ( strtolower( (string) parse_url( $u, PHP_URL_HOST ) ) === strtolower( (string) $host ) ) {
				return html_entity_decode( $u, ENT_QUOTES, 'UTF-8' );
			}
		}
		return null;
	}

	/** Z indeksu map strony wybiera plik z produktami (PrestaShop: 1_pl_0_sitemap.xml itp.). */
	public static function pick_child_sitemap( $xml ) {
		if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\s\]]+)#i', (string) $xml, $m ) ) {
			return null;
		}
		foreach ( $m[1] as $u ) {
			if ( preg_match( '/product|produkt/i', $u ) ) {
				return $u;
			}
		}
		return $m[1][0];
	}

	public static function product_from_sitemap( $xml, $base ) {
		return self::find_product_url( preg_replace( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\s\]]+)\s*(?:\]\]>)?\s*</loc>#i', 'href="$1"', (string) $xml ), $base );
	}

	/**
	 * Sklepy z „ładnymi” adresami bez numeru produktu (moduły przyjaznych URL): pierwszy adres
	 * w postaci /kategoria/nazwa-produktu. To tylko kandydat – trzeba go potwierdzić is_product_page().
	 */
	public static function product_guess_from_sitemap( $xml, $base ) {
		if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\s\]]+)#i', (string) $xml, $m ) ) {
			return null;
		}
		$base = rtrim( $base, '/' );
		foreach ( $m[1] as $u ) {
			$u = html_entity_decode( $u, ENT_QUOTES, 'UTF-8' );
			if ( 0 !== strpos( $u, $base . '/' ) || preg_match( '/[?#]|\.(xml|pdf|jpe?g|png)$/i', $u ) ) {
				continue;
			}
			$path = substr( $u, strlen( $base ) );
			if ( preg_match( '#^/(?:[a-z]{2}/)?[^/]+/[^/]*-[^/]*-[^/]+/?$#i', $path ) && ! preg_match( '#/(?:content|cms|blog|module|modules|brand|supplier|manufacturer)/#i', $path ) ) {
				return $u;
			}
		}
		return null;
	}

	/** Czy pobrana strona to karta produktu PrestaShop (body#product, klasa page-product, og:type product). */
	public static function is_product_page( $html ) {
		return (bool) preg_match( '/<body\b[^>]*(?:\bid=["\']product["\']|class=["\'][^"\']*\bpage-product\b)|"page_name"\s*:\s*"product"|property=["\']og:type["\'][^>]*content=["\'](?:og:)?product["\']/i', (string) $html );
	}

	/* ------------------------------------------------------------------ */
	/*  Analiza                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array  $page    strona główna (status, headers, body, url)
	 * @param array  $p       sondy: robots, sitemap_* , notfound, althost, product (opcjonalne)
	 * @param bool   $https   czy strona działa na https
	 */
	public function analyze( array $page, array $p, $https ) {
		$html  = (string) $page['body'];
		$host  = strtolower( (string) parse_url( $page['url'], PHP_URL_HOST ) );
		$out   = array();

		$out = array_merge( $out, $this->indexing( $page, isset( $p['robots'] ) ? $p['robots'] : null ) );
		$out = array_merge( $out, $this->home_basics( $html, $host, $https ) );
		$out = array_merge( $out, $this->sitemap( $p, $host ) );
		$out = array_merge( $out, $this->duplicates( $p, $host ) );
		$out = array_merge( $out, $this->images( $html ) );
		if ( $https ) {
			$out = array_merge( $out, $this->mixed_content( $html ) );
		}
		if ( isset( $p['product'] ) && ( empty( $p['product']['guess'] ) || self::is_product_page( $p['product']['body'] ) ) ) {
			$out = array_merge( $out, $this->product( $p['product'], $html ) );
		}
		return $out;
	}

	/** noindex w meta i w nagłówku X-Robots-Tag, robots.txt blokujący cały sklep. */
	private function indexing( array $page, $robots ) {
		$out  = array();
		$meta = preg_match( '/<meta[^>]+name=["\'](?:robots|googlebot)["\'][^>]*content=["\'][^"\']*noindex/i', $page['body'] );
		$hdr  = isset( $page['headers']['x-robots-tag'] ) && preg_match( '/noindex/i', implode( ' ', $page['headers']['x-robots-tag'] ) );
		if ( $meta || $hdr ) {
			$out[] = $this->f( 'noindex', 'critical', 'Strona główna jest ukryta przed Google (noindex)', ( $hdr ? 'Serwer wysyła nagłówek X-Robots-Tag: noindex' : 'Strona ma znacznik robots noindex' ) . ', czyli prosi wyszukiwarki, żeby nie pokazywały jej w wynikach. Często to pozostałość po wersji testowej.', 'Usunięcie noindex i zgłoszenie strony w Google Search Console.', 6 );
		}

		if ( $robots && 200 === (int) $robots['status'] && false === stripos( substr( $robots['body'], 0, 300 ), '<html' ) ) {
			if ( self::robots_blocks_all( $robots['body'] ) ) {
				$out[] = $this->f( 'robots_block', 'critical', 'Plik robots.txt blokuje cały sklep przed Google', 'W robots.txt jest reguła „Disallow: /” dla wszystkich robotów. Google nie może odwiedzać żadnej strony sklepu, więc z czasem znika on z wyników wyszukiwania.', 'Pilna poprawka robots.txt (zwykle pozostałość po wersji testowej) i ponowne zgłoszenie sklepu w Search Console.', 10 );
			}
		}
		return $out;
	}

	public static function robots_blocks_all( $txt ) {
		$agents = array();
		$block  = false;
		$in     = false;
		foreach ( preg_split( '/\r?\n/', (string) $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				if ( ! $in ) {
					$agents = array();
				}
				$agents[] = strtolower( trim( $m[1] ) );
				$in       = true;
				continue;
			}
			$in = false;
			if ( preg_match( '/^disallow:\s*\/\s*$/i', $line ) && ( in_array( '*', $agents, true ) || in_array( 'googlebot', $agents, true ) ) ) {
				$block = true;
			}
			if ( preg_match( '/^allow:\s*\/\s*$/i', $line ) && ( in_array( '*', $agents, true ) || in_array( 'googlebot', $agents, true ) ) ) {
				return false;
			}
		}
		return $block;
	}

	/** Tytuł, opis, H1, canonical, hreflang na stronie głównej. */
	private function home_basics( $html, $host, $https ) {
		$out = array();

		$title = self::title( $html );
		$tl    = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		if ( '' === $title ) {
			$out[] = $this->f( 'title', 'critical', 'Brak tytułu strony', 'Strona główna nie ma znacznika <title>, który Google pokazuje jako nagłówek w wynikach wyszukiwania.', 'Dodanie tytułu z najważniejszymi frazami.', 3 );
		} elseif ( $tl < 15 || $tl > 70 ) {
			$out[] = $this->f( 'title', 'warning', 'Tytuł strony ma nieoptymalną długość (' . $tl . ' znaków)', 'Optymalny tytuł ma ok. 30–60 znaków. Obecny: „' . self::cut( $title, 80 ) . '”.', 'Przeredagowanie tytułu pod kluczowe frazy.', 1 );
		} else {
			$out[] = $this->f( 'title', 'ok', 'Tytuł strony poprawny', '„' . self::cut( $title, 80 ) . '”', null, 0 );
		}

		if ( '' === self::meta_description( $html ) ) {
			$out[] = $this->f( 'description', 'warning', 'Brak opisu meta description', 'Google sam wybierze fragment tekstu do wyników wyszukiwania – zwykle przypadkowy i mało zachęcający do kliknięcia.', 'Napisanie opisu, który zachęca do wejścia (ok. 140–160 znaków).', 3 );
		}

		$h1 = self::h1s( $html );
		if ( ! $h1 ) {
			$out[] = $this->f( 'h1', 'warning', 'Brak nagłówka H1 na stronie głównej', 'Nagłówek H1 mówi Google, czego dotyczy strona.', 'Dodanie nagłówka H1 z główną frazą sklepu.', 2 );
		} elseif ( count( array_filter( $h1 ) ) === 0 && '' !== ( $logo = self::h1_image_alt( $html ) ) ) {
			$out[] = $this->f( 'h1', 'info', 'Nagłówek H1 na stronie głównej to tylko logo', 'Jedyny H1 na stronie głównej zawiera obrazek logo (opis: „' . self::cut( $logo, 60 ) . '”). Google traktuje to jak nazwę sklepu, a nie frazę, po której klienci szukają oferty.', 'Przeniesienie H1 na tekst z główną frazą sklepu (np. „Sklep z AGD do zabudowy”), a logo jako zwykły obrazek.', 1 );
		} elseif ( count( array_filter( $h1 ) ) === 0 ) {
			$out[] = $this->f( 'h1', 'warning', 'Pusty nagłówek H1 na stronie głównej', 'Na stronie jest znacznik H1, ale bez żadnego tekstu – Google nie dostaje informacji, czego dotyczy strona.', 'Wpisanie w H1 głównej frazy sklepu (np. „Sklep z akcesoriami rowerowymi”).', 2 );
		} elseif ( count( $h1 ) > 1 ) {
			$out[] = $this->f( 'h1', 'info', 'Kilka nagłówków H1 na stronie głównej (' . count( $h1 ) . ')', 'Lepiej, gdy strona ma jeden główny nagłówek H1, a pozostałe sekcje używają H2–H3.', 'Uporządkowanie nagłówków w szablonie.', 1 );
		}

		$canon = self::canonical( $html );
		if ( null === $canon ) {
			$out[] = $this->f( 'canonical', 'warning', 'Brak adresu kanonicznego (canonical)', 'Bez niego Google może indeksować te same treści pod różnymi adresami (np. z parametrami filtrów), co rozmywa pozycje.', 'Konfiguracja adresów kanonicznych w całym sklepie.', 2 );
		} else {
			$ch = strtolower( (string) parse_url( $canon, PHP_URL_HOST ) );
			if ( $ch && self::bare( $ch ) !== self::bare( $host ) ) {
				$out[] = $this->f( 'canonical', 'warning', 'Adres kanoniczny wskazuje inną domenę', 'Strona główna mówi Google, że jej właściwa wersja jest pod adresem ' . self::cut( $canon, 80 ) . '. To często pozostałość po starej domenie lub wersji testowej i może przenosić pozycje w niewłaściwe miejsce.', 'Poprawienie domeny w ustawieniach sklepu (Preferencje → Ruch / SEO i URL).', 4 );
			} elseif ( $https && 0 === stripos( $canon, 'http://' ) ) {
				$out[] = $this->f( 'canonical', 'warning', 'Adres kanoniczny prowadzi do wersji bez HTTPS', 'Sklep działa na https, ale canonical wskazuje http://. Google dostaje sprzeczne sygnały, którą wersję pokazywać.', 'Włączenie SSL na wszystkich stronach w ustawieniach PrestaShop.', 2 );
			}
		}

		// Sklep wielojęzyczny bez hreflang.
		$langs = array();
		if ( preg_match_all( '#href=["\'](?:https?://' . preg_quote( $host, '#' ) . ')?/([a-z]{2})/["\']#i', $html, $m ) ) {
			$langs = array_unique( array_map( 'strtolower', $m[1] ) );
		}
		if ( count( $langs ) >= 1 && ! preg_match( '/hreflang=/i', $html ) ) {
			$out[] = $this->f( 'hreflang', 'warning', 'Wersje językowe bez znaczników hreflang', 'Sklep ma wersje językowe (' . implode( ', ', array_slice( $langs, 0, 5 ) ) . '), ale nie mówi Google, która jest dla którego kraju. Klienci z zagranicy mogą trafiać na polską wersję i odwrotnie.', 'Wdrożenie znaczników hreflang dla wszystkich wersji językowych.', 2 );
		}
		return $out;
	}

	/** Mapa strony: czy istnieje, czy działa, ile adresów, kiedy aktualizowana. */
	private function sitemap( array $p, $host ) {
		$out    = array();
		$probed = false;
		$found  = null;
		$broken = array();
		foreach ( array( 'sitemap_robots', 'sitemap_ps', 'sitemap_std' ) as $k ) {
			if ( ! isset( $p[ $k ] ) ) {
				continue;
			}
			$probed = true;
			$r      = $p[ $k ];
			$ok     = 200 === (int) $r['status'] && preg_match( '/<(urlset|sitemapindex)\b/i', substr( (string) $r['body'], 0, 4000 ) );
			if ( $ok && ! $found ) {
				$found = $r;
			} elseif ( ! $ok && 'sitemap_robots' === $k ) {
				$broken[] = isset( $r['url'] ) ? $r['url'] : '';
			}
		}
		if ( ! $probed ) {
			return $out;
		}

		$robots_body = isset( $p['robots']['body'] ) && 200 === (int) $p['robots']['status'] ? $p['robots']['body'] : '';
		$listed      = self::robots_sitemaps( $robots_body );
		foreach ( $listed as $u ) {
			$uh = strtolower( (string) parse_url( $u, PHP_URL_HOST ) );
			if ( $uh && self::bare( $uh ) !== self::bare( $host ) ) {
				$out[] = $this->f( 'sitemap_host', 'warning', 'Robots.txt wskazuje mapę strony na innej domenie', 'W robots.txt jest adres ' . self::cut( $u, 80 ) . '. Google pobierze mapę z niewłaściwej domeny albo jej w ogóle nie przyjmie.', 'Poprawienie adresu mapy strony w robots.txt.', 2 );
				break;
			}
		}

		if ( ! $found ) {
			if ( $broken ) {
				$out[] = $this->f( 'sitemap', 'warning', 'Mapa strony z robots.txt nie działa', 'Robots.txt wskazuje mapę strony, ale pod tym adresem nie ma poprawnego pliku XML. Google nie dowie się z niej o nowych produktach.', 'Wygenerowanie mapy strony (moduł Google sitemap) i ustawienie jej automatycznej aktualizacji.', 3 );
			} else {
				$out[] = $this->f( 'sitemap', 'warning', 'Brak mapy strony (sitemap.xml)', 'Nie znaleźliśmy mapy strony ani w robots.txt, ani pod standardowymi adresami. Bez niej Google wolniej odkrywa nowe produkty i kategorie.', 'Włączenie modułu Google sitemap, automatyczna aktualizacja i zgłoszenie mapy w Search Console.', 3 );
			}
			return $out;
		}

		$body  = (string) $found['body'];
		$index = (bool) preg_match( '/<sitemapindex\b/i', substr( $body, 0, 4000 ) );
		$locs  = preg_match_all( '#<loc>#i', $body );
		$what  = $index ? 'indeks z ' . $locs . ' ' . self::plural( $locs, 'plikiem', 'plikami', 'plikami' ) : $locs . ' ' . self::plural( $locs, 'adres', 'adresy', 'adresów' );

		$newest = 0;
		if ( preg_match_all( '#<lastmod>\s*([0-9]{4}-[0-9]{2}-[0-9]{2})#i', $body, $m ) ) {
			foreach ( $m[1] as $d ) {
				$newest = max( $newest, (int) strtotime( $d . ' 00:00:00 UTC' ) );
			}
		}
		if ( ! $newest && ! empty( $found['headers']['last-modified'][0] ) ) {
			$newest = (int) strtotime( $found['headers']['last-modified'][0] );
		}
		$age = $newest ? (int) floor( ( time() - $newest ) / 86400 ) : null;

		if ( null !== $age && $age > 90 ) {
			$out[] = $this->f( 'sitemap_age', 'warning', 'Mapa strony nie była aktualizowana od ' . self::age( $age ), 'Mapa strony (' . $what . ') ma ostatnią zmianę z ' . gmdate( 'j.m.Y', $newest ) . '. Nowe produkty i kategorie mogą do niej nie trafiać. W PrestaShop mapa nie odświeża się sama – trzeba ustawić zadanie cron.', 'Ustawienie automatycznego generowania mapy strony (cron modułu Google sitemap).', 2 );
		} else {
			$out[] = $this->f( 'sitemap', 'ok', 'Mapa strony działa (' . $what . ')', 'Google może z niej odczytać listę stron sklepu.', null, 0 );
		}
		if ( ! $listed ) {
			$out[] = $this->f( 'sitemap_robots', 'info', 'Robots.txt nie wskazuje mapy strony', 'Mapa strony istnieje, ale nie ma do niej linku w robots.txt. Google znajdzie ją szybciej, gdy będzie tam wpisana.', 'Dodanie linii „Sitemap: …” do robots.txt.', 1 );
		}
		return $out;
	}

	/** Duplikaty: druga wersja domeny (www / bez www) i strony błędu zwracające 200. */
	private function duplicates( array $p, $host ) {
		$out = array();
		if ( isset( $p['althost'] ) && $p['althost']['status'] ) {
			$a   = $p['althost'];
			$loc = isset( $a['headers']['location'][0] ) ? strtolower( (string) parse_url( $a['headers']['location'][0], PHP_URL_HOST ) ) : '';
			$alt = isset( $a['url'] ) ? strtolower( (string) parse_url( $a['url'], PHP_URL_HOST ) ) : '';
			if ( $a['status'] >= 200 && $a['status'] < 300 && false !== stripos( (string) $a['body'], '<html' ) ) {
				$out[] = $this->f( 'host_dup', 'warning', 'Sklep działa pod dwoma adresami (' . $alt . ' i ' . $host . ')', 'Ta sama strona otwiera się z www i bez www, bez przekierowania. Google może traktować to jak dwa sklepy z tą samą treścią i dzielić między nie pozycje.', 'Stałe przekierowanie 301 na jedną wersję domeny.', 3 );
			} elseif ( in_array( (int) $a['status'], array( 302, 303, 307 ), true ) && $loc && self::bare( $loc ) === self::bare( $host ) ) {
				$out[] = $this->f( 'host_dup', 'info', 'Przekierowanie ' . $alt . ' jest tymczasowe (' . (int) $a['status'] . ')', 'Druga wersja domeny przekierowuje na właściwą, ale kodem tymczasowym. Google może dłużej trzymać obie wersje w indeksie.', 'Zmiana przekierowania na stałe (301).', 1 );
			}
		}
		if ( isset( $p['notfound'] ) && in_array( (int) $p['notfound']['status'], array( 301, 302, 303, 307, 308 ), true ) && ! empty( $p['notfound']['headers']['location'][0] ) ) {
			$loc  = $p['notfound']['headers']['location'][0];
			$lh   = strtolower( (string) parse_url( $loc, PHP_URL_HOST ) );
			$path = (string) parse_url( $loc, PHP_URL_PATH );
			if ( ( '' === $lh || self::bare( $lh ) === self::bare( $host ) ) && preg_match( '#^/?(?:[a-z]{2}/?)?(?:index\.php)?$#i', $path ) && ! parse_url( $loc, PHP_URL_QUERY ) ) {
				$out[] = $this->f( 'soft404', 'info', 'Nieistniejące strony przekierowują na stronę główną', 'Pod wymyślonym adresem sklep zamiast błędu 404 przekierowuje (' . (int) $p['notfound']['status'] . ') na stronę główną. Google traktuje to jak „miękki błąd 404”: usunięte produkty dłużej wiszą w indeksie, a klient z nieaktualnego linku nie wie, co się stało.', 'Zwracanie kodu 404 (lub 301 na najbliższy pasujący produkt czy kategorię) zamiast przekierowania wszystkiego na stronę główną.', 1 );
			}
		}
		if ( isset( $p['notfound'] ) && 200 === (int) $p['notfound']['status'] ) {
			$out[] = $this->f( 'soft404', 'warning', 'Nieistniejące strony zwracają kod 200', 'Pod wymyślonym adresem sklep odpowiada „wszystko w porządku” (200) zamiast „nie znaleziono” (404). Google może indeksować tysiące pustych stron, co obniża ocenę całego sklepu.', 'Poprawienie obsługi błędu 404 w szablonie lub konfiguracji serwera.', 2 );
		}
		return $out;
	}

	/** Zdjęcia na stronie głównej: opisy alt i nowoczesne formaty. */
	private function images( $html ) {
		$out = array();
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
			return $out;
		}
		$imgs = $m[0];
		$n    = count( $imgs );
		if ( $n < 5 ) {
			return $out;
		}
		$noalt = 0;
		foreach ( $imgs as $t ) {
			if ( ! preg_match( '/\balt=["\']\s*[^"\'\s][^"\']*["\']/i', $t ) ) {
				$noalt++;
			}
		}
		if ( $noalt / $n >= 0.3 ) {
			$out[] = $this->f( 'img_alt', 'warning', $noalt . ' z ' . $n . ' zdjęć na stronie głównej bez opisu (alt)', 'Opis alt mówi Google, co jest na zdjęciu – bez niego zdjęcia produktów nie pojawiają się w Grafice Google i tracą ruch.', 'Uzupełnienie opisów alt (w PrestaShop: legenda zdjęcia produktu).', 2 );
		}
		$modern = preg_match( '/\.(webp|avif)\b|type=["\']image\/(webp|avif)["\']/i', $html );
		if ( ! $modern && preg_match( '/\.(jpe?g|png)\b/i', $html ) ) {
			$out[] = $this->f( 'img_format', 'info', 'Zdjęcia w starszych formatach (JPG/PNG)', 'Formaty WebP i AVIF ważą zwykle o 25–50% mniej przy tej samej jakości. Lżejsze zdjęcia to szybszy sklep na telefonach i lepsza ocena w Google.', 'Włączenie generowania WebP/AVIF (PrestaShop 8+ ma to w ustawieniach zdjęć).', 1, 'speed', 'optymalizacja' );
		}
		return $out;
	}

	private function mixed_content( $html ) {
		if ( preg_match( '/<(?:img|script|iframe|source|video|audio)\b[^>]*\ssrc=["\']http:\/\//i', $html ) || preg_match( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*href=["\']http:\/\//i', $html ) ) {
			return array( $this->f( 'mixed', 'warning', 'Mieszana treść: zasoby ładowane bez HTTPS', 'Na bezpiecznej stronie część plików (zdjęcia, skrypty lub style) ładuje się przez http://. Przeglądarki je blokują albo pokazują ostrzeżenie zamiast kłódki.', 'Zamiana adresów zasobów na https:// w szablonie, modułach i treściach.', 3, 'security', 'bezpieczenstwo' ) );
		}
		return array();
	}

	/** Karta produktu: dane strukturalne Product/Offer/AggregateRating, okruszki, opisy. */
	private function product( array $pp, $home_html ) {
		$out = array();
		if ( empty( $pp['ok'] ) || 200 !== (int) $pp['status'] || '' === (string) $pp['body'] ) {
			return $out;
		}
		$html = (string) $pp['body'];
		$url  = isset( $pp['url'] ) ? $pp['url'] : '';
		$data = self::structured( $html );

		$where = 'Na sprawdzonej karcie produktu (' . self::cut( preg_replace( '#^https?://[^/]+#', '', $url ), 60 ) . ')';

		if ( ! $data['product'] ) {
			$out[] = $this->f( 'p_schema', 'warning', 'Karty produktów bez danych strukturalnych Product', $where . ' nie ma danych schema.org/Product. Bez nich Google nie pokaże w wynikach ceny, dostępności ani gwiazdek, a produkty trudniej trafiają do bezpłatnych wyników w zakładce Zakupy.', 'Wdrożenie danych strukturalnych Product z ofertą (cena, dostępność), marką i kodem EAN.', 4 );
		} else {
			$miss = array();
			if ( ! $data['price'] ) {
				$miss[] = 'cena';
			}
			if ( ! $data['availability'] ) {
				$miss[] = 'dostępność';
			}
			if ( ! $data['gtin'] && ! $data['sku'] ) {
				$miss[] = 'kod EAN/SKU';
			}
			if ( $miss ) {
				$sev   = in_array( 'cena', $miss, true ) || in_array( 'dostępność', $miss, true ) ? 'warning' : 'info';
				$out[] = $this->f( 'p_offer', $sev, 'Niepełne dane produktu dla Google (brak: ' . implode( ', ', $miss ) . ')', $where . ' dane Product nie zawierają: ' . implode( ', ', $miss ) . '. Google wymaga ceny i dostępności, żeby pokazać produkt z ceną w wynikach i w zakładce Zakupy.', 'Uzupełnienie danych strukturalnych w szablonie karty produktu.', 'warning' === $sev ? 3 : 1 );
			}
			if ( ! $data['rating'] ) {
				$out[] = $this->f( 'p_rating', 'info', 'Brak ocen produktów w danych dla Google', $where . ' nie ma oceny (AggregateRating). Gwiazdki przy wyniku wyszukiwania wyraźnie zwiększają liczbę kliknięć.', 'Moduł opinii produktów z danymi strukturalnymi (np. Product Comments lub zewnętrzny system opinii).', 1 );
			}
			if ( $data['price'] && $data['availability'] && $data['rating'] ) {
				$out[] = $this->f( 'p_schema', 'ok', 'Karta produktu ma pełne dane dla Google', 'Cena, dostępność i oceny są opisane w danych strukturalnych.', null, 0 );
			}
		}
		if ( ! $data['breadcrumbs'] ) {
			$out[] = $this->f( 'p_breadcrumbs', 'info', 'Brak okruszków (BreadcrumbList) w danych dla Google', $where . ' ścieżka kategorii nie jest opisana dla Google. Z nią wynik pokazuje „Sklep › Kategoria › Produkt” zamiast długiego adresu.', 'Dodanie danych BreadcrumbList w szablonie.', 1 );
		}

		if ( '' === self::meta_description( $html ) ) {
			$out[] = $this->f( 'p_description', 'warning', 'Karty produktów bez opisu meta description', $where . ' nie ma opisu meta. Google wstawi w wyniki przypadkowy fragment strony.', 'Uzupełnienie opisów meta produktów (można je generować z krótkiego opisu).', 2 );
		}
		if ( null === self::canonical( $html ) ) {
			$out[] = $this->f( 'p_canonical', 'warning', 'Karty produktów bez adresu kanonicznego', $where . ' nie ma znacznika canonical. Warianty produktu (kolory, rozmiary) mogą konkurować ze sobą w Google.', 'Włączenie adresów kanonicznych dla produktów i ich wariantów.', 2 );
		}
		$h1 = self::h1s( $html );
		if ( ! $h1 || '' === implode( '', $h1 ) ) {
			$out[] = $this->f( 'p_h1', 'warning', 'Karty produktów bez nagłówka H1', $where . ' nazwa produktu nie jest nagłówkiem H1, przez co Google słabiej rozumie, czego dotyczy strona.', 'Poprawka szablonu karty produktu.', 2 );
		} elseif ( count( array_unique( array_filter( $h1 ) ) ) > 1 ) {
			$out[] = $this->f( 'p_h1', 'info', 'Kilka różnych nagłówków H1 na karcie produktu (' . count( $h1 ) . ')', $where . ' oprócz nazwy produktu są inne nagłówki H1 (np. hasło sklepu z nagłówka lub stopki). Google dostaje mniej jednoznaczny sygnał, czego dotyczy strona.', 'Zostawienie jednego H1 z nazwą produktu, pozostałe nagłówki jako H2–H3.', 1 );
		}
		if ( $data['demo'] ) {
			$out[] = $this->f( 'p_demo', 'warning', 'Dane produktu z instalacji demonstracyjnej (kod „' . self::cut( $data['demo'], 20 ) . '”)', $where . ' dane dla Google podają kod produktu „' . self::cut( $data['demo'], 20 ) . '” – to indeks z przykładowych produktów PrestaShop. Zwykle znaczy to, że produkt powstał z edycji produktu demo i ma też inne pozostałości (kody, wagi, cechy), które trafiają do Google i porównywarek.', 'Przegląd produktów utworzonych z danych demo i uzupełnienie prawdziwych kodów (indeks, EAN).', 2 );
		}
		$pt = self::title( $html );
		if ( '' !== $pt && $pt === self::title( $home_html ) ) {
			$out[] = $this->f( 'p_title', 'warning', 'Karty produktów mają ten sam tytuł co strona główna', $where . ' tytuł strony to „' . self::cut( $pt, 60 ) . '”, taki sam jak na stronie głównej. Każdy produkt powinien mieć własny tytuł z nazwą.', 'Poprawka szablonu meta tytułów produktów.', 3 );
		}
		if ( ! preg_match( '/property=["\']og:image["\']/i', $html ) ) {
			$out[] = $this->f( 'p_og', 'info', 'Produkty udostępniane bez zdjęcia', $where . ' nie ma znacznika og:image – link do produktu wysłany na Messengerze czy Facebooku pokaże się bez zdjęcia.', 'Dodanie og:image ze zdjęciem produktu.', 1, 'seo', 'marketing' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/*  Dane strukturalne                                                   */
	/* ------------------------------------------------------------------ */

	public static function structured( $html ) {
		$r = array( 'product' => false, 'price' => false, 'availability' => false, 'rating' => false, 'gtin' => false, 'sku' => false, 'breadcrumbs' => false, 'demo' => '' );

		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$d = json_decode( html_entity_decode( trim( $json ), ENT_QUOTES, 'UTF-8' ), true );
				if ( null === $d ) {
					$d = json_decode( trim( $json ), true );
				}
				if ( is_array( $d ) ) {
					self::walk( $d, $r );
				}
			}
		}
		// Mikrodane (motywy PrestaShop 1.6/1.7 i część modułów).
		if ( preg_match( '#itemtype=["\']https?://schema\.org/Product["\']#i', $html ) ) {
			$r['product'] = true;
			if ( preg_match( '#itemprop=["\']price["\']#i', $html ) ) {
				$r['price'] = true;
			}
			if ( preg_match( '#itemprop=["\']availability["\']#i', $html ) ) {
				$r['availability'] = true;
			}
			if ( preg_match( '#itemprop=["\'](?:gtin13|gtin|gtin8|gtin14)["\']#i', $html ) ) {
				$r['gtin'] = true;
			}
			if ( preg_match( '#itemprop=["\']sku["\']#i', $html ) ) {
				$r['sku'] = true;
			}
			if ( ! $r['demo'] && preg_match( '#itemprop=["\']sku["\'][^>]*?(?:content=["\']|>\s*)(demo_\d+)\b#i', $html, $dm ) ) {
				$r['demo'] = $dm[1];
			}
			if ( preg_match( '#itemtype=["\']https?://schema\.org/AggregateRating["\']|itemprop=["\']aggregateRating["\']#i', $html ) ) {
				$r['rating'] = true;
			}
		}
		if ( preg_match( '#itemtype=["\']https?://schema\.org/BreadcrumbList["\']#i', $html ) ) {
			$r['breadcrumbs'] = true;
		}
		return $r;
	}

	private static function walk( $node, array &$r ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
		$type = array_map( 'strtolower', array_filter( $type, 'is_string' ) );
		if ( in_array( 'product', $type, true ) || in_array( 'productgroup', $type, true ) ) {
			$r['product'] = true;
			foreach ( array( 'gtin13', 'gtin', 'gtin8', 'gtin12', 'gtin14' ) as $g ) {
				if ( ! empty( $node[ $g ] ) ) {
					$r['gtin'] = true;
				}
			}
			if ( ! empty( $node['sku'] ) || ! empty( $node['mpn'] ) ) {
				$r['sku'] = true;
			}
			foreach ( array( 'sku', 'mpn' ) as $k ) {
				if ( ! $r['demo'] && isset( $node[ $k ] ) && is_scalar( $node[ $k ] ) && preg_match( '/^demo_\d+$/i', trim( (string) $node[ $k ] ) ) ) {
					$r['demo'] = trim( (string) $node[ $k ] );
				}
			}
			if ( ! empty( $node['aggregateRating'] ) ) {
				$r['rating'] = true;
			}
		}
		if ( in_array( 'offer', $type, true ) || in_array( 'aggregateoffer', $type, true ) ) {
			if ( isset( $node['price'] ) || isset( $node['lowPrice'] ) || ! empty( $node['priceSpecification'] ) ) {
				$r['price'] = true;
			}
			if ( ! empty( $node['availability'] ) ) {
				$r['availability'] = true;
			}
		}
		if ( in_array( 'aggregaterating', $type, true ) ) {
			$r['rating'] = true;
		}
		if ( in_array( 'breadcrumblist', $type, true ) ) {
			$r['breadcrumbs'] = true;
		}
		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) ) {
				self::walk( $v, $r );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Pomocnicze                                                          */
	/* ------------------------------------------------------------------ */

	public static function title( $html ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', (string) $html, $m ) ) {
			return trim( preg_replace( '/\s+/', ' ', html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
		}
		return '';
	}

	public static function meta_description( $html ) {
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)/i', (string) $html, $m ) || preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/i', (string) $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/** Teksty wszystkich nagłówków H1 (pusty string = pusty nagłówek). */
	public static function h1s( $html ) {
		$out = array();
		if ( preg_match_all( '/<h1\b[^>]*>(.*?)<\/h1>/is', (string) $html, $m ) ) {
			foreach ( $m[1] as $t ) {
				$out[] = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $t ), ENT_QUOTES, 'UTF-8' ) ), " \t\n\r\0\x0B\xC2\xA0" );
			}
		} elseif ( preg_match( '/<h1[\s>]/i', (string) $html ) ) {
			$out[] = 'x'; // H1 bez zamknięcia – uznajemy, że jest
		}
		return $out;
	}

	/** Opis alt obrazka w pierwszym H1 (np. logo w nagłówku starszych motywów). */
	public static function h1_image_alt( $html ) {
		if ( preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', (string) $html, $m ) && preg_match( '/<img\b[^>]*\balt=["\']([^"\']+)["\']/i', $m[1], $a ) ) {
			return trim( html_entity_decode( $a[1], ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	public static function canonical( $html ) {
		if ( preg_match( '/<link\b[^>]*rel=["\']canonical["\'][^>]*>/i', (string) $html, $m ) && preg_match( '/href=["\']([^"\']+)/i', $m[0], $h ) ) {
			return html_entity_decode( $h[1], ENT_QUOTES, 'UTF-8' );
		}
		return null;
	}

	public static function bare( $host ) {
		return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
	}

	public static function alt_host( $host ) {
		$host = strtolower( $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			return substr( $host, 4 );
		}
		// Tylko domeny główne (sklep.pl), nie subdomeny (sklep.firma.pl) – tam www zwykle nie istnieje.
		return 1 === substr_count( $host, '.' ) || preg_match( '/\.(com|net|org|edu|gov)\.[a-z]{2}$/', $host ) && 2 === substr_count( $host, '.' ) ? 'www.' . $host : null;
	}

	private static function cut( $s, $n ) {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > $n ) {
			return mb_substr( $s, 0, $n - 1 ) . '…';
		}
		return strlen( $s ) > $n ? substr( $s, 0, $n - 1 ) . '…' : $s;
	}

	private static function plural( $n, $one, $few, $many ) {
		$d = $n % 10;
		$t = $n % 100;
		if ( 1 === $n ) {
			return $one;
		}
		return $d >= 2 && $d <= 4 && ( $t < 12 || $t > 14 ) ? $few : $many;
	}

	private static function age( $days ) {
		if ( $days >= 730 ) {
			return floor( $days / 365 ) . ' lat';
		}
		if ( $days >= 365 ) {
			return 'ponad roku';
		}
		$mo = (int) floor( $days / 30 );
		return $mo . ' ' . self::plural( $mo, 'miesiąca', 'miesięcy', 'miesięcy' );
	}
}
