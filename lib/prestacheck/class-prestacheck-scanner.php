<?php
/**
 * PrestaCheck – silnik skanera sklepów.
 *
 * Czysty PHP (bez zależności od WordPressa), żeby ten sam silnik dało się użyć
 * we wtyczce WP, w module PrestaShop i w skrypcie do raportu branżowego.
 *
 * Sprawdza wyłącznie to, co sklep pokazuje publicznie każdemu odwiedzającemu.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PRESTACHECK_STANDALONE' ) ) {
	exit;
}

require_once __DIR__ . '/class-prestacheck-seo.php';

class PrestaCheck_Exception extends Exception {
	public $reason;
	public function __construct( $message, $reason = 'error' ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}
}

class PrestaCheck_Scanner {

	const VERSION    = '1.3.0';
	const USER_AGENT = 'Mozilla/5.0 (compatible; SiteAudit/1.3)';

	/** Maksymalna liczba punktów w każdej kategorii (suma = 100). */
	const CATEGORIES = array(
		'security'  => array( 'label' => 'Bezpieczeństwo', 'max' => 30 ),
		'platform'  => array( 'label' => 'Wersja platformy', 'max' => 20 ),
		'speed'     => array( 'label' => 'Szybkość', 'max' => 20 ),
		'seo'       => array( 'label' => 'SEO', 'max' => 20 ),
		'marketing' => array( 'label' => 'Analityka i RODO', 'max' => 10 ),
	);

	/** Usługi, do których prowadzą znalezione problemy. */
	const SERVICES = array(
		'migracja'       => 'Migracja do PrestaShop 9',
		'bezpieczenstwo' => 'Pakiet bezpieczeństwa i opieka techniczna',
		'optymalizacja'  => 'Optymalizacja szybkości sklepu',
		'seo'            => 'SEO techniczne i pozycjonowanie',
		'marketing'      => 'Analityka GA4 i kampanie reklamowe',
	);

	private $kb;
	private $opts;
	private $pinned = array();
	private $insecure = false; // true tylko dla sklepu z błędnym certyfikatem (wtedy i tak zgłaszamy krytyczny błąd)

	public function __construct( array $knowledge, array $opts = array() ) {
		$this->kb   = $knowledge;
		$this->opts = array_merge(
			array(
				'allow_private' => false,   // tylko do testów lokalnych
				'timeout'       => 9,
				'max_body'      => 3145728, // 3 MB
				'psi_key'       => '',
			),
			$opts
		);
	}

	/* =====================================================================
	 *  GŁÓWNY SKAN
	 * ===================================================================== */

	public function scan( $input ) {
		$this->insecure = false;
		$target         = $this->normalize_url( $input );

		$result = array(
			'scanner_version' => self::VERSION,
			'input'           => $input,
			'host'            => $target['host'],
			'scanned_at'      => gmdate( 'c' ),
			'findings'        => array(),
			'meta'            => array(),
		);

		// Najpierw DNS i kontrola adresu – czytelny komunikat dla literówek w domenie.
		$this->resolve_safe( $target['host'] );

		// 1. Strona główna – najpierw HTTPS, potem awaryjnie HTTP.
		$https = $this->fetch_follow( 'https://' . $target['authority'] . '/' );
		$page  = $https;
		$uses_https = $https['ok'] && 0 === strpos( $https['url'], 'https://' );

		// Tylko błędy weryfikacji certyfikatu (nie np. brak TLS na porcie) oznaczają „zły certyfikat”.
		$cert_broken = ! $https['ok'] && in_array( $https['errno'], array( 51, 58, 60, 77, 83, 90, 91 ), true );
		if ( ! $https['ok'] ) {
			$http = $this->fetch_follow( 'http://' . $target['authority'] . '/' );
			if ( $http && $http['ok'] && ! ( $cert_broken && 0 === strpos( $http['url'], 'https://' ) ) ) {
				$page       = $http;
				$uses_https = 0 === strpos( $http['url'], 'https://' );
			} elseif ( $cert_broken ) {
				// Sklep działa tylko na HTTPS z błędnym certyfikatem – pobieramy stronę bez weryfikacji,
				// żeby mimo to przygotować raport (błąd certyfikatu i tak trafia do wyników jako krytyczny).
				$page       = $this->fetch_follow( 'https://' . $target['authority'] . '/', 5, true );
				$uses_https     = true;
				$this->insecure = true;
				if ( ! $page || ! $page['ok'] ) {
					throw new PrestaCheck_Exception( 'Nie udało się połączyć ze sklepem. Sprawdź adres i spróbuj ponownie.' );
				}
			} else {
				throw new PrestaCheck_Exception( 'Nie udało się połączyć ze sklepem. Sprawdź adres i spróbuj ponownie.' );
			}
		}

		if ( in_array( (int) $page['status'], array( 401, 403, 429, 503 ), true ) ) {
			throw new PrestaCheck_Exception( 'Sklep zablokował automatyczne sprawdzenie (zapora lub ochrona przed botami, kod ' . (int) $page['status'] . '). Napisz do nas – sprawdzimy go ręcznie i bezpłatnie.', 'blocked' );
		}
		if ( $page['status'] >= 400 ) {
			throw new PrestaCheck_Exception( 'Sklep odpowiedział błędem ' . (int) $page['status'] . '. Sprawdź adres i spróbuj ponownie.' );
		}

		$final = parse_url( $page['url'] );
		$base  = $final['scheme'] . '://' . $final['host'] . ( isset( $final['port'] ) ? ':' . $final['port'] : '' );

		// 2. Wykrycie PrestaShop.
		$ps = $this->detect_prestashop( $page['body'], $page['headers'] );

		// 3. Sondy pomocnicze (równolegle). /themes/core.js to plik rdzenia PrestaShop 1.7+,
		// zawsze publicznie dostępny – jego odcisk (sha1) wskazuje dokładne wydanie.
		$probes = array(
			'robots'    => $base . '/robots.txt',
			'corejs'    => array( $base . '/themes/core.js', 1048576 ),
			'changelog' => $base . '/docs/CHANGELOG.txt',
			'install'   => $base . '/install/',
			'git'       => $base . '/.git/HEAD',
			// SEO: mapa strony (domyślny adres PrestaShop i standardowy), test błędu 404, druga wersja domeny.
			'sitemap_ps'  => array( $base . '/1_index_sitemap.xml', 2097152 ),
			'sitemap_std' => array( $base . '/sitemap.xml', 2097152 ),
			'notfound'    => $base . '/prestacheck-' . substr( md5( (string) mt_rand() ), 0, 10 ) . '.html',
		);
		$alt = PrestaCheck_SEO::alt_host( $final['host'] );
		if ( $alt && ! isset( $final['port'] ) ) {
			$probes['althost'] = array( $final['scheme'] . '://' . $alt . '/', 262144 );
		}

		$vuln_db       = isset( $this->kb['vulnerable_modules'] ) ? $this->kb['vulnerable_modules'] : array();
		$risky_modules = array();
		foreach ( $ps['modules'] as $m ) {
			if ( isset( $vuln_db[ $m ] ) && '_info' !== $m ) {
				$risky_modules[]          = $m;
				$probes[ 'mod:' . $m ]    = $base . '/modules/' . rawurlencode( $m ) . '/config.xml';
				if ( count( $risky_modules ) >= 10 ) {
					break;
				}
			}
		}
		$p = $this->probe_many( $probes );
		$p = $this->seo_fetch( $p, $page, $base );

		$page['https_ssl_error'] = $cert_broken;
		return $this->analyze( $result, $target, $page, $uses_https, $p, $risky_modules, true );
	}

	/** Druga runda dla SEO: mapa strony z robots.txt i jedna karta produktu (ze strony głównej albo z mapy). */
	private function seo_fetch( array $p, array $page, $base ) {
		$robots = isset( $p['robots'] ) && 200 === $p['robots']['status'] ? $p['robots']['body'] : '';
		$listed = PrestaCheck_SEO::robots_sitemaps( $robots );
		foreach ( array( 'sitemap_ps', 'sitemap_std' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$p[ $k ]['url'] = 'sitemap_ps' === $k ? $base . '/1_index_sitemap.xml' : $base . '/sitemap.xml';
			}
		}
		if ( $listed ) {
			$u = $listed[0];
			foreach ( array( 'sitemap_ps', 'sitemap_std' ) as $k ) {
				if ( isset( $p[ $k ] ) && rtrim( $p[ $k ]['url'], '/' ) === rtrim( $u, '/' ) ) {
					$p['sitemap_robots'] = $p[ $k ];
				}
			}
			if ( ! isset( $p['sitemap_robots'] ) ) {
				$r                   = $this->request( $u, array( 'max_body' => 2097152, 'timeout' => 7 ) );
				$r['url']            = $u;
				$p['sitemap_robots'] = $r;
			}
		}

		$product = PrestaCheck_SEO::find_product_url( $page['body'], $base );
		$guess   = null;
		if ( ! $product ) {
			foreach ( array( 'sitemap_robots', 'sitemap_ps', 'sitemap_std' ) as $k ) {
				if ( empty( $p[ $k ] ) || 200 !== $p[ $k ]['status'] ) {
					continue;
				}
				$xml = (string) $p[ $k ]['body'];
				if ( preg_match( '/<sitemapindex\b/i', substr( $xml, 0, 4000 ) ) ) {
					$child = PrestaCheck_SEO::pick_child_sitemap( $xml );
					if ( $child ) {
						$c   = $this->request( $child, array( 'max_body' => 2097152, 'timeout' => 7 ) );
						$xml = 200 === $c['status'] ? (string) $c['body'] : '';
					}
				}
				$product = PrestaCheck_SEO::product_from_sitemap( $xml, $base );
				if ( $product ) {
					break;
				}
				if ( ! $guess ) {
					$guess = PrestaCheck_SEO::product_guess_from_sitemap( $xml, $base );
				}
			}
		}
		// Adresy bez numeru produktu: sprawdzamy kandydata i bierzemy go tylko, jeśli to naprawdę karta produktu.
		$url = $product ? $product : $guess;
		if ( $url ) {
			$pp = $this->fetch_follow( $url, 3 );
			if ( $pp && ( $product || ( 200 === (int) $pp['status'] && PrestaCheck_SEO::is_product_page( $pp['body'] ) ) ) ) {
				$pp['guess']  = ! $product;
				$p['product'] = $pp;
			}
		}
		return $p;
	}

	/**
	 * Analiza pobranych odpowiedzi (bez sieci poza testem przekierowania http→https).
	 * Osobno od pobierania – żeby dało się ją sprawdzać na zapisanych odpowiedziach prawdziwych sklepów.
	 */
	public function analyze( array $result, array $target, array $page, $uses_https, array $p, array $risky_modules = array(), $live = true ) {
		$html    = $page['body'];
		$headers = $page['headers'];
		$final   = parse_url( $page['url'] );

		$result['final_url']       = $page['url'];
		$result['meta']['base']    = $final['scheme'] . '://' . $final['host'] . ( isset( $final['port'] ) ? ':' . $final['port'] : '' );
		$result['meta']['ttfb']    = round( $page['ttfb'], 3 );
		$result['meta']['html_kb'] = isset( $page['size'] ) ? (int) round( $page['size'] / 1024 ) : (int) round( strlen( $html ) / 1024 );

		// Wykrycie PrestaShop, dodatkowe sygnały z sond i wersja.
		$ps = $this->refine_prestashop( $this->detect_prestashop( $html, $headers ), $p );
		$result['meta']['prestashop'] = $ps;
		if ( ! $ps['is'] ) {
			unset( $p['install'] ); // instalator sprawdzamy tylko w sklepach PrestaShop
		}

		$php = $this->php_version( $headers );
		$result['meta']['php'] = $php;

		$f = array();
		if ( $live ) {
			$f = array_merge( $f, $this->check_transport( $page, $uses_https, $target ) );
		}
		$f = array_merge( $f, $this->check_security_headers( $headers, $uses_https ) );
		$f = array_merge( $f, $this->check_exposed_files( $p, $ps ) );
		$f = array_merge( $f, $this->check_modules( $p, $risky_modules ) );
		$f = array_merge( $f, $this->check_platform( $ps, $ps['version'], $php ) );
		$f = array_merge( $f, $this->check_speed_basic( $page ) );
		$seo = new PrestaCheck_SEO( array( $this, 'make_finding' ) );
		$f   = array_merge( $f, $seo->analyze( $page, $p, $uses_https ) );
		$f   = array_merge( $f, $this->check_seo( $html, isset( $p['robots'] ) ? $p['robots'] : null ) );
		if ( ! empty( $p['product']['url'] ) && 200 === (int) $p['product']['status'] ) {
			$result['meta']['product_url'] = $p['product']['url'];
		}
		$f = array_merge( $f, $this->check_marketing( $html ) );

		$result['findings'] = $f;
		$result['psi_done'] = false;

		return self::score( $result );
	}

	/* =====================================================================
	 *  PAGESPEED (osobny krok – trwa 15–40 s)
	 * ===================================================================== */

	public function pagespeed( array $result ) {
		$url = isset( $result['final_url'] ) ? $result['final_url'] : '';
		$api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?strategy=mobile&category=performance&category=seo&locale=pl&url=' . rawurlencode( $url );
		if ( $this->opts['psi_key'] ) {
			$api .= '&key=' . rawurlencode( $this->opts['psi_key'] );
		}

		$ch = curl_init( $api );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 70,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_USERAGENT      => self::USER_AGENT,
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			)
		);
		$body   = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		self::close( $ch );

		return $this->apply_pagespeed( $result, $status, (string) $body );
	}

	/** Wpisuje odpowiedź API PageSpeed v5 do wyniku skanu (osobno, żeby dało się to testować). */
	public function apply_pagespeed( array $result, $status, $body ) {
		$data = $body ? json_decode( $body, true ) : null;
		$lh   = isset( $data['lighthouseResult'] ) ? $data['lighthouseResult'] : null;

		$result['findings'] = array_values(
			array_filter(
				$result['findings'],
				function ( $x ) {
					return ! in_array( $x['id'], array( 'psi', 'crux', 'lh_seo' ), true );
				}
			)
		);

		if ( 200 !== $status || ! $lh || ! isset( $lh['categories']['performance']['score'] ) ) {
			$result['psi_done']  = 'error';
			$result['findings'][] = $this->finding( 'psi', 'speed', 'info', 'Test Google PageSpeed niedostępny', 'Nie udało się w tej chwili pobrać wyniku Google PageSpeed. Pozostałe pomiary szybkości są w raporcie.', null, null, 0 );
			return self::score( $result );
		}

		$score  = (int) round( $lh['categories']['performance']['score'] * 100 );
		$a      = $lh['audits'];
		$metric = function ( $k ) use ( $a ) {
			return isset( $a[ $k ]['displayValue'] ) ? $a[ $k ]['displayValue'] : '–';
		};
		$lcp_ms = isset( $a['largest-contentful-paint']['numericValue'] ) ? $a['largest-contentful-paint']['numericValue'] : null;

		$result['meta']['psi'] = array(
			'score' => $score,
			'lcp'   => $metric( 'largest-contentful-paint' ),
			'cls'   => $metric( 'cumulative-layout-shift' ),
			'tbt'   => $metric( 'total-blocking-time' ),
			'si'    => $metric( 'speed-index' ),
		);

		$detail = sprintf(
			'Wynik Google PageSpeed na telefonie: %d/100. Największy element strony ładuje się w %s (LCP), przesunięcia układu: %s (CLS), blokowanie strony: %s (TBT).',
			$score,
			$metric( 'largest-contentful-paint' ),
			$metric( 'cumulative-layout-shift' ),
			$metric( 'total-blocking-time' )
		);

		if ( $score < 50 ) {
			$result['findings'][] = $this->finding( 'psi', 'speed', 'critical', 'Sklep wolno ładuje się na telefonach', $detail . ' Każda sekunda opóźnienia obniża konwersję, a Google gorzej ocenia wolne strony.', 'Optymalizacja obrazów (WebP/AVIF), cache, odchudzenie modułów i JS, lepszy serwer.', 'optymalizacja', 10 );
		} elseif ( $score < 90 ) {
			$result['findings'][] = $this->finding( 'psi', 'speed', 'warning', 'Szybkość mobilna do poprawy', $detail . ( $lcp_ms && $lcp_ms > 2500 ? ' LCP powyżej 2,5 s to próg, przy którym Google uznaje stronę za wolną.' : '' ), 'Optymalizacja obrazów, lazy loading, cache i ograniczenie zbędnych skryptów.', 'optymalizacja', 5 );
		} else {
			$result['findings'][] = $this->finding( 'psi', 'speed', 'ok', 'Bardzo dobra szybkość mobilna', $detail, null, null, 0 );
		}

		// Dane od prawdziwych użytkowników Chrome (CrUX) – najpierw dla adresu, potem dla całej domeny.
		$field = null;
		foreach ( array( 'loadingExperience', 'originLoadingExperience' ) as $k ) {
			if ( ! $field && ! empty( $data[ $k ]['overall_category'] ) && ! empty( $data[ $k ]['metrics'] ) ) {
				$field = $data[ $k ];
			}
		}
		if ( $field ) {
			$m    = $field['metrics'];
			$lcpf = isset( $m['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ) ? $m['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] / 1000 : null;
			$inp  = isset( $m['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ? (int) $m['INTERACTION_TO_NEXT_PAINT']['percentile'] : null;
			$clsf = isset( $m['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ) ? $m['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100 : null;
			$cat  = strtoupper( $field['overall_category'] );
			$det  = 'Pomiary od prawdziwych klientów sklepu (dane Chrome z ostatnich 28 dni): ' . implode( ', ', array_filter( array(
				null !== $lcpf ? 'LCP ' . number_format( $lcpf, 1, ',', '' ) . ' s' : null,
				null !== $inp ? 'INP ' . $inp . ' ms' : null,
				null !== $clsf ? 'CLS ' . number_format( $clsf, 2, ',', '' ) : null,
			) ) ) . '. Google bierze te dane pod uwagę w ocenie strony (Core Web Vitals).';
			$result['meta']['crux'] = array( 'category' => $cat, 'lcp' => $lcpf, 'inp' => $inp, 'cls' => $clsf );
			if ( 'SLOW' === $cat ) {
				$result['findings'][] = $this->finding( 'crux', 'speed', 'warning', 'Klienci realnie widzą wolny sklep (Core Web Vitals niezaliczone)', $det, 'Optymalizacja szybkości pod Core Web Vitals – to bezpośredni sygnał rankingowy Google.', 'optymalizacja', 5 );
			} elseif ( 'AVERAGE' === $cat ) {
				$result['findings'][] = $this->finding( 'crux', 'speed', 'info', 'Core Web Vitals do poprawy', $det, 'Optymalizacja wskaźnika, który odstaje (LCP, INP lub CLS).', 'optymalizacja', 2 );
			} else {
				$result['findings'][] = $this->finding( 'crux', 'speed', 'ok', 'Core Web Vitals zaliczone u prawdziwych użytkowników', $det, null, null, 0 );
			}
		}

		// Wynik SEO Lighthouse z listą niezaliczonych audytów (po polsku, locale=pl).
		if ( isset( $lh['categories']['seo']['score'] ) ) {
			$seo   = (int) round( $lh['categories']['seo']['score'] * 100 );
			$fails = array();
			foreach ( isset( $lh['categories']['seo']['auditRefs'] ) ? $lh['categories']['seo']['auditRefs'] : array() as $ref ) {
				$au = isset( $a[ $ref['id'] ] ) ? $a[ $ref['id'] ] : null;
				if ( $au && isset( $au['score'] ) && null !== $au['score'] && $au['score'] < 1 && ! empty( $ref['weight'] ) && ! empty( $au['title'] ) ) {
					$fails[] = $au['title'];
				}
			}
			$result['meta']['psi']['seo'] = $seo;
			if ( $seo < 90 && $fails ) {
				$result['findings'][] = $this->finding( 'lh_seo', 'seo', $seo < 70 ? 'warning' : 'info', 'Google Lighthouse: SEO ' . $seo . '/100', 'Test SEO Google na telefonie zgłasza: ' . implode( '; ', array_slice( $fails, 0, 4 ) ) . '.', 'Poprawki techniczne SEO wskazane przez Lighthouse.', 'seo', $seo < 70 ? 3 : 1 );
			}
		}

		$result['psi_done'] = true;
		return self::score( $result );
	}

	/* =====================================================================
	 *  WYKRYWANIE PRESTASHOP
	 * ===================================================================== */

	public function detect_prestashop( $html, array $headers ) {
		$signals = array();
		$score   = 0;
		$gen16   = 0;
		$gen17   = 0;

		$cookies = isset( $headers['set-cookie'] ) ? implode( "\n", $headers['set-cookie'] ) : '';
		if ( preg_match( '/PrestaShop-[a-f0-9]{8,}/i', $cookies ) ) {
			$score += 3; $signals[] = 'cookie PrestaShop';
		}
		foreach ( array( 'powered-by', 'x-powered-by' ) as $h ) {
			if ( isset( $headers[ $h ] ) && preg_match( '/prestashop/i', implode( ' ', $headers[ $h ] ) ) ) {
				$score += 3; $signals[] = 'nagłówek Powered-By';
			}
		}

		$generator = '';
		if ( preg_match( '/<meta[^>]+name=["\']generator["\'][^>]*content=["\']([^"\']+)/i', $html, $m ) || preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]*name=["\']generator["\']/i', $html, $m ) ) {
			$generator = $m[1];
		}
		$version = null;
		if ( false !== stripos( $generator, 'prestashop' ) ) {
			$score += 3; $signals[] = 'meta generator';
			if ( preg_match( '/PrestaShop\s*v?(\d+\.\d+(?:\.\d+){0,2})/i', $generator, $m ) ) {
				$version = $m[1];
			}
		}

		if ( preg_match( '/var\s+prestashop\s*=\s*\{|prestashop\s*=\s*\{"cart"/i', $html ) ) {
			$score += 3; $gen17 += 3; $signals[] = 'obiekt JS prestashop';
		}
		if ( preg_match( '/var\s+(static_token|baseDir|priceDisplayPrecision|roundMode)\s*=/', $html ) ) {
			$score += 2; $gen16 += 2; $signals[] = 'zmienne JS PrestaShop 1.6';
		}
		if ( false !== strpos( $html, '/themes/default-bootstrap/' ) ) {
			$score += 2; $gen16 += 3; $signals[] = 'motyw default-bootstrap (1.6)';
		}
		if ( preg_match( '#/js/jquery/jquery-1\.11\.0\.min\.js#', $html ) ) {
			$score += 1; $gen16 += 2; $signals[] = 'jQuery 1.11 z rdzenia 1.6';
		}
		if ( preg_match( '#/themes/[a-z0-9_\-]+/assets/(?:cache|css|js)/#i', $html ) ) {
			$score += 1; $gen17 += 1; $signals[] = 'struktura motywu 1.7+';
		}

		$modules = array();
		if ( preg_match_all( '#/modules/([a-z0-9_]{2,64})/#i', $html, $mm ) ) {
			$modules = array_values( array_unique( array_map( 'strtolower', $mm[1] ) ) );
		}
		if ( $modules ) {
			$score += 1; $signals[] = 'katalog /modules/';
			foreach ( $modules as $mod ) {
				if ( 0 === strpos( $mod, 'ps_' ) ) {
					$gen17 += 1;
					break;
				}
			}
			foreach ( array( 'blockcart', 'blocklayered', 'blocktopmenu', 'blockuserinfo' ) as $old ) {
				if ( in_array( $old, $modules, true ) ) {
					$gen16 += 1;
					break;
				}
			}
		}

		$theme = null;
		if ( preg_match( '#/themes/([a-z0-9_\-]+)/#i', $html, $m ) ) {
			$theme = $m[1];
		}

		$generation = null;
		if ( $gen16 > $gen17 ) {
			$generation = '1.6';
		} elseif ( $gen17 > 0 ) {
			$generation = '1.7+';
		}

		if ( $theme && 0 === stripos( $theme, 'hummingbird' ) ) {
			$generation = '9'; // Hummingbird – domyślny motyw PrestaShop 9
		}

		return array(
			'is'            => $score >= 3,
			'score'         => $score,
			'confidence'    => min( 100, $score * 12 ),
			'signals'       => $signals,
			'generation'    => $generation,
			'version'       => $version,
			'version_min'   => $version,
			'version_max'   => $version,
			'version_open'  => false,
			'version_source' => $version ? 'meta generator' : null,
			'theme'         => $theme,
			'modules'       => $modules,
		);
	}

	/**
	 * Uzupełnia wykrycie o sygnały z sond: robots.txt generowany przez PrestaShop,
	 * odcisk /themes/core.js (dokładne wydanie 1.7.0–8.2.x) i docs/CHANGELOG.txt.
	 */
	public function refine_prestashop( array $ps, array $p ) {
		$ok = function ( $key ) use ( $p ) {
			return isset( $p[ $key ] ) && 200 === $p[ $key ]['status'] && '' !== $p[ $key ]['body'] && false === stripos( substr( $p[ $key ]['body'], 0, 600 ), '<html' );
		};

		if ( $ok( 'robots' ) && false !== stripos( $p['robots']['body'], 'generated by PrestaShop' ) ) {
			$ps['score']    += 3;
			$ps['signals'][] = 'robots.txt PrestaShop';
		}

		if ( ! $ps['version'] && $ok( 'changelog' ) && preg_match( '/^#\s*v(\d+\.\d+\.\d+(?:\.\d+)?)\b/m', substr( $p['changelog']['body'], 0, 8000 ), $mm ) ) {
			$ps['score']         += 3;
			$ps['signals'][]      = 'docs/CHANGELOG.txt';
			$ps['version']        = $mm[1];
			$ps['version_min']    = $mm[1];
			$ps['version_max']    = $mm[1];
			$ps['version_source'] = 'docs/CHANGELOG.txt';
		}

		// /themes/core.js: odcisk sha1 (dokładne wydanie), wersja jQuery (generacja) i data pliku.
		$cj = isset( $p['corejs'] ) ? $p['corejs'] : null;
		if ( $cj && 200 === (int) $cj['status'] ) {
			$body    = isset( $cj['body'] ) ? (string) $cj['body'] : '';
			$size    = isset( $cj['size'] ) ? (int) $cj['size'] : strlen( $body );
			$is_html = '' !== $body && false !== stripos( substr( $body, 0, 600 ), '<html' );
			if ( $size > 20000 && ! $is_html ) {
				$sha   = isset( $cj['sha1'] ) ? $cj['sha1'] : sha1( $body );
				$jq    = isset( $cj['jq'] ) ? $cj['jq'] : self::jquery_version( $body );
				$db    = isset( $this->kb['prestashop']['corejs'] ) ? $this->kb['prestashop']['corejs'] : array();
				$range = null;
				if ( isset( $db[ $sha ] ) ) {
					$hit             = $db[ $sha ];
					$range           = array( $hit['min'], $hit['max'], ! empty( $hit['open'] ) );
					$ps['score']    += 6;
					$ps['signals'][] = 'odcisk themes/core.js';
				} else {
					$gen = $jq ? $this->generation_from_jquery( $jq ) : null;
					if ( $gen && ( $ps['score'] >= 1 || false !== stripos( $body, 'prestashop' ) ) ) {
						$range           = $gen;
						$ps['score']    += 3;
						$ps['signals'][] = 'themes/core.js (jQuery ' . $jq . ')';
					} elseif ( false !== stripos( $body, 'prestashop' ) ) {
						$ps['score']    += 2;
						$ps['signals'][] = 'themes/core.js';
					}
				}
				$ps['corejs'] = array( 'sha1' => $sha, 'size' => $size, 'jquery' => $jq, 'known' => isset( $db[ $sha ] ) );

				if ( $range ) {
					if ( null === $ps['generation'] || '1.6' === $ps['generation'] ) {
						$ps['generation'] = 0 === strpos( $range[0], '9.' ) ? '9' : '1.7+';
					}
					if ( ! $ps['version'] ) {
						list( $min, $max, $open ) = $range;
						$ps['version_min']    = $min;
						$ps['version_max']    = $max;
						$ps['version_open']   = $open;
						$ps['version_source'] = isset( $db[ $sha ] ) ? 'themes/core.js' : 'jQuery w themes/core.js';
						$ps['version']        = self::version_label( $min, $max, $open );
						if ( ! isset( $db[ $sha ] ) ) {
							$ps['version_generic'] = true; // tylko generacja – dokładne wydanie nieznane
							$ps['version']         = '9.0.0' === $min ? '9' : ( '1.7.7.0' === $min ? '1.7.7–8.2' : '1.7.0–1.7.6' );
						}

						// Data pliku: aktualizacja nadpisuje core.js, więc plik z dnia D oznacza wydanie nie nowsze niż ostatnie przed D.
						$lm = isset( $cj['headers']['last-modified'][0] ) ? strtotime( $cj['headers']['last-modified'][0] ) : false;
						if ( $lm && $lm < time() - 3 * 86400 ) {
							$est = $this->latest_release_before( $min, $open ? $this->branch_end( $max ) : $max, $lm );
							if ( $est && ( $open || $est !== $max ) ) {
								$ps['version_estimate'] = $est;
								$ps['corejs']['date']   = gmdate( 'Y-m-d', $lm );
							}
						}
					}
				}
			}
		}

		$ps['is']         = $ps['score'] >= 3;
		$ps['confidence'] = min( 100, $ps['score'] * 12 );
		return $ps;
	}

	/** Wersja jQuery z banera licencji albo z minifikowanego kodu (var x="3.5.1",y=function(e,t){return new y.fn.init(e,t)}). */
	public static function jquery_version( $js ) {
		if ( preg_match( '/jQuery JavaScript Library v(\d+\.\d+\.\d+)/', $js, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/var \w+="(\d\.\d+\.\d+)",(\w+)=function\(\w+,\w+\)\{return new \2\.fn\.init/', $js, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** Generacja PrestaShop po wersji jQuery w core.js (sprawdzone na oficjalnych paczkach). */
	private function generation_from_jquery( $jq ) {
		$ps = $this->kb['prestashop'];
		if ( version_compare( $jq, '3.6', '>=' ) ) {
			return array( '9.0.0', $ps['latest_9'], true );
		}
		if ( version_compare( $jq, '3.4', '>=' ) ) {
			return array( '1.7.7.0', $ps['latest_8'], false );
		}
		if ( version_compare( $jq, '2.0', '>=' ) ) {
			return array( '1.7.0.0', '1.7.6.9', false );
		}
		return null;
	}

	/** Ostatnie wydanie z gałęzi danej wersji (np. 8.2.3 → 8.2.8), według tabeli dat wydań. */
	private function branch_end( $version ) {
		$dates  = isset( $this->kb['prestashop']['release_dates'] ) ? $this->kb['prestashop']['release_dates'] : array();
		$prefix = preg_replace( '/^(1\.\d+|\d+)\.(\d+).*$/', '$1.$2', $version );
		$end    = $version;
		foreach ( array_keys( $dates ) as $v ) {
			if ( 0 === strpos( $v, $prefix . '.' ) && version_compare( $v, $end, '>' ) ) {
				$end = $v;
			}
		}
		return $end;
	}

	/** Najnowsze wydanie z zakresu [min, max], opublikowane najpóźniej kilka dni po dacie pliku. */
	private function latest_release_before( $min, $max, $ts ) {
		$dates = isset( $this->kb['prestashop']['release_dates'] ) ? $this->kb['prestashop']['release_dates'] : array();
		$best  = null;
		foreach ( $dates as $v => $d ) {
			if ( version_compare( $v, $min, '<' ) || version_compare( $v, $max, '>' ) ) {
				continue;
			}
			if ( strtotime( $d . ' 00:00:00 UTC' ) <= $ts + 4 * 86400 && ( null === $best || version_compare( $v, $best, '>' ) ) ) {
				$best = $v;
			}
		}
		return $best;
	}

	public static function version_label( $min, $max, $open ) {
		if ( $open ) {
			return $min . ' lub nowsza';
		}
		return $min === $max ? $min : $min . '–' . $max;
	}

	private function php_version( array $headers ) {
		$all = '';
		foreach ( array( 'x-powered-by', 'server' ) as $h ) {
			if ( isset( $headers[ $h ] ) ) {
				$all .= ' ' . implode( ' ', $headers[ $h ] );
			}
		}
		if ( preg_match( '#PHP/(\d+\.\d+(?:\.\d+)?)#i', $all, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/* =====================================================================
	 *  REGUŁY
	 * ===================================================================== */

	private function check_transport( array $page, $uses_https, array $target ) {
		$out = array();

		if ( ! empty( $page['https_ssl_error'] ) ) {
			$out[] = $this->finding( 'cert', 'security', 'critical', 'Certyfikat SSL jest nieprawidłowy', 'Wersja https:// sklepu zgłasza błąd certyfikatu, więc przeglądarki pokazują klientom ostrzeżenie zamiast sklepu. Większość osób w tym momencie rezygnuje z zakupu.', 'Wymiana certyfikatu i konfiguracja automatycznego odnawiania.', 'bezpieczenstwo', 12 );
			return $out;
		}

		if ( ! $uses_https ) {
			$out[] = $this->finding( 'https', 'security', 'critical', 'Sklep działa bez szyfrowania HTTPS', 'Dane logowania i zamówień klientów są przesyłane otwartym tekstem, a przeglądarki oznaczają stronę jako „niezabezpieczoną”. To odstrasza kupujących i obniża pozycję w Google.', 'Instalacja certyfikatu SSL i wymuszenie HTTPS na całym sklepie.', 'bezpieczenstwo', 12 );
			return $out;
		}

		if ( ! empty( $page['ssl_error'] ) ) {
			$out[] = $this->finding( 'cert', 'security', 'critical', 'Certyfikat SSL jest nieprawidłowy', 'Przeglądarki pokazują klientom ostrzeżenie zamiast sklepu. Większość osób w tym momencie rezygnuje z zakupu.', 'Wymiana certyfikatu i konfiguracja automatycznego odnawiania.', 'bezpieczenstwo', 12 );
		} elseif ( ! empty( $page['cert_expires'] ) ) {
			$days = (int) ceil( ( $page['cert_expires'] - time() ) / 86400 );
			$when = $days <= 1 ? 'w ciągu doby' : 'za ' . $days . ' dni';
			if ( $days < 14 ) {
				$out[] = $this->finding( 'cert', 'security', 'critical', 'Certyfikat SSL wygasa ' . $when, 'Po wygaśnięciu klienci zobaczą ostrzeżenie bezpieczeństwa zamiast sklepu.', 'Odnowienie certyfikatu i włączenie automatycznego odnawiania.', 'bezpieczenstwo', 8 );
			} elseif ( $days < 30 ) {
				$out[] = $this->finding( 'cert', 'security', 'warning', 'Certyfikat SSL wygasa ' . $when, 'Warto upewnić się, że certyfikat odnowi się automatycznie.', 'Sprawdzenie automatycznego odnawiania certyfikatu.', 'bezpieczenstwo', 3 );
			} else {
				$out[] = $this->finding( 'cert', 'security', 'ok', 'Certyfikat SSL ważny (' . $days . ' dni)', 'Połączenie jest szyfrowane, certyfikat jest ważny.', null, null, 0 );
			}
		} else {
			$out[] = $this->finding( 'cert', 'security', 'ok', 'Sklep działa na HTTPS', 'Połączenie jest szyfrowane.', null, null, 0 );
		}

		// Czy wersja http:// przekierowuje na https://?
		$r = $this->request( 'http://' . $target['authority'] . '/', array( 'max_body' => 8192 ) );
		if ( $r['ok'] ) {
			$loc = isset( $r['headers']['location'][0] ) ? $r['headers']['location'][0] : '';
			if ( in_array( $r['status'], array( 301, 302, 303, 307, 308 ), true ) && 0 === stripos( $loc, 'https://' ) ) {
				if ( 301 !== $r['status'] && 308 !== $r['status'] ) {
					$out[] = $this->finding( 'redirect', 'security', 'warning', 'Przekierowanie na HTTPS jest tymczasowe (' . $r['status'] . ')', 'Wersja http:// przekierowuje na https://, ale kodem tymczasowym. Google może traktować obie wersje jako osobne strony.', 'Zmiana przekierowania na stałe (301).', 'seo', 2 );
				}
			} elseif ( $r['status'] >= 200 && $r['status'] < 300 ) {
				$out[] = $this->finding( 'redirect', 'security', 'warning', 'Sklep otwiera się też bez HTTPS', 'Wejście na http:// nie przekierowuje na bezpieczną wersję. Część klientów (np. z starych linków) korzysta ze sklepu bez szyfrowania.', 'Wymuszenie przekierowania 301 z http:// na https://.', 'bezpieczenstwo', 3 );
			}
		}

		return $out;
	}

	private function check_security_headers( array $headers, $uses_https ) {
		$missing = array();
		if ( $uses_https && ! isset( $headers['strict-transport-security'] ) ) {
			$missing[] = 'Strict-Transport-Security';
		}
		if ( ! isset( $headers['x-content-type-options'] ) ) {
			$missing[] = 'X-Content-Type-Options';
		}
		$csp = isset( $headers['content-security-policy'] ) ? implode( ' ', $headers['content-security-policy'] ) : '';
		if ( ! isset( $headers['x-frame-options'] ) && false === stripos( $csp, 'frame-ancestors' ) ) {
			$missing[] = 'X-Frame-Options';
		}
		if ( ! isset( $headers['referrer-policy'] ) ) {
			$missing[] = 'Referrer-Policy';
		}

		$n = count( $missing );
		if ( 0 === $n ) {
			return array( $this->finding( 'headers', 'security', 'ok', 'Nagłówki bezpieczeństwa ustawione', 'Serwer wysyła podstawowe nagłówki chroniące przed popularnymi atakami.', null, null, 0 ) );
		}
		return array(
			$this->finding(
				'headers',
				'security',
				$n >= 2 ? 'warning' : 'info',
				'Brakujące nagłówki bezpieczeństwa (' . $n . ')',
				'Serwer nie wysyła nagłówków: ' . implode( ', ', $missing ) . '. Chronią one m.in. przed osadzaniem sklepu na obcych stronach (clickjacking) i przed przechwyceniem połączenia.',
				'Dodanie nagłówków w konfiguracji serwera – kilkanaście minut pracy.',
				'bezpieczenstwo',
				min( 4, $n )
			),
		);
	}

	private function check_exposed_files( array $p, array $ps ) {
		$exposed = array();

		if ( isset( $p['git'] ) && 200 === $p['git']['status'] && 0 === strpos( ltrim( $p['git']['body'] ), 'ref:' ) ) {
			$exposed[] = '/.git/ (repozytorium kodu)';
		}
		if ( isset( $p['install'] ) && 200 === $p['install']['status'] ) {
			$head = substr( $p['install']['body'], 0, 30000 );
			if ( false !== stripos( $head, 'prestashop' ) && preg_match( '/installation assistant|assistant d.installation|asystent instalacji|install_version|id=["\']install(?:er)?["\']|\/install\/theme\//i', $head ) ) {
				$exposed[] = '/install/ (instalator PrestaShop)';
			}
		}

		if ( ! $exposed ) {
			return array( $this->finding( 'files', 'security', 'ok', 'Brak publicznie dostępnych plików technicznych', 'Nie znaleźliśmy pozostawionego instalatora ani repozytorium kodu.', null, null, 0 ) );
		}

		$f = $this->finding(
			'files',
			'security',
			'critical',
			'Publicznie dostępne pliki techniczne',
			'Na serwerze są pliki, które nie powinny być dostępne z internetu. Mogą ułatwić przejęcie sklepu lub wyciek kodu i haseł.',
			'Natychmiastowe usunięcie lub zablokowanie dostępu i przegląd bezpieczeństwa.',
			'bezpieczenstwo',
			8
		);
		$f['sensitive'] = true;
		$f['internal']  = 'Dostępne: ' . implode( ', ', $exposed );
		return array( $f );
	}

	private function check_modules( array $p, array $risky ) {
		if ( ! $risky ) {
			return array();
		}
		$db       = $this->kb['vulnerable_modules'];
		$critical = array();
		$unknown  = array();

		foreach ( $risky as $m ) {
			$info    = $db[ $m ];
			$version = null;
			$key     = 'mod:' . $m;
			if ( isset( $p[ $key ] ) && 200 === $p[ $key ]['status'] && preg_match( '#<version>\s*(?:<!\[CDATA\[)?\s*([\d.]+)#i', $p[ $key ]['body'], $mm ) ) {
				$version = $mm[1];
			}
			$label = $m . ( $version ? ' ' . $version : '' ) . ' – ' . $info['cve'] . ' (' . $info['type'] . ', CVSS ' . $info['cvss'] . ')';

			if ( $version ) {
				$vuln = false;
				if ( isset( $info['fixed'] ) ) {
					$vuln = version_compare( $version, $info['fixed'], '<' );
				} elseif ( isset( $info['max_affected'] ) ) {
					$vuln = version_compare( $version, $info['max_affected'], '<=' );
				}
				if ( $vuln ) {
					$critical[] = $label;
				}
			} else {
				$unknown[] = $label;
			}
		}

		$out = array();
		if ( $critical ) {
			$f = $this->finding( 'modules', 'security', 'critical', 'Moduł ze znaną, publicznie opisaną luką', 'W sklepie działa moduł w wersji, dla której opublikowano opis ataku. Takie luki są masowo wykorzystywane przez automatyczne boty, m.in. do kradzieży danych kart i klientów.', 'Aktualizacja lub wymiana modułu, przegląd sklepu pod kątem śladów włamania.', 'bezpieczenstwo', 8 );
			$f['sensitive'] = true;
			$f['internal']  = implode( '; ', $critical );
			$out[]          = $f;
		} elseif ( $unknown ) {
			$f = $this->finding( 'modules', 'security', 'warning', 'Moduł z historią krytycznych luk', 'W sklepie działa moduł, w którym w przeszłości wykryto poważne luki. Nie udało się ustalić jego wersji z zewnątrz – trzeba sprawdzić, czy jest zaktualizowany.', 'Weryfikacja wersji modułów i aktualizacja.', 'bezpieczenstwo', 4 );
			$f['sensitive'] = true;
			$f['internal']  = implode( '; ', $unknown );
			$out[]          = $f;
		}
		return $out;
	}

	private function check_platform( array $ps, $version, $php ) {
		$out = array();

		if ( ! $ps['is'] ) {
			$out[] = $this->finding( 'platform', 'platform', 'info', 'Nie wykryto PrestaShop', 'Sklep prawdopodobnie działa na innej platformie (lub ukrywa ją bardzo skutecznie). Pozostałe testy wykonaliśmy normalnie.', null, null, 0 );
			$out[0]['excluded'] = true;
			return $out;
		}

		$latest9 = $this->kb['prestashop']['latest_9'];

		$vmin = ! empty( $ps['version_min'] ) ? $ps['version_min'] : $version;
		$vmax = ! empty( $ps['version_max'] ) ? $ps['version_max'] : $version;
		$open = ! empty( $ps['version_open'] );

		if ( $version && ! empty( $ps['version_generic'] ) && '9' === $ps['generation'] && empty( $ps['version_estimate'] ) ) {
			$out[] = $this->finding( 'ps_version', 'platform', 'info', 'PrestaShop 9 – dokładna wersja nieznana', 'Sklep działa na PrestaShop 9, ale z zewnątrz nie widać, które to wydanie. Wersje starsze niż 9.1.1 mają krytyczną lukę XSS (CVE-2026-44212), a aktualna wersja to ' . $latest9 . '.', 'Sprawdzenie w panelu, czy sklep ma najnowszą wersję ' . $latest9 . '.', 'bezpieczenstwo', 2 );
		} elseif ( $version && $vmin && $vmax ) {
			// Odcisk core.js może wskazać zakres wydań – oceniamy najnowsze możliwe (bez fałszywych alarmów).
			$f = $this->version_finding( 'PrestaShop ' . $version, $vmax, $open, $latest9 );

			// Data plików rdzenia zawęża zakres. To mocna wskazówka, ale nie pewność – krytyczny alarm,
			// który wynika tylko z daty, pokazujemy jako „do sprawdzenia”.
			if ( ! empty( $ps['version_estimate'] ) ) {
				$est   = $ps['version_estimate'];
				$date  = isset( $ps['corejs']['date'] ) ? date( 'j.m.Y', strtotime( $ps['corejs']['date'] ) ) : '';
				$generic = ! empty( $ps['version_generic'] ); // zmieniony core.js – data daje tylko górną granicę
				$label   = 'PrestaShop ' . $est . ( $generic ? ' lub starsza' : '' ) . ' (wg daty plików)';
				$g       = $this->version_finding( $label, $est, false, $latest9 );
				$g['detail'] = 'Pliki rdzenia sklepu pochodzą z ' . $date . ' r., ' . ( $generic ? 'więc sklep ma wersję nie nowszą niż ' : 'co odpowiada wydaniu ' ) . $est . '. ' . $g['detail'];
				if ( 'critical' === $g['sev'] && 'critical' !== $f['sev'] ) {
					$g['sev']    = 'warning';
					$g['points'] = max( $f['points'], 7 );
					$g['title']  = str_replace( ' – ', ' – prawdopodobnie ', $g['title'] );
					$g['detail'] .= ' Jeśli sklep był aktualizowany po tej dacie, warto to potwierdzić w panelu.';
				}
				$f = $g;
			}
			$out[] = $f;
		} elseif ( '9' === $ps['generation'] ) {
			$out[] = $this->finding( 'ps_version', 'platform', 'info', 'Prawdopodobnie PrestaShop 9', 'Sklep korzysta z motywu Hummingbird, domyślnego w PrestaShop 9. Dokładnej wersji nie widać z zewnątrz – wersje starsze niż 9.1.1 mają krytyczną lukę XSS (CVE-2026-44212).', 'Sprawdzenie w panelu, czy sklep ma najnowszą wersję ' . $latest9 . '.', 'bezpieczenstwo', 2 );
		} elseif ( '1.6' === $ps['generation'] ) {
			$out[] = $this->finding( 'ps_version', 'platform', 'critical', 'PrestaShop 1.6 (lub starszy) – wersja bez wsparcia', 'Budowa sklepu wskazuje na PrestaShop 1.6, który nie dostaje poprawek bezpieczeństwa od czerwca 2019 r. Takie sklepy są głównym celem automatycznych ataków.', 'Migracja do PrestaShop ' . $latest9 . ' z przeniesieniem produktów, klientów, zamówień i SEO.', 'migracja', 14 );
		} else {
			$hint = '';
			if ( $php && version_compare( $php, '8.1', '<' ) ) {
				$hint = ' Serwer działa na PHP ' . $php . ', na którym PrestaShop 9 nie działa – to oznacza wersję 1.7 lub 8.x.';
			}
			$out[] = $this->finding( 'ps_version', 'platform', 'warning', 'Nie ustaliliśmy dokładnej wersji PrestaShop', 'To dobrze, że wersja nie jest publicznie widoczna. Sklep działa na gałęzi 1.7 lub nowszej – jeśli to 1.7 lub 8.0/8.1, nie dostaje już poprawek bezpieczeństwa.' . $hint, 'Sprawdzenie wersji w panelu (Parametry zaawansowane → Informacje) i plan aktualizacji.', 'migracja', $hint ? 8 : 5 );
		}

		// PHP
		if ( $php ) {
			$rule = $this->match_rule( $this->kb['php'], $php );
			$st   = $rule ? $rule['status'] : 'ok';
			if ( 'eol' === $st ) {
				$out[] = $this->finding( 'php', 'platform', 'critical', 'PHP ' . $php . ' – wersja bez wsparcia', $rule['note'] . ' Dodatkowo serwer publicznie ujawnia wersję PHP, co ułatwia dobór ataku.', 'Aktualizacja PHP (zwykle razem z aktualizacją PrestaShop) i ukrycie nagłówka X-Powered-By.', 'bezpieczenstwo', 6 );
			} elseif ( 'ending' === $st ) {
				$out[] = $this->finding( 'php', 'platform', 'warning', 'PHP ' . $php . ' – wsparcie kończy się wkrótce', $rule['note'], 'Zaplanowanie aktualizacji PHP do 8.3 lub nowszej.', 'bezpieczenstwo', 3 );
			} else {
				$out[] = $this->finding( 'php', 'platform', 'info', 'Serwer ujawnia wersję PHP (' . $php . ')', 'Wersja jest wspierana, ale jej publiczne pokazywanie ułatwia atakującym dobór exploitów.', 'Ukrycie nagłówka X-Powered-By w konfiguracji serwera.', 'bezpieczenstwo', 1 );
			}
		} else {
			$out[] = $this->finding( 'php', 'platform', 'ok', 'Wersja PHP nie jest ujawniana', 'Serwer nie pokazuje publicznie wersji PHP – to dobra praktyka.', null, null, 0 );
		}

		return $out;
	}

	/** Ocena jednej wersji (najnowszej możliwej) według gałęzi z bazy wiedzy. */
	private function version_finding( $title, $vmax, $open, $latest9 ) {
		$branch = $this->match_rule( $this->kb['prestashop']['branches'], $vmax );
		$status = $branch ? $branch['status'] : 'unknown';
		$note   = $branch ? $branch['note'] : '';
		$below  = function ( $limit ) use ( $vmax, $open ) {
			return ! $open && version_compare( $vmax, $limit, '<' );
		};

		switch ( $status ) {
			case 'eol':
				return $this->finding( 'ps_version', 'platform', 'critical', $title . ' – wersja bez wsparcia', $note . ' Każda nowo odkryta luka w tej wersji zostaje otwarta na zawsze.', 'Migracja do PrestaShop ' . $latest9 . ' z przeniesieniem produktów, klientów, zamówień i SEO.', 'migracja', 14 );
			case 'outdated':
				return $this->finding( 'ps_version', 'platform', 'critical', $title . ' – brak aktualnych poprawek', $note, 'Aktualizacja do PrestaShop ' . $latest9 . '.', 'bezpieczenstwo', 10 );
			case 'extended':
			case 'previous':
				$next = 'extended' === $status ? 'migracji do PrestaShop ' . $latest9 : 'aktualizacji do PrestaShop ' . $latest9;
				if ( isset( $branch['min_safe'] ) && $below( $branch['min_safe'] ) ) {
					return $this->finding( 'ps_version', 'platform', 'critical', $title . ' – brak krytycznej poprawki', $note, 'Pilna aktualizacja do ' . $branch['latest'] . ', a następnie plan ' . $next . '.', 'bezpieczenstwo', 10 );
				} elseif ( isset( $branch['recommended'] ) && $below( $branch['recommended'] ) ) {
					return $this->finding( 'ps_version', 'platform', 'warning', $title . ' – brak najnowszych poprawek bezpieczeństwa', $note, 'Aktualizacja do ' . $branch['latest'] . ' i plan ' . $next . '.', 'bezpieczenstwo', 7 );
				} else {
					$check = $open && isset( $branch['recommended'] ) ? ' Z zewnątrz nie da się rozróżnić ostatnich łatek – warto sprawdzić w panelu, czy sklep ma co najmniej ' . $branch['recommended'] . '.' : '';
					if ( 'extended' === $status ) {
						return $this->finding( 'ps_version', 'platform', 'warning', $title . ' – wersja w trybie wygaszania', $note . ' Nowe funkcje i moduły powstają już tylko dla PrestaShop 9.' . $check, 'Zaplanowanie migracji do PrestaShop ' . $latest9 . ' bez pośpiechu i przestojów.', 'migracja', 5 );
					} else {
						return $this->finding( 'ps_version', 'platform', 'info', $title . ' – dostępna nowa wersja ' . $latest9, $note . $check, 'Aktualizacja do PrestaShop ' . $latest9 . ' (m.in. One Page Checkout).', 'migracja', 2 );
					}
				}
			case 'current':
				if ( isset( $branch['latest'] ) && $below( $branch['latest'] ) ) {
					$crit  = isset( $branch['min_safe'] ) && $below( $branch['min_safe'] );
					return $this->finding( 'ps_version', 'platform', $crit ? 'critical' : 'warning', $title . ' – dostępna aktualizacja ' . $branch['latest'], $crit ? 'Ta wersja ma krytyczną lukę XSS (CVE-2026-44212), poprawioną w 9.1.1.' : 'Nowsze wydanie zawiera poprawki błędów i bezpieczeństwa.', 'Aktualizacja do ' . $branch['latest'] . '.', 'bezpieczenstwo', $crit ? 10 : 3 );
				} else {
					return $this->finding( 'ps_version', 'platform', 'ok', $title . ' – aktualna wersja', 'Sklep działa na najnowszej gałęzi PrestaShop.', null, null, 0 );
				}
			default:
				return $this->finding( 'ps_version', 'platform', 'warning', $title, 'Nie rozpoznaliśmy statusu wsparcia tej wersji.', 'Weryfikacja wersji i planu aktualizacji.', 'migracja', 4 );
		}
	}

	private function check_speed_basic( array $page ) {
		$out  = array();
		$ttfb = $page['ttfb'];

		if ( $ttfb > 1.5 ) {
			$out[] = $this->finding( 'ttfb', 'speed', 'critical', sprintf( 'Serwer odpowiada wolno (%.1f s)', $ttfb ), 'Zanim przeglądarka zacznie cokolwiek rysować, czeka ' . number_format( $ttfb, 1, ',', '' ) . ' s na odpowiedź serwera. Dobry wynik to poniżej 0,8 s.', 'Włączenie cache, optymalizacja bazy danych i modułów, ewentualnie zmiana serwera.', 'optymalizacja', 5 );
		} elseif ( $ttfb > 0.8 ) {
			$out[] = $this->finding( 'ttfb', 'speed', 'warning', sprintf( 'Czas odpowiedzi serwera do poprawy (%.1f s)', $ttfb ), 'Dobry wynik to poniżej 0,8 s. Wolny serwer spowalnia każdą podstronę sklepu.', 'Cache, optymalizacja bazy danych i modułów.', 'optymalizacja', 3 );
		} else {
			$out[] = $this->finding( 'ttfb', 'speed', 'ok', sprintf( 'Szybka odpowiedź serwera (%.2f s)', $ttfb ), 'Serwer odpowiada szybko.', null, null, 0 );
		}

		$enc = isset( $page['headers']['content-encoding'] ) ? strtolower( implode( ',', $page['headers']['content-encoding'] ) ) : '';
		if ( ! preg_match( '/gzip|br|zstd|deflate/', $enc ) ) {
			$out[] = $this->finding( 'compression', 'speed', 'warning', 'Brak kompresji strony', 'Serwer wysyła stronę bez kompresji gzip/Brotli, przez co klienci pobierają kilka razy więcej danych.', 'Włączenie kompresji w konfiguracji serwera.', 'optymalizacja', 3 );
		}

		$kb = ( isset( $page['size'] ) ? $page['size'] : strlen( $page['body'] ) ) / 1024;
		if ( $kb > 500 ) {
			$out[] = $this->finding( 'html_size', 'speed', 'warning', sprintf( 'Bardzo ciężki kod strony głównej (%d KB)', $kb ), 'Sam kod HTML strony głównej waży ponad 500 KB – zwykle przez osadzone skrypty, style lub zbyt wiele produktów naraz.', 'Odchudzenie szablonu i modułów strony głównej.', 'optymalizacja', 2 );
		}

		return $out;
	}

	/** Podstawy strony głównej, których nie obejmuje moduł SEO. */
	private function check_seo( $html, $robots ) {
		$out = array();

		if ( ! preg_match( '/<meta[^>]+name=["\']viewport["\']/i', $html ) ) {
			$out[] = $this->finding( 'viewport', 'seo', 'critical', 'Strona nie jest przystosowana do telefonów', 'Brak znacznika viewport – na telefonie sklep wyświetla się jak pomniejszona wersja komputerowa. Większość ruchu w e-commerce jest mobilna.', 'Wdrożenie responsywnego szablonu.', 'migracja', 3 );
		}

		if ( ! preg_match( '/application\/ld\+json|itemtype=["\']https?:\/\/schema\.org/i', $html ) ) {
			$out[] = $this->finding( 'schema', 'seo', 'warning', 'Brak danych strukturalnych na stronie głównej', 'Dane strukturalne (schema.org) opisują Google sklep, jego logo i wyszukiwarkę. Pomagają też zbudować rozbudowany wynik z nazwą firmy.', 'Wdrożenie danych Organization i WebSite na stronie głównej.', 'seo', 2 );
		}

		if ( ! $robots || 200 !== $robots['status'] || false !== stripos( $robots['body'], '<html' ) ) {
			$out[] = $this->finding( 'robots', 'seo', 'warning', 'Brak pliku robots.txt', 'Robots.txt wskazuje robotom, co indeksować, a czego nie (np. koszyka i filtrów).', 'Wygenerowanie robots.txt i mapy strony.', 'seo', 2 );
		}

		if ( ! preg_match( '/<html[^>]+lang=["\'][a-z]/i', $html ) ) {
			$out[] = $this->finding( 'lang', 'seo', 'info', 'Brak deklaracji języka strony', 'Atrybut lang pomaga wyszukiwarkom i czytnikom ekranu.', 'Dodanie atrybutu lang w szablonie.', 'seo', 1 );
		}

		if ( ! preg_match( '/property=["\']og:(title|image)["\']/i', $html ) ) {
			$out[] = $this->finding( 'og', 'seo', 'info', 'Brak znaczników Open Graph', 'Linki do sklepu udostępniane na Facebooku czy Messengerze wyświetlają się bez obrazka i z przypadkowym opisem.', 'Dodanie znaczników Open Graph.', 'marketing', 1 );
		}

		return $out;
	}

	private function check_marketing( $html ) {
		$out = array();

		$ga = preg_match( '/googletagmanager\.com\/(gtag\/js\?id=G-|gtm\.js)|GTM-[A-Z0-9]{4,}|["\']G-[A-Z0-9]{6,}["\']|\/modules\/[a-z0-9_]*(?:gtm|tagmanager|tagmenager|googleanalytics|ganalytics|ga4)[a-z0-9_]*\//i', $html );
		if ( ! $ga ) {
			$out[] = $this->finding( 'ga4', 'marketing', 'warning', 'Nie wykryto Google Analytics 4', 'Bez analityki nie wiadomo, skąd przychodzą klienci, które kampanie zarabiają i gdzie ludzie porzucają koszyk.', 'Wdrożenie GA4 z pełnym śledzeniem e-commerce.', 'marketing', 4 );
		} else {
			$out[] = $this->finding( 'ga4', 'marketing', 'ok', 'Google Analytics / Tag Manager wdrożony', 'Sklep mierzy ruch.', null, null, 0 );
		}

		$consent_tools = 'clickio|didomi|cookiefirst|cookie-script|cookiescript|quantcast|sourcepoint|seigicookie|pressidium|cookie-consent|cookie_consent|cookie-notice|cookiebot|cookieyes|iubenda|onetrust|cookiehub|complianz|usercentrics|termly|osano|trustarc|axeptio|klaro|tarteaucitron|cookieconsent|cookie-consent|lgcookieslaw|cookiesplus|deluxecookies|pdgdprpro|gdprpro|psgdpr_cookie|consentmanager|cookie-law|cookielaw|cookie_notice|cookienotice|consentmode|gtag\(\s*["\']consent["\']';
		$consent       = preg_match( '/' . $consent_tools . '/i', $html );
		if ( ! $consent ) {
			$out[] = $this->finding( 'consent', 'marketing', 'warning', 'Nie wykryto zgody na cookies (Consent Mode)', 'Nie znaleźliśmy znanego systemu zgód cookies ani Google Consent Mode v2. Bez niego sklep ryzykuje problemy z RODO, a kampanie Google Ads tracą dane o konwersjach.', 'Wdrożenie banera zgód zgodnego z Consent Mode v2.', 'marketing', 4 );
		}

		if ( ! preg_match( '/connect\.facebook\.net\/[^"\']*fbevents\.js|fbq\(\s*["\']init/i', $html ) ) {
			$out[] = $this->finding( 'pixel', 'marketing', 'info', 'Nie wykryto Meta Pixel', 'Bez piksela Meta nie da się skutecznie prowadzić remarketingu na Facebooku i Instagramie.', 'Wdrożenie Meta Pixel i Conversions API.', 'marketing', 1 );
		}

		return $out;
	}

	/* =====================================================================
	 *  OCENA I WIDOK PUBLICZNY
	 * ===================================================================== */

	public static function score( array $result ) {
		$ded       = array_fill_keys( array_keys( self::CATEGORIES ), 0 );
		$excluded  = array();
		$counts    = array( 'critical' => 0, 'warning' => 0, 'info' => 0, 'ok' => 0 );

		foreach ( $result['findings'] as $f ) {
			$ded[ $f['cat'] ] += $f['points'];
			if ( isset( $counts[ $f['sev'] ] ) ) {
				$counts[ $f['sev'] ]++;
			}
			if ( ! empty( $f['excluded'] ) ) {
				$excluded[ $f['cat'] ] = true;
			}
		}

		$cats   = array();
		$earned = 0;
		$max    = 0;
		foreach ( self::CATEGORIES as $key => $c ) {
			if ( isset( $excluded[ $key ] ) ) {
				continue;
			}
			$cmax = $c['max'];
			if ( 'speed' === $key && true !== ( isset( $result['psi_done'] ) ? $result['psi_done'] : false ) ) {
				$cmax = 10; // bez PageSpeed liczymy tylko pomiary serwera
			}
			$e             = max( 0, $cmax - min( $cmax, $ded[ $key ] ) );
			$cats[ $key ]  = array( 'label' => $c['label'], 'score' => (int) round( $e / $cmax * 100 ) );
			$earned       += $e;
			$max          += $cmax;
		}

		$total = $max ? (int) round( $earned / $max * 100 ) : 0;
		if ( $counts['critical'] > 0 ) {
			$total = min( $total, 69 ); // krytyczny problem = nigdy „dobra” ocena
		}

		$result['score']      = $total;
		$result['grade']      = $total >= 85 ? 'good' : ( $total >= 60 ? 'fair' : 'poor' );
		$result['categories'] = $cats;
		$result['counts']     = $counts;

		$services = array();
		foreach ( $result['findings'] as $f ) {
			if ( $f['service'] && in_array( $f['sev'], array( 'critical', 'warning' ), true ) ) {
				$services[ $f['service'] ] = ( isset( $services[ $f['service'] ] ) ? $services[ $f['service'] ] : 0 ) + $f['points'];
			}
		}
		arsort( $services );
		$result['services'] = array();
		foreach ( $services as $k => $pts ) {
			$result['services'][] = array( 'key' => $k, 'label' => self::SERVICES[ $k ], 'weight' => $pts );
		}

		return $result;
	}

	/**
	 * Wersja wyniku bezpieczna do pokazania w przeglądarce.
	 *  - pola „internal” (np. ścieżki odsłoniętych plików) nigdy nie wychodzą na zewnątrz,
	 *  - przed podaniem e-maila szczegóły widać tylko dla 3 najpoważniejszych problemów.
	 */
	public static function public_view( array $result, $unlocked = false, $free_details = 3 ) {
		$issues = array();
		$passed = array();
		foreach ( $result['findings'] as $f ) {
			unset( $f['internal'] );
			if ( ! empty( $f['sensitive'] ) ) {
				$f['fix'] = $f['fix'] . ' Szczegóły przekażemy właścicielowi sklepu po weryfikacji.';
			}
			if ( in_array( $f['sev'], array( 'critical', 'warning' ), true ) ) {
				$issues[] = $f;
			} elseif ( 'info' === $f['sev'] && $f['points'] > 0 ) {
				$issues[] = $f;
			} elseif ( 'ok' === $f['sev'] ) {
				$passed[] = $f; // informacje bez punktów (np. „PageSpeed niedostępny”) nie są wynikiem „w porządku”
			}
		}
		$rank = array( 'critical' => 0, 'warning' => 1, 'info' => 2 );
		usort(
			$issues,
			function ( $a, $b ) use ( $rank ) {
				return array( $rank[ $a['sev'] ], -$a['points'] ) <=> array( $rank[ $b['sev'] ], -$b['points'] );
			}
		);

		if ( ! $unlocked ) {
			foreach ( $issues as $i => &$f ) {
				if ( $i >= $free_details ) {
					$f['detail'] = null;
					$f['fix']    = null;
					$f['locked'] = true;
				}
			}
			unset( $f );
		}

		$meta = $result['meta'];
		$ps   = isset( $meta['prestashop'] ) ? $meta['prestashop'] : array();

		return array(
			'host'       => $result['host'],
			'final_url'  => $result['final_url'],
			'scanned_at' => $result['scanned_at'],
			'score'      => $result['score'],
			'grade'      => $result['grade'],
			'categories' => $result['categories'],
			'counts'     => $result['counts'],
			'services'   => $result['services'],
			'psi_done'   => $result['psi_done'],
			'psi'        => isset( $meta['psi'] ) ? $meta['psi'] : null,
			'crux'       => isset( $meta['crux'] ) ? $meta['crux'] : null,
			'product_url' => isset( $meta['product_url'] ) ? $meta['product_url'] : null,
			'prestashop' => array(
				'is'         => ! empty( $ps['is'] ),
				'version'    => isset( $ps['version'] ) ? $ps['version'] : null,
				'generation' => isset( $ps['generation'] ) ? $ps['generation'] : null,
			),
			'issues'     => $issues,
			'passed'     => $passed,
			'unlocked'   => (bool) $unlocked,
		);
	}

	/* =====================================================================
	 *  HTTP – z ochroną przed SSRF (blokada adresów wewnętrznych)
	 * ===================================================================== */

	public function normalize_url( $input ) {
		$input = trim( (string) $input );
		if ( '' === $input || strlen( $input ) > 300 ) {
			throw new PrestaCheck_Exception( 'Podaj adres sklepu, np. mojsklep.pl' );
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $input ) ) {
			$input = 'https://' . $input;
		}
		$u = parse_url( $input );
		if ( ! $u || empty( $u['host'] ) || ! in_array( strtolower( $u['scheme'] ), array( 'http', 'https' ), true ) || isset( $u['user'] ) || isset( $u['pass'] ) ) {
			throw new PrestaCheck_Exception( 'To nie wygląda na poprawny adres sklepu.' );
		}
		$host = strtolower( rtrim( $u['host'], '.' ) );
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x20-\x7e]/', $host ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( $ascii ) {
				$host = $ascii;
			}
		}
		if ( ! $this->opts['allow_private'] ) {
			if ( ! preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
				throw new PrestaCheck_Exception( 'Podaj domenę sklepu, np. mojsklep.pl' );
			}
			if ( isset( $u['port'] ) && ! in_array( (int) $u['port'], array( 80, 443 ), true ) ) {
				throw new PrestaCheck_Exception( 'Skanujemy tylko sklepy na standardowych portach (80/443).' );
			}
		}
		$authority = $host . ( isset( $u['port'] ) && ! in_array( (int) $u['port'], array( 80, 443 ), true ) ? ':' . (int) $u['port'] : '' );
		return array( 'host' => $host, 'authority' => $authority );
	}

	private function resolve_safe( $host ) {
		if ( isset( $this->pinned[ $host ] ) ) {
			return $this->pinned[ $host ];
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = @gethostbynamel( $host );
			if ( ! $ips ) {
				throw new PrestaCheck_Exception( 'Nie znaleźliśmy takiej domeny. Sprawdź, czy adres jest poprawny.' );
			}
		}
		foreach ( $ips as $ip ) {
			if ( ! $this->opts['allow_private'] && ! $this->is_public_ip( $ip ) ) {
				throw new PrestaCheck_Exception( 'Ten adres nie jest publicznym sklepem internetowym.' );
			}
		}
		$this->pinned[ $host ] = $ips[0];
		return $ips[0];
	}

	private function is_public_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$long = ip2long( $ip );
		$deny = array( array( '100.64.0.0', 10 ), array( '192.0.0.0', 24 ), array( '198.18.0.0', 15 ), array( '0.0.0.0', 8 ), array( '127.0.0.0', 8 ), array( '169.254.0.0', 16 ) );
		foreach ( $deny as $d ) {
			$mask = -1 << ( 32 - $d[1] );
			if ( ( $long & $mask ) === ( ip2long( $d[0] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	private function make_handle( $url, array $o, &$buffer, &$hdrs ) {
		$u    = parse_url( $url );
		$host = strtolower( $u['host'] );
		$port = isset( $u['port'] ) ? (int) $u['port'] : ( 'https' === $u['scheme'] ? 443 : 80 );
		$ip   = $this->resolve_safe( $host );

		$buffer = '';
		$hdrs   = array();
		$max      = isset( $o['max_body'] ) ? $o['max_body'] : $this->opts['max_body'];
		$insecure = ! empty( $o['insecure'] ) || $this->insecure;

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RESOLVE        => array( $host . ':' . $port . ':' . $ip ),
				CURLOPT_PROXY          => '',
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_TIMEOUT        => isset( $o['timeout'] ) ? $o['timeout'] : $this->opts['timeout'],
				CURLOPT_CONNECTTIMEOUT => 6,
				CURLOPT_USERAGENT      => self::USER_AGENT,
				CURLOPT_ENCODING       => '',
				CURLOPT_SSL_VERIFYPEER => ! $insecure,
				CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
				CURLOPT_CERTINFO       => ! empty( $o['certinfo'] ),
				CURLOPT_HTTPHEADER     => array( 'Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: pl-PL,pl;q=0.9,en;q=0.5' ),
				CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$hdrs ) {
					if ( preg_match( '#^HTTP/\S+\s+\d+#', $line ) ) {
						$hdrs = array(); // nowy blok nagłówków (np. po 100 Continue)
					} elseif ( false !== strpos( $line, ':' ) ) {
						list( $k, $v )            = explode( ':', $line, 2 );
						$hdrs[ strtolower( trim( $k ) ) ][] = trim( $v );
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$buffer, $max ) {
					$buffer .= $chunk;
					return strlen( $buffer ) > $max ? 0 : strlen( $chunk );
				},
			)
		);
		return $ch;
	}

	private function collect( $ch, $buffer, $hdrs, $errno ) {
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$r      = array(
			'ok'           => $status > 0,
			'status'       => $status,
			'headers'      => $hdrs,
			'body'         => $buffer,
			'url'          => curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL ),
			'ttfb'         => (float) curl_getinfo( $ch, CURLINFO_STARTTRANSFER_TIME ),
			'ssl_error'    => in_array( $errno, array( 35, 51, 58, 60, 77, 83, 90, 91 ), true ),
			'errno'        => $errno,
			'cert_expires' => null,
		);
		$ci = @curl_getinfo( $ch, CURLINFO_CERTINFO );
		if ( is_array( $ci ) && isset( $ci[0] ) ) {
			foreach ( $ci[0] as $k => $v ) {
				if ( 'expire date' === strtolower( $k ) ) {
					$ts = strtotime( $v );
					if ( $ts ) {
						$r['cert_expires'] = $ts;
					}
				}
			}
		}
		return $r;
	}

	public function request( $url, array $o = array() ) {
		try {
			$ch = $this->make_handle( $url, $o, $buffer, $hdrs );
		} catch ( PrestaCheck_Exception $e ) {
			return array( 'ok' => false, 'status' => 0, 'headers' => array(), 'body' => '', 'url' => $url, 'ttfb' => 0, 'ssl_error' => false, 'errno' => -1, 'cert_expires' => null, 'error' => $e->getMessage() );
		}
		curl_exec( $ch );
		$errno = curl_errno( $ch );
		if ( 23 === $errno ) {
			$errno = 0; // przerwane celowo po przekroczeniu limitu rozmiaru
		}
		$r = $this->collect( $ch, $buffer, $hdrs, $errno );
		self::close( $ch );
		return $r;
	}

	/** Pobiera stronę, ręcznie podążając za przekierowaniami (każdy krok sprawdzany pod kątem SSRF). */
	public function fetch_follow( $url, $max = 5, $insecure = false ) {
		$last = null;
		for ( $i = 0; $i <= $max; $i++ ) {
			$is_https = 0 === strpos( $url, 'https://' );
			$r        = $this->request( $url, array( 'certinfo' => $is_https && ! $insecure, 'insecure' => $insecure ) );
			if ( ! empty( $r['error'] ) && $i > 0 ) {
				return $last; // przekierowanie w zakazane miejsce – zatrzymujemy się
			}
			$r['url'] = $url;
			if ( $r['ok'] && $r['status'] >= 300 && $r['status'] < 400 && ! empty( $r['headers']['location'][0] ) ) {
				$last = $r;
				$url  = $this->absolute( $url, $r['headers']['location'][0] );
				if ( ! preg_match( '#^https?://#i', $url ) ) {
					return $r;
				}
				continue;
			}
			return $r;
		}
		return $last;
	}

	/** Równoległe, lekkie zapytania pomocnicze (bez przekierowań). */
	public function probe_many( array $urls ) {
		$mh      = curl_multi_init();
		$handles = array();
		$bufs    = array();
		$hdrs    = array();

		foreach ( $urls as $key => $url ) {
			$bufs[ $key ] = '';
			$hdrs[ $key ] = array();
			$max          = 65536;
			if ( is_array( $url ) ) {
				list( $url, $max ) = $url;
			}
			try {
				$ch = $this->make_handle( $url, array( 'max_body' => $max, 'timeout' => 7 ), $bufs[ $key ], $hdrs[ $key ] );
			} catch ( PrestaCheck_Exception $e ) {
				continue;
			}
			$handles[ $key ] = $ch;
			curl_multi_add_handle( $mh, $ch );
		}

		do {
			$st = curl_multi_exec( $mh, $running );
			if ( $running ) {
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $running && CURLM_OK === $st );

		$errs = array();
		while ( $info = curl_multi_info_read( $mh ) ) {
			foreach ( $handles as $key => $ch ) {
				if ( $ch === $info['handle'] ) {
					$errs[ $key ] = $info['result'];
				}
			}
		}

		$out = array();
		foreach ( $handles as $key => $ch ) {
			$e           = isset( $errs[ $key ] ) ? $errs[ $key ] : 0;
			$out[ $key ] = $this->collect( $ch, $bufs[ $key ], $hdrs[ $key ], 23 === $e ? 0 : $e );
			curl_multi_remove_handle( $mh, $ch );
			self::close( $ch );
		}
		curl_multi_close( $mh );
		return $out;
	}

	/* =====================================================================
	 *  POMOCNICZE
	 * ===================================================================== */

	private static function close( $ch ) {
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $ch );
		}
	}

	public function make_finding( $id, $cat, $sev, $title, $detail, $fix, $service, $points ) {
		return $this->finding( $id, $cat, $sev, $title, $detail, $fix, $service, $points );
	}

	private function finding( $id, $cat, $sev, $title, $detail, $fix, $service, $points ) {
		return array(
			'id'      => $id,
			'cat'     => $cat,
			'sev'     => $sev,
			'title'   => $title,
			'detail'  => $detail,
			'fix'     => $fix,
			'service' => $service,
			'points'  => (int) $points,
		);
	}

	private function match_rule( array $rules, $version ) {
		foreach ( $rules as $r ) {
			if ( preg_match( '/' . $r['match'] . '/', $version ) ) {
				return $r;
			}
		}
		return null;
	}

	private function title_of( $html ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
			return trim( preg_replace( '/\s+/', ' ', html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
		}
		return '';
	}

	private function cut( $s, $n ) {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > $n ) {
			return mb_substr( $s, 0, $n - 1 ) . '…';
		}
		return strlen( $s ) > $n ? substr( $s, 0, $n - 1 ) . '…' : $s;
	}

	private function absolute( $base, $loc ) {
		if ( preg_match( '#^https?://#i', $loc ) ) {
			return $loc;
		}
		$b = parse_url( $base );
		$origin = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $loc, '//' ) ) {
			return $b['scheme'] . ':' . $loc;
		}
		if ( 0 === strpos( $loc, '/' ) ) {
			return $origin . $loc;
		}
		$dir = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		return $origin . $dir . $loc;
	}
}
