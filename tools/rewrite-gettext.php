<?php
/**
 * Rewrite gettext source literals using a JSON old=>new map.
 *
 * This one-off helper deliberately touches only literal arguments of WordPress
 * gettext functions. Persisted business keys and arbitrary PHP strings remain
 * unchanged.
 */

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "CLI only.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$map_file = $argv[1] ?? ( $root . '/tools/de-en-map.json' );
$map = json_decode( (string) file_get_contents( $map_file ), true );
if ( ! is_array( $map ) ) {
    fwrite( STDERR, "Invalid translation map.\n" );
    exit( 1 );
}

$functions = [
    '__'           => [ 0 ],
    '_e'           => [ 0 ],
    '_x'           => [ 0 ],
    '_ex'          => [ 0 ],
    '_n'           => [ 0, 1 ],
    '_nx'          => [ 0, 1 ],
    'esc_html__'   => [ 0 ],
    'esc_html_e'   => [ 0 ],
    'esc_html_x'   => [ 0 ],
    'esc_attr__'   => [ 0 ],
    'esc_attr_e'   => [ 0 ],
    'esc_attr_x'   => [ 0 ],
];

/**
 * Decode a PHP constant string literal without evaluating arbitrary code.
 */
function rmwc_decode_literal( string $literal ): string {
    $quote = $literal[0] ?? '';
    $body = substr( $literal, 1, -1 );
    if ( "'" === $quote ) {
        return str_replace( [ "\\\\", "\\'" ], [ "\\", "'" ], $body );
    }
    return stripcslashes( $body );
}

/**
 * Encode a value as a conservative PHP single-quoted literal.
 */
function rmwc_encode_literal( string $value ): string {
    return "'" . str_replace( [ "\\", "'" ], [ "\\\\", "\\'" ], $value ) . "'";
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

$changed_files = 0;
$changed_literals = 0;

foreach ( $iterator as $file ) {
    if ( ! $file instanceof SplFileInfo || 'php' !== strtolower( $file->getExtension() ) ) {
        continue;
    }
    $path = $file->getPathname();
    $relative = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
    if ( str_starts_with( $relative, '.git/' ) || str_starts_with( $relative, '.github/' ) || str_starts_with( $relative, 'tools/' ) || str_starts_with( $relative, 'tests/' ) ) {
        continue;
    }

    $source = (string) file_get_contents( $path );
    $tokens = token_get_all( $source );
    $out = '';
    $count = count( $tokens );
    $changed = false;

    for ( $i = 0; $i < $count; $i++ ) {
        $token = $tokens[ $i ];
        if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
            $out .= is_array( $token ) ? $token[1] : $token;
            continue;
        }

        $function = $token[1];
        $target_args = $functions[ $function ];
        $out .= $function;

        // Copy whitespace/comments through the opening parenthesis.
        $j = $i + 1;
        while ( $j < $count ) {
            $next = $tokens[ $j ];
            $text = is_array( $next ) ? $next[1] : $next;
            $out .= $text;
            if ( '(' === $text ) {
                break;
            }
            // This was not a function call after all.
            if ( ! is_array( $next ) || ! in_array( $next[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                break;
            }
            $j++;
        }

        if ( $j >= $count || '(' !== ( is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ] ) ) {
            $i = $j - 1;
            continue;
        }

        $depth = 1;
        $arg_index = 0;
        $j++;
        for ( ; $j < $count && $depth > 0; $j++ ) {
            $part = $tokens[ $j ];
            $text = is_array( $part ) ? $part[1] : $part;

            if ( is_array( $part ) && T_CONSTANT_ENCAPSED_STRING === $part[0] && 1 === $depth && in_array( $arg_index, $target_args, true ) ) {
                $decoded = rmwc_decode_literal( $text );
                if ( isset( $map[ $decoded ] ) && is_string( $map[ $decoded ] ) && '' !== $map[ $decoded ] ) {
                    $text = rmwc_encode_literal( $map[ $decoded ] );
                    $changed = true;
                    $changed_literals++;
                }
            }

            $out .= $text;

            if ( '(' === $text || '[' === $text || '{' === $text ) {
                $depth++;
            } elseif ( ')' === $text || ']' === $text || '}' === $text ) {
                $depth--;
            } elseif ( ',' === $text && 1 === $depth ) {
                $arg_index++;
            }
        }

        $i = $j - 1;
    }

    if ( $changed ) {
        file_put_contents( $path, $out );
        $changed_files++;
    }
}

fwrite( STDOUT, "Rewrote {$changed_literals} gettext literals in {$changed_files} PHP files.\n" );
