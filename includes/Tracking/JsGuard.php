<?php
/**
 * Static check of custom JavaScript before it becomes a draft and again before it is written. It is a brake, not a
 * sandbox: it refuses what is obviously risky or unreadable, and the owner still reads the code on the card.
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * The check returns '' (passes) or the reason, in plain words. Conservative on purpose: a harmless script that trips a rule is
 * refused with a reason the AI can fix; a harmful one must never pass.
 */
final class JsGuard {

	/** Largest script (bytes). */
	public const MAX_BYTES = 20480;

	/** Longest line (characters): longer lines are minified or packed code nobody can read on the card. */
	public const MAX_LINE = 300;

	/** Most lines. */
	public const MAX_LINES = 800;

	/**
	 * Refused patterns → reason.
	 */
	private const RULES = array(
		// Running text as code / hiding what the code does.
		'/\beval\b/'                                   => 'eval() runs text as code',
		'/\bFunction\s*\(/'                            => 'Function() / new Function runs text as code',
		'/\.\s*constructor\b/'                         => '.constructor can reach the Function constructor',
		'/\bimport\s*\(/'                              => 'import() loads code from elsewhere',
		'/\bimportScripts\b|\bWorker\s*\(|\bSharedWorker\b/' => 'workers load code from elsewhere',
		'/\bset(?:Timeout|Interval)\s*\(\s*[\'"`]/'    => 'setTimeout / setInterval with a text argument runs text as code',
		'/\batob\b|\bbtoa\b|\bfromCharCode\b|\bunescape\b|\bdecodeURIComponent\s*\(\s*[\'"`]%/' => 'encoded / obfuscated text (atob, fromCharCode, unescape…)',
		'/\\\\(?:u[0-9a-fA-F{]|x[0-9a-fA-F]{2}|[0-7]{2,3})/' => 'escaped characters (\\u, \\x, octal) hide what the code says — write plain text',
		'/\bwith\s*\(/'                                => 'with() statements',
		'/\b(?:window|self|globalThis|document|top|parent|frames)\s*\[/' => 'dynamic access to window / document properties',
		'/\[\s*[\'"`][^\'"`\]]*[\'"`]\s*\+/'           => 'property names built from pieces of text',
		'/\[\s*`/'                                     => 'property names built from template text',
		// Data of visitors.
		'/\bdocument\s*\.\s*cookie\b|\bcookieStore\b/' => 'reading or writing cookies (document.cookie)',
		'/\blocalStorage\b|\bsessionStorage\b|\bindexedDB\b/' => 'browser storage (localStorage / sessionStorage / indexedDB), where sign-in tokens live',
		'/\.\s*value\b|\bFormData\b|\bdocument\s*\.\s*forms\b|\.\s*elements\b|\bselectionStart\b|\bnavigator\s*\.\s*clipboard\b/' => 'reading form fields or what visitors type',
		'/\b(?:querySelector(?:All)?|getElementsByTagName|getElementsByName|closest)\s*\(\s*[\'"`][^\'"`]*\b(?:input|textarea|select|form)\b/i' => 'selecting form fields (input, textarea, select, form)',
		'/addEventListener\s*\(\s*[\'"`](?:input|change|keydown|keyup|keypress|submit|paste|copy|beforeinput)[\'"`]|\bon(?:input|change|keydown|keyup|keypress|submit|paste|copy)\s*=/' => 'listening to what visitors type or submit',
		'/\bnavigator\s*\.\s*credentials\b|\bPaymentRequest\b/' => 'credentials or payment APIs',
		// Leaving the page / rewriting it.
		'/\blocation\b/'                               => 'window.location (redirects, or reading the address)',
		'/\bwindow\s*\.\s*open\b|\bopener\b/'          => 'opening or controlling other windows',
		'/\bdocument\s*\.\s*(?:write|writeln)\b/'      => 'document.write',
		'/\bdocument\s*\.\s*domain\b/'                 => 'document.domain',
		'/\bpostMessage\b/'                            => 'postMessage (sends data to other windows)',
		'/<\s*\/?\s*(?:script|iframe|frame|object|embed|form|base|meta|link)\b/i' => 'HTML tags that load or run code (script, iframe, form, meta…)',
		'/\bon(?:abort|auxclick|before\w+|blur|cancel|canplay\w*|change|click|close|contextmenu|copy|cut|dblclick|drag\w*|drop|ended|error|focus\w*|hashchange|input|invalid|key\w+|load\w*|message|mouse\w+|paste|pause|play\w*|pointer\w+|popstate|progress|reset|resize|scroll\w*|search|select\w*|show|storage|submit|toggle|touch\w+|transition\w+|animation\w+|unload|wheel|begin|end)\s*=(?!=)/i'                              => 'inline event handlers (onclick=, onerror=, onload=…) — use addEventListener',
		'/<\s*\/?\s*[a-z]*[\'"`]\s*\+/i'                           => 'HTML tags built from pieces of text',
		'/\bhttp-equiv\b|\bsrcdoc\b/i'                 => 'meta refresh / srcdoc',
		'/javascript\s*:|vbscript\s*:|data\s*:\s*text\/html/i' => 'javascript: / data: addresses',
		'/\bcreateElement\s*\(\s*[\'"`](?:script|iframe|frame|object|embed|form|base|meta|link)[\'"`]/i' => 'creating script / iframe / form elements',
		'/\bXMLHttpRequest\b|\$\s*\.\s*(?:ajax|get|post|getJSON|getScript|load)\b|\bjQuery\s*\.\s*(?:ajax|get|post|getJSON|getScript)\b|\baxios\b|\.\s*load\s*\(\s*[\'"`]/' => 'XMLHttpRequest / jQuery ajax (use fetch() with a fixed address instead)',
		'/\bnew\s+Image\b/'                            => 'new Image() (a common way to send data out)',
	);

