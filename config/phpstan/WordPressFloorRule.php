<?php

namespace NextJsRevalidate\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\TypeCombinator;

/**
 * The WordPress half of the compatibility gate: a use of a core API newer than
 * the floor the plugin declares is an analysis error.
 *
 * #122: `Requires at least` said 5.0 while the plugin hung its headline feature
 * on `wp_after_insert_post`, 5.6, and nothing could have noticed. PHPStan
 * already loads WordPress core as stubs (`szepeviktor/phpstan-wordpress`), and
 * the stubs keep core's docblocks — so every function, method and class the
 * plugin reaches for carries the `@since` core gave it, and this reads it.
 *
 * What it sees, whenever what it resolves to is declared in the stubs:
 * - function calls, method calls (instance, nullsafe and static), `new`, and
 *   class constants — on a receiver that may also be null or false, too;
 * - a function or method named as a callback — `'wp_is_block_theme'`,
 *   `'WP_X::m'`, `[ 'WP_X', 'm' ]` — passed where core or PHP asks for a
 *   `callable`, as `add_action()`, `call_user_func()` and `array_map()` do;
 * - a class a declaration extends or implements, which is fatal on load.
 * A member is as new as the newer of it and the class declaring it.
 *
 * What it cannot see is hooks — `add_action( 'wp_after_insert_post', … )` is a
 * call to a 2.0 function with a string in it, and the stubs carry no `do_action`
 * bodies to read a hook's `@since` from. Nor a callback built at runtime, nor a
 * class named only in a type or an `instanceof`, which is not fatal.
 * `tests/wordpress-floor-test.php` holds the hooks that set the floor; ADR 0030
 * records the limits.
 *
 * A function inside a `function_exists()` check on it, and a class inside a
 * `class_exists()` check on it, is the sanctioned way to use a newer API below
 * its release, so it is not reported. A `class_exists()` check covers a member
 * no newer than the checked class — `next_tag()` on a checked
 * `WP_HTML_Tag_Processor`, not `is_block_theme()` on a checked `WP_Theme`. A
 * `method_exists()` check cannot be honoured: the stubs already declare the
 * method, so PHPStan has nothing to narrow — and reports the check itself as
 * always true. A call guarded that way says so with an inline ignore of
 * `nextjsRevalidate.wordpressFloor`, and its reason.
 *
 * The floor comes from `parameters.wordpressFloor` in phpstan.neon rather than
 * from the plugin header, because PHPStan's result cache is keyed on the
 * configuration and not on a file it has no reason to think a rule reads: a
 * header edit alone would leave every cached file's verdict in place.
 * `tests/wordpress-floor-test.php` fails when the two disagree.
 *
 * @implements Rule<Node>
 */
final class WordPressFloorRule implements Rule {

	/** Where the stubs live — what makes a function, method or class core's. */
	private const STUBS = '/php-stubs/wordpress-stubs/';

	private const FUNCTION_REMEDY = 'guard it with function_exists()';

	private const CLASS_REMEDY = 'guard it with class_exists()';

	private const MEMBER_REMEDY = 'guard it with class_exists() on a class no older than it, or mark a use guarded some other way `@phpstan-ignore nextjsRevalidate.wordpressFloor` and say why';

	/** @var ReflectionProvider */
	private $reflection_provider;

	/** @var string */
	private $floor;

	public function __construct( ReflectionProvider $reflection_provider, string $floor ) {
		$this->reflection_provider = $reflection_provider;
		$this->floor               = $floor;
	}

	/**
	 * Every node, because what the rule holds spans three node families — calls,
	 * class constants and class declarations — and PHPStan hands a rule one type.
	 */
	public function getNodeType(): string {
		return Node::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( $node instanceof CallLike ) {
			$errors = array_merge( $this->check_call( $node, $scope ), $this->check_callbacks( $node, $scope ) );
		} elseif ( $node instanceof ClassConstFetch ) {
			$errors = $this->check_constant( $node, $scope );
		} elseif ( $node instanceof Class_ ) {
			$errors = $this->check_declaration( $node, $scope );
		} else {
			return [];
		}

		// A receiver typed as two classes sharing a core parent reports its method once.
		$unique = [];
		foreach ( $errors as $error ) $unique[ $error->getMessage() ] = $error;

		return array_values( $unique );
	}

	// What a node reaches in core
	// ====

