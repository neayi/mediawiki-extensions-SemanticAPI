<?php

namespace MediaWiki\Extension\SemanticAPI;

/**
 * Edits {{#set:...}} statements in wikitext.
 *
 * A statement may hold several properties ({{#set:A=1|B=2|+sep=,}}), so properties are
 * edited one assignment at a time and the other assignments of the statement are kept.
 */
class SetStatementEditor {

    // {{#set:...}} statements, with up to two levels of nested templates in the values
    // (e.g. {{!}}), followed by the whitespace up to the end of the line
    private const SET_PATTERN = '/(\{\{\s*#set\s*:)((?:[^{}]|\{\{(?:[^{}]|\{\{[^{}]*\}\})*\}\})*)\}\}([ \t]*\n?)/i';

    /**
     * Sets the value of a property: the first assignment of the property is replaced,
     * other assignments of the same property are removed. If the property is not set
     * anywhere, a new {{#set:}} statement is appended to the page.
     */
    public static function setProperty( string $pageText, string $propertyName, string $value ): string {
        // Escape pipe characters in values
        $escapedValue = str_replace( '|', '{{!}}', $value );

        $found = false;
        $pageText = self::editAssignments( $pageText, $propertyName,
            static function ( string $lead, string $name, string $trail ) use ( $escapedValue, &$found ) {
                if ( $found ) {
                    return null;
                }
                $found = true;
                return $lead . $name . '=' . $escapedValue . $trail;
            }
        );

        if ( !$found ) {
            $separator = "\n";
            if ( $pageText !== '' && !preg_match( '/\n$/', $pageText ) ) {
                $separator = "\n\n";
            }
            $pageText .= $separator . "{{#set:{$propertyName}={$escapedValue}}}";
        }

        return $pageText;
    }

    /**
     * Removes all assignments of a property. Returns null if the property is not set
     * in the page.
     */
    public static function removeProperty( string $pageText, string $propertyName ): ?string {
        $found = false;
        $pageText = self::editAssignments( $pageText, $propertyName,
            static function () use ( &$found ) {
                $found = true;
                return null;
            }
        );

        if ( !$found ) {
            return null;
        }

        // Clean up multiple consecutive newlines
        return preg_replace( '/\n{3,}/', "\n\n", $pageText );
    }

    /**
     * Calls $editor for each assignment of $propertyName in the {{#set:}} statements of
     * the page. $editor returns the new assignment text, or null to remove the assignment.
     * Statements left without any assignment are removed.
     */
    private static function editAssignments( string $pageText, string $propertyName, callable $editor ): string {
        $target = self::normalizePropertyName( $propertyName );

        return preg_replace_callback( self::SET_PATTERN, static function ( $m ) use ( $target, $editor ) {
            [ $statement, $opening, $body, $after ] = $m;

            $changed = false;
            $parts = [];
            foreach ( self::splitParameters( $body ) as $part ) {
                $equalPos = strpos( $part, '=' );
                $name = $equalPos === false ? '' : trim( substr( $part, 0, $equalPos ) );

                if ( $name === '' || self::normalizePropertyName( $name ) !== $target ) {
                    $parts[] = $part;
                    continue;
                }

                $changed = true;
                preg_match( '/^(\s*)/', $part, $lead );
                preg_match( '/(\s*)$/', $part, $trail );
                $newPart = $editor( $lead[1], $name, $trail[1] );
                if ( $newPart !== null ) {
                    $parts[] = $newPart;
                }
            }

            if ( !$changed ) {
                return $statement;
            }

            // Drop the statement if only options (+sep=...) or empty parameters remain
            foreach ( $parts as $part ) {
                $equalPos = strpos( $part, '=' );
                if ( $equalPos !== false && !str_starts_with( ltrim( $part ), '+' ) ) {
                    return $opening . implode( '|', $parts ) . '}}' . $after;
                }
            }
            return '';
        }, $pageText );
    }

    /**
     * Splits the body of a parser function on the pipes that are not inside a nested
     * template or link.
     */
    private static function splitParameters( string $body ): array {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen( $body );

        for ( $i = 0; $i < $length; $i++ ) {
            $pair = substr( $body, $i, 2 );
            if ( $pair === '{{' || $pair === '[[' ) {
                $depth++;
                $current .= $pair;
                $i++;
            } elseif ( ( $pair === '}}' || $pair === ']]' ) && $depth > 0 ) {
                $depth--;
                $current .= $pair;
                $i++;
            } elseif ( $body[$i] === '|' && $depth === 0 ) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $body[$i];
            }
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * Property names follow page title rules: underscores and spaces are equivalent,
     * and the first letter is case insensitive.
     */
    private static function normalizePropertyName( string $name ): string {
        $name = trim( preg_replace( '/[\s_]+/u', ' ', $name ) );
        return mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
    }
}
