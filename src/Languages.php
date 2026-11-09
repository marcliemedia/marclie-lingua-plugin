<?php
/**
 * Languages the admin can pick (filter `marclie_lingua/language_presets` adds or changes entries:
 * code => [ locale, bcp47, name, label ]).
 */

namespace Marclie\Lingua;

defined( 'ABSPATH' ) || exit;

final class Languages {

	/** code => [ WordPress locale, BCP 47, name in the language, label ] */
	public const PRESETS = array(
		'bg' => array( 'bg_BG', 'bg-BG', 'Български', 'BG' ),
		'bs' => array( 'bs_BA', 'bs-BA', 'Bosanski', 'BS' ),
		'cs' => array( 'cs_CZ', 'cs-CZ', 'Čeština', 'CS' ),
		'da' => array( 'da_DK', 'da-DK', 'Dansk', 'DA' ),
		'de' => array( 'de_DE', 'de-DE', 'Deutsch', 'DE' ),
		'el' => array( 'el', 'el-GR', 'Ελληνικά', 'EL' ),
		'en' => array( 'en_GB', 'en-GB', 'English', 'EN' ),
		'es' => array( 'es_ES', 'es-ES', 'Español', 'ES' ),
		'fi' => array( 'fi', 'fi-FI', 'Suomi', 'FI' ),
		'fr' => array( 'fr_FR', 'fr-FR', 'Français', 'FR' ),
		'hr' => array( 'hr', 'hr-HR', 'Hrvatski', 'HR' ),
		'hu' => array( 'hu_HU', 'hu-HU', 'Magyar', 'HU' ),
		'it' => array( 'it_IT', 'it-IT', 'Italiano', 'IT' ),
		'ja' => array( 'ja', 'ja-JP', '日本語', 'JA' ),
		'nb' => array( 'nb_NO', 'nb-NO', 'Norsk bokmål', 'NO' ),
		'nl' => array( 'nl_NL', 'nl-NL', 'Nederlands', 'NL' ),
		'pl' => array( 'pl_PL', 'pl-PL', 'Polski', 'PL' ),
		'pt' => array( 'pt_PT', 'pt-PT', 'Português', 'PT' ),
		'ro' => array( 'ro_RO', 'ro-RO', 'Română', 'RO' ),
		'ru' => array( 'ru_RU', 'ru-RU', 'Русский', 'RU' ),
		'sk' => array( 'sk_SK', 'sk-SK', 'Slovenčina', 'SK' ),
		'sl' => array( 'sl_SI', 'sl-SI', 'Slovenščina', 'SL' ),
		'sr' => array( 'sr_RS', 'sr-RS', 'Српски', 'SR' ),
		'sv' => array( 'sv_SE', 'sv-SE', 'Svenska', 'SV' ),
		'tr' => array( 'tr_TR', 'tr-TR', 'Türkçe', 'TR' ),
		'uk' => array( 'uk', 'uk-UA', 'Українська', 'UK' ),
		'vi' => array( 'vi', 'vi-VN', 'Tiếng Việt', 'VI' ),
		'zh' => array( 'zh_CN', 'zh-CN', '中文', 'ZH' ),
	);

	/** @var array<string, Lang>|null */
	private static ?array $all = null;

	/** @return array<string, Lang> code => language, sorted by code */
	public static function all(): array {
		if ( null === self::$all ) {
			self::$all = array();
			foreach ( (array) apply_filters( 'marclie_lingua/language_presets', self::PRESETS ) as $code => $row ) {
				$code = strtolower( (string) $code );
				if ( 1 !== preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code ) || ! is_array( $row ) || count( $row ) < 4 ) {
					continue;
				}
				[ $locale, $bcp47, $name, $label ] = array_map( 'strval', array_values( $row ) );
				self::$all[ $code ] = new Lang( $code, $locale, $bcp47, $name, $label );
			}
			ksort( self::$all );
		}

		return self::$all;
	}

	public static function get( string $code ): ?Lang {
		return self::all()[ $code ] ?? null;
	}

	/** Preset whose WordPress locale matches (`de_DE`, `de_AT` → `de`), for the default source language. */
	public static function fromLocale( string $locale ): ?Lang {
		$code = strtolower( substr( $locale, 0, 2 ) );

		return self::get( $code );
	}
}