	/** @return list<IdentifierRuleError> */
	private function check_call( CallLike $node, Scope $scope ): array {
		if ( $node instanceof FuncCall ) {
			return $node->name instanceof Name ? $this->check_function( $node->name, $scope ) : [];
		}

		if ( $node instanceof New_ ) {
			return $node->class instanceof Name ? $this->check_class( 'Class', $scope->resolveName( $node->class ), $scope ) : [];
		}

		if ( $node instanceof MethodCall || $node instanceof NullsafeMethodCall ) {
			if ( ! $node->name instanceof Identifier ) return [];

			// Every class the receiver may be: a `?WP_Screen` is a `WP_Screen` whenever the call runs at all.
			$receiver = TypeCombinator::remove( TypeCombinator::removeNull( $scope->getType( $node->var ) ), new ConstantBooleanType( false ) );
			$errors   = [];
			foreach ( $receiver->getObjectClassReflections() as $class ) {
				$errors = array_merge( $errors, $this->check_method( $class, $node->name->toString(), $scope ) );
			}

			return $errors;
		}

		if ( $node instanceof StaticCall ) {
			if ( ! $node->class instanceof Name || ! $node->name instanceof Identifier ) return [];

			$class = $this->class_reflection( $scope->resolveName( $node->class ) );

			return null === $class ? [] : $this->check_method( $class, $node->name->toString(), $scope );
		}

		return [];
	}

