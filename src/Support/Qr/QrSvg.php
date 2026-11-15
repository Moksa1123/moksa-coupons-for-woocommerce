<?php

declare( strict_types=1 );

namespace Moksafocou\Support\Qr;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a QrEncoder module matrix to a standalone SVG string.
 *
 * The markup is assembled only from plugin-computed integers and two hard-coded colour literals —
 * the coupon code / share URL is never written into it (it lives only in the dark-module geometry).
 * It is still escaped on output with wp_kses() and allowed_html(), so the response does not rely on
 * "safe by construction" alone.
 *
 * No viewBox: wp_kses() lower-cases attribute names, and a standalone SVG parser ignores
 * "viewbox", which used to break the coordinate system. Coordinates are therefore written in pixels
 * (module × scale) with fixed width/height, which renders identically. Empty elements are written
 * as " />", the form wp_kses() normalises to, so escaping leaves the markup byte-for-byte unchanged. A 4-module quiet zone is
 * included (scanners need it) and dark modules are one crisp-edged <path>.
 */
final class QrSvg {

	/** Quiet-zone width in modules (QR spec minimum is 4). */
	private const QUIET = 4;

	private const DARK  = '#000000';
	private const LIGHT = '#ffffff';

	/**
	 * @param string $data     Payload to encode (final URL byte form).
	 * @param int    $px_scale Pixels per module.
	 * @return string|\WP_Error SVG markup, or the encoder's error.
	 */
	public static function render( string $data, int $px_scale = 8 ) {
		$matrix = QrEncoder::encode( $data );
		if ( $matrix instanceof \WP_Error ) {
			return $matrix;
		}
		return self::from_matrix( $matrix, $px_scale );
	}

	/**
	 * @param array<int,array<int,int>> $matrix
	 */
	public static function from_matrix( array $matrix, int $px_scale = 8 ): string {
		$count    = count( $matrix );
		$px_scale = max( 1, min( 40, $px_scale ) );
		$px       = ( $count + ( 2 * self::QUIET ) ) * $px_scale;

		$path = '';
		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $module ) {
				if ( 1 === $module ) {
					$path .= 'M' . ( ( $x + self::QUIET ) * $px_scale ) . ',' . ( ( $y + self::QUIET ) * $px_scale )
						. 'h' . $px_scale . 'v' . $px_scale . 'h-' . $px_scale . 'z';
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" shape-rendering="crispEdges" role="img" aria-label="QR code">'
			. '<rect width="%1$d" height="%1$d" fill="%2$s" />'
			. '<path d="%3$s" fill="%4$s" />'
			. '</svg>',
			$px,
			self::LIGHT,
			$path,
			self::DARK
		);
	}

	/**
	 * Exactly the elements and attributes from_matrix() writes, for wp_kses().
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'svg'  => array(
				'xmlns'           => true,
				'width'           => true,
				'height'          => true,
				'shape-rendering' => true,
				'role'            => true,
				'aria-label'      => true,
			),
			'rect' => array(
				'width'  => true,
				'height' => true,
				'fill'   => true,
			),
			'path' => array(
				'd'    => true,
				'fill' => true,
			),
		);
	}
}