	/** Network calls whose first argument must be a fixed address. */
	private const NETWORK = '/\b(fetch|sendBeacon|WebSocket|EventSource)\s*\(/';

	/**
	 * Why the code may not be used ('' = it passes).
	 *
	 * @param mixed             $code    Code.
	 * @param array<int,string> $domains Other domains the owner allowed (wp-admin).
	 * @param string            $own     This site's host.
	 */
	public static function check( $code, array $domains, string $own ): string {
		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			return 'The script is empty.';
		}
		if ( strlen( $code ) > self::MAX_BYTES ) {
			return sprintf( 'The script is %1$d bytes; the most is %2$d (20 KB). Keep it short and readable.', strlen( $code ), self::MAX_BYTES );
		}
		if ( ! wp_check_invalid_utf8( $code ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $code ) ) {
			return 'The script has invalid or invisible control characters.';
		}
		if ( preg_match( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', $code ) ) {
			return 'The script has invisible Unicode characters (zero-width / direction marks).';
		}
		$lines = explode( "\n", str_replace( "\r\n", "\n", $code ) );
		if ( count( $lines ) > self::MAX_LINES ) {
			return sprintf( 'The script has more than %d lines.', self::MAX_LINES );
		}
		foreach ( $lines as $n => $line ) {
			if ( mb_strlen( $line ) > self::MAX_LINE ) {
				return sprintf( 'Line %1$d is longer than %2$d characters: minified or packed code is not accepted, the owner must be able to read every line.', $n + 1, self::MAX_LINE );
			}
			if ( substr_count( $line, ';' ) > 8 ) {
				return sprintf( 'Line %d holds many statements: write one statement per line (minified code is not accepted).', $n + 1 );
			}
		}
		foreach ( self::RULES as $pattern => $reason ) {
			if ( preg_match( $pattern, $code ) ) {
				return 'Not allowed: ' . $reason . '.';
			}
		}
		$allowed = array_merge( array( $own ), $domains );
		foreach ( self::hosts( $code ) as $host ) {
			if ( ! self::host_allowed( $host, $allowed ) ) {
				return sprintf( 'The script talks to %s, which is not in the allowed domains. The site owner can add it in WordPress → AddSynapse → Settings → «JavaScript added by the AI».', $host );
			}
		}
		if ( preg_match_all( self::NETWORK, $code, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $i => $hit ) {
				$rest = substr( $code, (int) $hit[1] + strlen( (string) $hit[0] ) );
				if ( ! preg_match( '/^\s*(?:\'[^\'\\\\\n+]*\'|"[^"\\\\\n+]*"|`[^`$\\\\\n]*`)\s*[,)]/', $rest ) ) {
					return sprintf( '%s() must be called with a fixed address written in the code (no variables or pieces of text).', $m[1][ $i ][0] );
				}
			}
		}
		if ( preg_match_all( '/\.\s*src\s*=(?!=)\s*([^;\n]*)|setAttribute\s*\(\s*[\'"`](?:src|href|action|formaction)[\'"`]\s*,\s*([^)\n]*)/', $code, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$value = trim( (string) ( '' !== ( $hit[1] ?? '' ) ? $hit[1] : ( $hit[2] ?? '' ) ) );
				if ( ! preg_match( '/^(?:\'[^\'\\\\\n+]*\'|"[^"\\\\\n+]*"|`[^`$\\\\\n]*`)$/', $value ) ) {
					return 'An address (src / href) must be fixed text in the code, not built from variables.';
				}
			}
		}
		return '';
	}