	/**
	 * Functions and methods named as callbacks, in the arguments that ask for a
	 * `callable`. Only there: a hook name is a string too, and `'wp_body_open'`
	 * passed to `do_action()` names a hook, whatever function shares its name.
	 *
	 * @return list<IdentifierRuleError>
	 */
	private function check_callbacks( CallLike $node, Scope $scope ): array {
		if ( $node->isFirstClassCallable() ) return [];

		$variants = $this->variants( $node, $scope );
		if ( ! $variants ) return [];

		$args       = $node->getArgs();
		$parameters = ParametersAcceptorSelector::selectFromArgs( $scope, $args, $variants )->getParameters();
		$last       = end( $parameters );
		$errors     = [];

		foreach ( $args as $position => $arg ) {
			if ( $arg->unpack || null !== $arg->name ) continue;

			$parameter = $parameters[ $position ] ?? ( $last && $last->isVariadic() ? $last : null );
			if ( null === $parameter || ! TypeCombinator::removeNull( $parameter->getType() )->isCallable()->yes() ) continue;

			$callback = $scope->getType( $arg->value );

			foreach ( $callback->getConstantStrings() as $string ) {
				$parts = explode( '::', ltrim( $string->getValue(), '\\' ), 2 );

				if ( 1 === count( $parts ) ) {
					$errors = array_merge( $errors, $this->check_function( new Name\FullyQualified( $parts[0] ), $scope ) );
					continue;
				}

				$class = $this->class_reflection( $parts[0] );
				if ( null !== $class ) $errors = array_merge( $errors, $this->check_method( $class, $parts[1], $scope ) );
			}

			foreach ( $callback->getConstantArrays() as $array ) {
				foreach ( $array->findTypeAndMethodNames() as $pair ) {
					if ( $pair->isUnknown() ) continue;

					foreach ( $pair->getType()->getObjectClassReflections() as $class ) {
						$errors = array_merge( $errors, $this->check_method( $class, $pair->getMethod(), $scope ) );
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * The signatures a call can be checked against, or none when what it calls
	 * cannot be resolved.
	 *
	 * @return list<ParametersAcceptor>
	 */
	private function variants( CallLike $node, Scope $scope ): array {
		if ( $node instanceof FuncCall ) {
			if ( ! $node->name instanceof Name || ! $this->reflection_provider->hasFunction( $node->name, $scope ) ) return [];

			return $this->reflection_provider->getFunction( $node->name, $scope )->getVariants();
		}

		if ( ( $node instanceof MethodCall || $node instanceof NullsafeMethodCall ) && $node->name instanceof Identifier ) {
			$method = $scope->getMethodReflection( $scope->getType( $node->var ), $node->name->toString() );

			return null === $method ? [] : $method->getVariants();
		}

		if ( $node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier ) {
			$class = $this->class_reflection( $scope->resolveName( $node->class ) );

			return null !== $class && $class->hasMethod( $node->name->toString() )
				? $class->getMethod( $node->name->toString(), $scope )->getVariants()
				: [];
		}

		if ( $node instanceof New_ && $node->class instanceof Name ) {
			$class = $this->class_reflection( $scope->resolveName( $node->class ) );

			return null !== $class && $class->hasConstructor() ? $class->getConstructor()->getVariants() : [];
		}

		return [];
	}

	/** @return list<IdentifierRuleError> */
	private function check_constant( ClassConstFetch $node, Scope $scope ): array {
		if ( ! $node->class instanceof Name || ! $node->name instanceof Identifier || 'class' === $node->name->toLowerString() ) return [];

		$class = $this->class_reflection( $scope->resolveName( $node->class ) );
		$name  = $node->name->toString();

		if ( null === $class || ! $class->hasConstant( $name ) ) return [];

		return $this->check_member( $class, $class->getConstant( $name ), sprintf( 'Constant %%s::%s', $name ), $scope );
	}

	/** @return list<IdentifierRuleError> */
	private function check_declaration( Class_ $node, Scope $scope ): array {
		$errors = null === $node->extends ? [] : $this->check_class( 'Class', $node->extends->toString(), $scope );

		foreach ( $node->implements as $interface ) {
			$errors = array_merge( $errors, $this->check_class( 'Interface', $interface->toString(), $scope ) );
		}

		return $errors;
	}

	// Core's releases
	// ====

	/** @return list<IdentifierRuleError> */
	private function check_function( Name $name, Scope $scope ): array {
		if ( ! $this->reflection_provider->hasFunction( $name, $scope ) ) return [];

		$function = $this->reflection_provider->getFunction( $name, $scope );
		if ( ! self::is_core( $function->getFileName() ) || $scope->isInFunctionExists( $function->getName() ) ) return [];

		return $this->report( sprintf( 'Function %s()', $function->getName() ), self::since( $function->getDocComment() ), self::FUNCTION_REMEDY );
	}

	/** @return list<IdentifierRuleError> */
	private function check_class( string $kind, string $name, Scope $scope ): array {
		$class = $this->class_reflection( $name );
		if ( null === $class || ! self::is_core( $class->getFileName() ) || $scope->isInClassExists( $class->getName() ) ) return [];

		return $this->report( sprintf( '%s %s', $kind, $class->getName() ), self::class_since( $class ), self::CLASS_REMEDY );
	}

	/** @return list<IdentifierRuleError> */
	private function check_method( ClassReflection $receiver, string $name, Scope $scope ): array {
		if ( ! $receiver->hasMethod( $name ) ) return [];

		$method = $receiver->getMethod( $name, $scope );

		return $this->check_member( $receiver, $method, sprintf( 'Method %%s::%s()', $method->getName() ), $scope );
	}

	/**
	 * A method or constant, dated by the newer of its own `@since` and its
	 * class's — a member core documents without one arrived with its class, and
	 * none arrived before it.
	 *
	 * @param string $what The member's name for the message, with `%s` where its declaring class goes.
	 * @return list<IdentifierRuleError>
	 */
	private function check_member( ClassReflection $receiver, ClassMemberReflection $member, string $what, Scope $scope ): array {
		$declaring = $member->getDeclaringClass();
		if ( ! self::is_core( $declaring->getFileName() ) ) return [];

		$since = self::newest( self::since( $member->getDocComment() ), self::class_since( $declaring ) );

		foreach ( [ $receiver, $declaring ] as $class ) {
			if ( ! $scope->isInClassExists( $class->getName() ) ) continue;

			$checked = self::class_since( $class );
			if ( null !== $checked && ! self::is_newer( $since, $checked ) ) return [];
		}

		return $this->report( sprintf( $what, $declaring->getName() ), $since, self::MEMBER_REMEDY );
	}

	/** @return list<IdentifierRuleError> */
	private function report( string $what, ?string $since, string $remedy ): array {
		if ( null === $since || ! self::is_newer( $since, $this->floor ) ) return [];

		return [
			RuleErrorBuilder::message( sprintf(
				'%s was added in WordPress %s, above the %s floor the plugin declares (`Requires at least`). Raise the floor (ADR 0028), or %s.',
				$what,
				$since,
				$this->floor,
				$remedy
			) )
				->identifier( 'nextjsRevalidate.wordpressFloor' )
				->build(),
		];
	}

	// Reading the stubs
	// ====

	private function class_reflection( string $name ): ?ClassReflection {
		return $this->reflection_provider->hasClass( $name ) ? $this->reflection_provider->getClass( $name ) : null;
	}

	private static function is_core( ?string $file ): bool {
		return null !== $file && false !== strpos( str_replace( '\\', '/', $file ), self::STUBS );
	}

	private static function class_since( ClassReflection $class ): ?string {
		return self::since( $class->getNativeReflection()->getDocComment() ?: null );
	}

	/**
	 * The release a docblock says its subject arrived in: the first `@since`,
	 * which is where core records the introduction — later ones record changes.
	 * Multisite-era entries read `MU (3.0.0)`, and the number inside is the one.
	 * A first `@since` naming no release — `Unknown`, `Beta`, a bundled library's
	 * own — dates nothing, rather than letting a later change stand in for it.
	 */
	private static function since( ?string $doc ): ?string {
		if ( null === $doc || ! preg_match( '/@since\s+(?:MU\s*\(\s*)?(\S+)/', $doc, $tag ) ) return null;

		return preg_match( '/^\d+\.\d+(?:\.\d+)?/', $tag[1], $release ) ? $release[0] : null;
	}

	/** The later of two releases, either of which may be unknown. */
	private static function newest( ?string $a, ?string $b ): ?string {
		if ( null === $a || null === $b ) return $a ?? $b;

		return self::is_newer( $a, $b ) ? $a : $b;
	}

	/** Whether `$since` is a later release than `$than`. An unknown one is not. */
	private static function is_newer( ?string $since, string $than ): bool {
		return null !== $since && version_compare( self::pad_version( $since ), self::pad_version( $than ), '>' );
	}

	/**
	 * `5.6` as `5.6.0`. `version_compare()` orders `5.6.0` above `5.6`, so a
	 * `@since 5.6.0` against a `5.6` floor would read as newer without it.
	 */
	private static function pad_version( string $version ): string {
		return implode( '.', array_pad( explode( '.', $version ), 3, '0' ) );
	}
}
