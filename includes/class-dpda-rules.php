<?php
/**
 * Product data audit component.
 *
 * @package Digicyfr_Product_Data_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin component. */
final class DPDA_Rules {
	public const OPTION = 'dpda_rules_v1';

	/** Each key is one independently selected, applicable policy cell. */
	public static function definitions(): array {
		return array(
			'simple_image'                     => array( 'simple', __( 'Main image', 'digicyfr-product-data-audit' ), __( 'This published simple product has no main image.', 'digicyfr-product-data-audit' ) ),
			'simple_description'               => array( 'simple', __( 'Full description', 'digicyfr-product-data-audit' ), __( 'This published simple product has no full description.', 'digicyfr-product-data-audit' ) ),
			'simple_short_description'         => array( 'simple', __( 'Short description', 'digicyfr-product-data-audit' ), __( 'This published simple product has no short description.', 'digicyfr-product-data-audit' ) ),
			'simple_sku'                       => array( 'simple', __( 'SKU', 'digicyfr-product-data-audit' ), __( 'This published simple product has no SKU.', 'digicyfr-product-data-audit' ) ),
			'simple_price'                     => array( 'simple', __( 'Active price', 'digicyfr-product-data-audit' ), __( 'This published simple product has no active price; zero is valid.', 'digicyfr-product-data-audit' ) ),
			'simple_weight'                    => array( 'simple', __( 'Weight for nonvirtual item', 'digicyfr-product-data-audit' ), __( 'This nonvirtual simple product has no weight.', 'digicyfr-product-data-audit' ) ),
			'simple_dimensions'                => array( 'simple', __( 'All three dimensions for nonvirtual item', 'digicyfr-product-data-audit' ), __( 'This nonvirtual simple product is missing length, width, or height.', 'digicyfr-product-data-audit' ) ),
			'simple_download'                  => array( 'simple', __( 'File for downloadable item', 'digicyfr-product-data-audit' ), __( 'This downloadable simple product has no download file.', 'digicyfr-product-data-audit' ) ),
			'simple_stock'                     => array( 'simple', __( 'Quantity when stock is managed', 'digicyfr-product-data-audit' ), __( 'This stock-managed simple product has no quantity; zero is valid.', 'digicyfr-product-data-audit' ) ),
			'variable_image'                   => array( 'variable', __( 'Main image', 'digicyfr-product-data-audit' ), __( 'This published variable parent has no main image.', 'digicyfr-product-data-audit' ) ),
			'variable_description'             => array( 'variable', __( 'Full description', 'digicyfr-product-data-audit' ), __( 'This published variable parent has no full description.', 'digicyfr-product-data-audit' ) ),
			'variable_short_description'       => array( 'variable', __( 'Short description', 'digicyfr-product-data-audit' ), __( 'This published variable parent has no short description.', 'digicyfr-product-data-audit' ) ),
			'variable_sku'                     => array( 'variable', __( 'Parent SKU', 'digicyfr-product-data-audit' ), __( 'This published variable parent has no parent SKU.', 'digicyfr-product-data-audit' ) ),
			'variable_has_published_variation' => array( 'variable', __( 'At least one published variation', 'digicyfr-product-data-audit' ), __( 'This variable parent has no published child variation; this does not test purchasability.', 'digicyfr-product-data-audit' ) ),
			'variation_own_sku'                => array( 'variation', __( 'Own variation SKU', 'digicyfr-product-data-audit' ), __( 'This published variation has no own SKU, even if it displays a parent SKU.', 'digicyfr-product-data-audit' ) ),
			'variation_price'                  => array( 'variation', __( 'Active variation price', 'digicyfr-product-data-audit' ), __( 'This published variation has no active price; zero is valid.', 'digicyfr-product-data-audit' ) ),
			'variation_weight'                 => array( 'variation', __( 'Effective weight for nonvirtual item', 'digicyfr-product-data-audit' ), __( 'This nonvirtual published variation has no effective weight, including inheritance.', 'digicyfr-product-data-audit' ) ),
			'variation_dimensions'             => array( 'variation', __( 'Effective dimensions for nonvirtual item', 'digicyfr-product-data-audit' ), __( 'This nonvirtual published variation is missing an effective dimension, including inheritance.', 'digicyfr-product-data-audit' ) ),
			'variation_download'               => array( 'variation', __( 'File for downloadable variation', 'digicyfr-product-data-audit' ), __( 'This downloadable published variation has no download file.', 'digicyfr-product-data-audit' ) ),
			'variation_stock'                  => array( 'variation', __( 'Effective quantity when stock is managed', 'digicyfr-product-data-audit' ), __( 'This stock-managed published variation has no effective quantity; zero is valid.', 'digicyfr-product-data-audit' ) ),
		);
	}

