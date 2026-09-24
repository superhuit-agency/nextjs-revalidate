<?php

namespace NextJsRevalidate\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Accessory\HasMethodType;
use PHPStan\Type\TypeUtils;

/**
 * The WordPress half of the compatibility gate: a call to a core API newer than
 * the floor the plugin declares is an analysis error.
 *
 * #122: `Requires at least` said 5.0 while the plugin hung its headline feature
 * on `wp_after_insert_post`, 5.6, and nothing could have noticed. PHPStan
 * already loads WordPress core as stubs (`szepeviktor/phpstan-wordpress`), and
 * the stubs keep core's docblocks — so every function, method and class the
 * plugin reaches for carries the `@since` core gave it, and this reads it.
 *
 * What it sees: function calls, method calls (instance, nullsafe and static)
 * and `new`, whenever what they resolve to is declared in the WordPress stubs.
 * What it cannot see is hooks — `add_action( 'wp_after_insert_post', … )` is a
 * call to a 2.0 function with a string in it, and the stubs carry no `do_action`
 * bodies to read a hook's `@since` from. `tests/wordpress-floor-test.php` holds
 * the hooks that set the floor; ADR 0029 records the limit.
 *
 * A call inside a `function_exists()`, `class_exists()` or `method_exists()`
 * check on what it calls is the sanctioned way to use a newer API below its
 * release, so it is not reported.
 *
 * The floor comes from `parameters.wordpressFloor` in phpstan.neon rather than
 * from the plugin header, because PHPStan's result cache is keyed on the
 * configuration and not on a file it has no reason to think a rule reads: a
 * header edit alone would leave every cached file's verdict in place.
 * `tests/wordpress-floor-test.php` fails when the two disagree.
 *
 * @implements Rule<CallLike>
 */
final class WordPressFloorRule implements Rule {

	/** Where the stubs live — what makes a function, method or class core's. */
	private const STUBS = '/php-stubs/wordpress-stubs/';

	/** @var ReflectionProvider */
	private $reflectionProvider;

	/** @var string */
	private $floor;

	public function __construct( ReflectionProvider $reflectionProvider, string $floor ) {
		$this->reflectionProvider = $reflectionProvider;
		$this->floor              = $floor;
	}

	public function getNodeType(): string {
		return CallLike::class;
	}

	/**
	 * @param CallLike $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		$found = $this->resolve( $node, $scope );
		if ( null === $found ) return [];

		[ $what, $doc, $guard ] = $found;
		$since = self::since( $doc );

		if ( null === $since || version_compare( self::full( $since ), self::full( $this->floor ), '<=' ) ) return [];

		return [
			RuleErrorBuilder::message( sprintf(
				'%s was added in WordPress %s, above the %s floor the plugin declares (`Requires at least`). Raise the floor (ADR 0028), or guard the call with %s.',
				$what,
				$since,
				$this->floor,
				$guard
			) )
				->identifier( 'nextjsRevalidate.wordpressFloor' )
				->build(),
		];
	}

	/**
	 * What an unguarded call reaches in core: a name for the message, the
	 * docblock carrying its `@since`, and the check that would guard it. Null
	 * when it is not core's, is already guarded, or cannot be resolved.
	 *
	 * @return array{string, ?string, string}|null
	 */
	private function resolve( CallLike $node, Scope $scope ): ?array {
		if ( $node instanceof FuncCall ) {
			if ( ! $node->name instanceof Name || ! $this->reflectionProvider->hasFunction( $node->name, $scope ) ) return null;

			$function = $this->reflectionProvider->getFunction( $node->name, $scope );
			if ( ! self::is_core( $function->getFileName() ) || $scope->isInFunctionExists( $function->getName() ) ) return null;

			return [ sprintf( 'Function %s()', $function->getName() ), $function->getDocComment(), 'function_exists()' ];
		}

		if ( $node instanceof New_ ) {
			$class = $node->class instanceof Name ? $this->class_reflection( $scope->resolveName( $node->class ) ) : null;
			if ( null === $class || ! self::is_core( $class->getFileName() ) || $scope->isInClassExists( $class->getName() ) ) return null;

			return [ sprintf( 'Class %s', $class->getName() ), $class->getNativeReflection()->getDocComment() ?: null, 'class_exists()' ];
		}

		if ( $node instanceof MethodCall || $node instanceof NullsafeMethodCall ) {
			if ( ! $node->name instanceof Identifier ) return null;

			$name = $node->name->toString();
			$type = $scope->getType( $node->var );
			if ( ! $type->hasMethod( $name )->yes() ) return null;

			// `method_exists( $screen, 'x' )` narrows `$screen` to `WP_Screen&hasMethod(x)`.
			foreach ( TypeUtils::getAccessoryTypes( $type ) as $accessory ) {
				if ( $accessory instanceof HasMethodType && $accessory->hasMethod( $name )->yes() ) return null;
			}

			$method = $type->getMethod( $name, $scope );
			$guard  = 'method_exists()';
		} elseif ( $node instanceof StaticCall ) {
			if ( ! $node->class instanceof Name || ! $node->name instanceof Identifier ) return null;

			$class = $this->class_reflection( $scope->resolveName( $node->class ) );
			if ( null === $class || ! $class->hasMethod( $node->name->toString() ) || $scope->isInClassExists( $class->getName() ) ) return null;

			$method = $class->getMethod( $node->name->toString(), $scope );
			$guard  = 'class_exists()';
		} else {
			return null;
		}

		$declaring = $method->getDeclaringClass();
		if ( ! self::is_core( $declaring->getFileName() ) ) return null;

		// A method core documents without a `@since` of its own arrived with its class.
		$doc = $method->getDocComment();
		if ( null === self::since( $doc ) ) $doc = $declaring->getNativeReflection()->getDocComment() ?: null;

		return [ sprintf( 'Method %s::%s()', $declaring->getName(), $method->getName() ), $doc, $guard ];
	}

	private function class_reflection( string $name ): ?ClassReflection {
		return $this->reflectionProvider->hasClass( $name ) ? $this->reflectionProvider->getClass( $name ) : null;
	}

	private static function is_core( ?string $file ): bool {
		return null !== $file && false !== strpos( str_replace( '\\', '/', $file ), self::STUBS );
	}

	/**
	 * The release a docblock says its subject arrived in: the first `@since`,
	 * which is where core records the introduction — later ones record changes.
	 * Multisite-era entries read `MU (3.0.0)`, and the number inside is the one.
	 */
	private static function since( ?string $doc ): ?string {
		if ( null === $doc ) return null;

		return preg_match( '/@since\s+(?:MU\s*\(\s*)?(\d+\.\d+(?:\.\d+)?)/', $doc, $matches ) ? $matches[1] : null;
	}

	/**
	 * `5.6` as `5.6.0`. `version_compare()` orders `5.6.0` above `5.6`, so a
	 * `@since 5.6.0` against a `5.6` floor would read as newer without it.
	 */
	private static function full( string $version ): string {
		return implode( '.', array_pad( explode( '.', $version ), 3, '0' ) );
	}
}