	/**
	 * Host names the code names (absolute and protocol-relative addresses).
	 *
	 * @param string $code Code.
	 * @return array<int,string>
	 */
	public static function hosts( string $code ): array {
		preg_match_all( '#(?:\b(?:https?|wss?):)?//([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+)#i', $code, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
	}

	/**
	 * Whether a host is this site or one of the allowed domains (or a sub-domain of one).
	 *
	 * @param string            $host    Host.
	 * @param array<int,string> $allowed Allowed.
	 */
	public static function host_allowed( string $host, array $allowed ): bool {
		$host = strtolower( $host );
		foreach ( $allowed as $domain ) {
			$domain = strtolower( (string) $domain );
			if ( '' === $domain ) {
				continue;
			}
			if ( $host === $domain || ( strlen( $host ) > strlen( $domain ) && substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Plain facts about the code for the card (written by the site, never by the AI).
	 *
	 * @param string $code Code (already checked).
	 * @param string $own  This site's host.
	 * @return array<int,string>
	 */
	public static function facts( string $code, string $own ): array {
		$facts   = array();
		$other   = array_values(
			array_filter(
				self::hosts( $code ),
				static function ( string $h ) use ( $own ): bool {
					return ! self::host_allowed( $h, array( $own ) );
				}
			)
		);
		$facts[] = $other ? 'Talks to: ' . implode( ', ', $other ) : 'Talks to no other website';
		if ( preg_match( self::NETWORK, $code ) ) {
			$facts[] = 'Sends or loads data over the network (fetch / beacon)';
		}
		if ( preg_match( '/\b(?:innerHTML|outerHTML|insertAdjacentHTML|appendChild|insertBefore|append|prepend|remove|replaceWith)\b/', $code ) ) {
			$facts[] = 'Changes what the page shows';
		}
		if ( preg_match( '/\.\s*style\b|classList/', $code ) ) {
			$facts[] = 'Changes styles / classes of page elements';
		}
		if ( preg_match( '/addEventListener|\bon(?:click|scroll|load|mouse\w*)\s*=/', $code ) ) {
			$facts[] = 'Reacts to clicks / scrolling / loading';
		}
		if ( preg_match( '/\bset(?:Timeout|Interval)\b/', $code ) ) {
			$facts[] = 'Uses timers';
		}
		$facts[] = sprintf( '%1$d lines, %2$d bytes', substr_count( $code, "\n" ) + 1, strlen( $code ) );
		return $facts;
	}
}
