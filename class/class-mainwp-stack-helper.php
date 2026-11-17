<?php
/**
 * MainWP Utility Helper.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors, Generic.Metrics.CyclomaticComplexity -- Using cURL functions.

/**
 * Class MainWP_Stack_Helper
 *
 * @package MainWP\Dashboard
 */
class MainWP_Stack_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Working stack.
     *
     * @var array $working_stack Working stack.
     */
    private static $working_stack = array();

    /**
     * Stack push
     *
     * @param mixed $action Action to add to the working stack.
     * @return void
     */
    public static function stack_push( $action ) {
        self::$working_stack[] = $action;
    }

    /**
     * Stack pop
     *
     * @return mixed Removed stack item.
     */
    public static function stack_pop() {
        return array_pop( self::$working_stack );
    }

    /**
     * Stack current
     *
     * @return mixed|null Current stack item, or null when the stack is empty.
     */
    public static function stack_current() {
        return self::$working_stack ? end( self::$working_stack ) : null;
    }

    /**
     * Stack all
     *
     * @return array Working stack.
     */
    public static function stack_all() {
        return self::$working_stack;
    }

    /**
     * Stack clear
     *
     * @return void
     */
    public static function stack_clear() {
        self::$stack = array();
    }

    /**
     * In stack
     *
     * @param mixed $action Action to search for in the working stack.
     * @return bool Whether the action is in the working stack.
     */
    public static function in_stack( $action ) {
        return in_array( $action, self::$working_stack, true );
    }

    /**
     * Is current stack.
     *
     * @param mixed $action Action to compare with the current stack item.
     * @return bool Whether the action is the current stack item.
     */
    public static function is_current_stack( $action ) {
        return self::stack_current() === $action;
    }
}