	/** Selected. */
	public static function selected(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/** Normalize.
	 *
	 * @param array $raw raw input.
	 */
	public static function normalize( array $raw ): array {
		$out = array_fill_keys( array_keys( self::definitions() ), false );
		foreach ( $out as $key => $unused ) {
			$out[ $key ] = isset( $raw[ $key ] ) && in_array( $raw[ $key ], array( true, 1, '1', 'on' ), true );
		}
		return $out;
	}

	/** Suggested. */
	public static function suggested(): array {
		return self::normalize( array_fill_keys( array( 'simple_image', 'simple_description', 'simple_price', 'variable_image', 'variable_has_published_variation', 'variation_price' ), true ) );
	}

	/** Hash.
	 *
	 * @param array $selection selection input.
	 */
	public static function hash( array $selection ): string {
		return hash( 'sha256', wp_json_encode( self::normalize( $selection ) ) );
	}

	/** Count selected.
	 *
	 * @param array $selection selection input.
	 */
	public static function count_selected( array $selection ): int {
		return count( array_filter( self::normalize( $selection ) ) );
	}

	/**
	 * Return selected missing-field keys for one WooCommerce subject.
	 *
	 * @param WC_Product $product Product to check.
	 * @param array      $selection Merchant-selected policy matrix.
	 * @param int        $published_children Number of published children for a variable parent.
	 */
	public static function evaluate( WC_Product $product, array $selection, int $published_children = 0 ): array {
		$type = $product->get_type();
		if ( ! in_array( $type, array( 'simple', 'variable', 'variation' ), true ) ) {
			return array();
		}
		$missing = array();
		$checks  = array(
			'image'                   => static fn () => 0 === (int) $product->get_image_id(),
			'description'             => static fn () => '' === trim( $product->get_description() ),
			'short_description'       => static fn () => '' === trim( $product->get_short_description() ),
			'sku'                     => static fn () => '' === (string) $product->get_sku(),
			'own_sku'                 => static fn () => '' === (string) $product->get_sku( 'edit' ),
			'price'                   => static fn () => '' === (string) $product->get_price(),
			'weight'                  => static fn () => ! $product->get_virtual() && '' === (string) $product->get_weight(),
			'dimensions'              => static fn () => ! $product->get_virtual() && ( '' === (string) $product->get_length() || '' === (string) $product->get_width() || '' === (string) $product->get_height() ),
			'download'                => static fn () => $product->get_downloadable() && array() === $product->get_downloads(),
			'stock'                   => static fn () => ( true === $product->get_manage_stock() || 'parent' === $product->get_manage_stock() ) && null === $product->get_stock_quantity(),
			'has_published_variation' => static fn () => 0 === $published_children,
		);
		foreach ( self::normalize( $selection ) as $key => $enabled ) {
			if ( ! $enabled || ! str_starts_with( $key, $type . '_' ) ) {
				continue;
			}
			$suffix = substr( $key, strlen( $type ) + 1 );
			if ( isset( $checks[ $suffix ] ) && $checks[ $suffix ]() ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}
}
