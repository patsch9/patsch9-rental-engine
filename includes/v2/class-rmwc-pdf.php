<?php
/**
 * Minimal local PDF renderer for rental documents.
 *
 * This intentionally has no external service or remote dependency. It renders
 * plain, readable A4 documents using a core PDF font and WinAnsi encoding.
 *
 * @package RMWC
 */

defined( 'ABSPATH' ) || exit;

final class RMWC_PDF {
    /**
     * Build a PDF document.
     *
     * @param string   $title Document title.
     * @param string[] $lines Text lines.
     * @return string Binary PDF bytes.
     */
    public static function build( $title, array $lines, $signature_jpeg = '' ) {
        $title = self::clean_line( $title );
        $lines = array_map( [ __CLASS__, 'clean_line' ], $lines );

        $signature = self::decode_signature( $signature_jpeg );
        $pages = [];
        $page  = [];
        $max_lines = $signature ? 38 : 48;

        foreach ( $lines as $line ) {
            $wrapped = self::wrap_line( $line, 92 );
            foreach ( $wrapped as $wrapped_line ) {
                if ( count( $page ) >= $max_lines ) {
                    $pages[] = $page;
                    $page    = [];
                }
                $page[] = $wrapped_line;
            }
        }
        if ( $page || ! $pages ) {
            $pages[] = $page;
        }

        $objects = [];
        $add_object = static function( $content ) use ( &$objects ) {
            $objects[] = $content;
            return count( $objects );
        };

        $catalog_id   = $add_object( '' );
        $pages_id     = $add_object( '' );
        $font_id      = $add_object( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
        $font_bold_id = $add_object( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' );

        $signature_object_id = 0;
        if ( $signature ) {
            $signature_object_id = $add_object(
                '<< /Type /XObject /Subtype /Image /Width ' . (int) $signature['width'] .
                ' /Height ' . (int) $signature['height'] .
                ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' .
                strlen( $signature['bytes'] ) . " >>\nstream\n" .
                $signature['bytes'] . "\nendstream"
            );
        }

        $page_ids = [];
        foreach ( $pages as $page_index => $page_lines ) {
            $stream = "BT\n/F2 15 Tf\n50 790 Td\n";
            $stream .= '(' . self::pdf_escape( self::encode( $title ) ) . ") Tj\n";
            $stream .= "/F1 10 Tf\n0 -26 Td\n";
            foreach ( $page_lines as $line ) {
                $stream .= '(' . self::pdf_escape( self::encode( $line ) ) . ") Tj\n0 -15 Td\n";
            }
            $stream .= "ET\n";

            $resource_xobject = '';
            if ( $signature && 0 === $page_index ) {
                $available_width = 220.0;
                $scale = min( $available_width / max( 1, (float) $signature['width'] ), 90.0 / max( 1, (float) $signature['height'] ) );
                $draw_w = max( 1.0, $signature['width'] * $scale );
                $draw_h = max( 1.0, $signature['height'] * $scale );
                $stream .= "BT /F1 8 Tf 50 170 Td (Dokumentationsunterschrift:) Tj ET\n";
                $stream .= sprintf( "q %.2F 0 0 %.2F 50 70 cm /Sig Do Q\n", $draw_w, $draw_h );
                $resource_xobject = ' /XObject << /Sig ' . $signature_object_id . ' 0 R >>';
            }

            $stream .= "BT /F1 8 Tf 50 28 Td (Seite " . ( $page_index + 1 ) . ' / ' . count( $pages ) . ") Tj ET\n";

            $content_id = $add_object( '<< /Length ' . strlen( $stream ) . ">>\nstream\n" . $stream . "endstream" );
            $page_id = $add_object(
                '<< /Type /Page /Parent ' . $pages_id . ' 0 R /MediaBox [0 0 595 842] ' .
                '/Resources << /Font << /F1 ' . $font_id . ' 0 R /F2 ' . $font_bold_id . ' 0 R >>' . $resource_xobject . ' >> ' .
                '/Contents ' . $content_id . ' 0 R >>'
            );
            $page_ids[] = $page_id;
        }

        $objects[ $pages_id - 1 ] = '<< /Type /Pages /Kids [' . implode( ' ', array_map( static fn( $id ) => $id . ' 0 R', $page_ids ) ) . '] /Count ' . count( $page_ids ) . ' >>';
        $objects[ $catalog_id - 1 ] = '<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [ 0 ];
        foreach ( $objects as $index => $object ) {
            $id = $index + 1;
            $offsets[ $id ] = strlen( $pdf );
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen( $pdf );
        $pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ( $id = 1; $id <= count( $objects ); $id++ ) {
            $pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
        }
        $pdf .= 'trailer' . "\n<< /Size " . ( count( $objects ) + 1 ) . ' /Root ' . $catalog_id . " 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    /**
     * Build a visually structured business document while keeping the same
     * dependency-free local PDF engine.
     *
     * Supported block types: section, row, text, bullet, notice, spacer and page_break.
     *
     * @param string $title Document title.
     * @param array  $blocks Structured content blocks.
     * @param string $signature_jpeg Optional JPEG data URI.
     * @param string $footer Optional footer label.
     * @return string Binary PDF bytes.
     */
    public static function build_styled( $title, array $blocks, $signature_jpeg = '', $footer = '' ) {
        $title     = self::clean_line( $title );
        $footer    = self::clean_line( $footer );
        $signature = self::decode_signature( $signature_jpeg );

        $objects = [];
        $add_object = static function( $content ) use ( &$objects ) {
            $objects[] = $content;
            return count( $objects );
        };

        $catalog_id   = $add_object( '' );
        $pages_id     = $add_object( '' );
        $font_id      = $add_object( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
        $font_bold_id = $add_object( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' );

        $signature_object_id = 0;
        if ( $signature ) {
            $signature_object_id = $add_object(
                '<< /Type /XObject /Subtype /Image /Width ' . (int) $signature['width'] .
                ' /Height ' . (int) $signature['height'] .
                ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' .
                strlen( $signature['bytes'] ) . " >>\nstream\n" .
                $signature['bytes'] . "\nendstream"
            );
        }

        $pages   = [];
        $page    = '';
        $y       = 0.0;
        $is_first_page = true;

        $text_command = static function( $text, $font, $size, $x, $y_pos ) {
            $text = self::clean_line( $text );
            if ( '' === $text ) {
                return '';
            }
            return sprintf(
                "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
                $font,
                (float) $size,
                (float) $x,
                (float) $y_pos,
                self::pdf_escape( self::encode( $text ) )
            );
        };

        $start_page = static function() use ( &$page, &$y, &$is_first_page, $title, $text_command ) {
            $page = '';
            if ( $is_first_page ) {
                $page .= $text_command( $title, 'F2', 20, 50, 792 );
                $page .= "0.72 G 0.8 w 50 770 m 545 770 l S\n";
                $y = 746.0;
                $is_first_page = false;
            } else {
                $page .= $text_command( $title, 'F2', 11, 50, 796 );
                $page .= "0.84 G 0.6 w 50 783 m 545 783 l S\n";
                $y = 760.0;
            }
        };

        $finish_page = static function() use ( &$pages, &$page ) {
            $pages[] = $page;
            $page    = '';
        };

        $start_page();

        $ensure_space = static function( $needed ) use ( &$y, $finish_page, $start_page ) {
            if ( $y - (float) $needed < 66.0 ) {
                $finish_page();
                $start_page();
            }
        };

        $draw_wrapped = static function( $text, $font, $size, $x, $wrap, $leading ) use ( &$page, &$y, $text_command, $ensure_space ) {
            $parts = preg_split( "/\r\n?|\n/u", (string) $text ) ?: [ (string) $text ];
            foreach ( $parts as $part_index => $part ) {
                $part = self::clean_line( $part );
                $rows = '' === $part ? [ '' ] : self::wrap_line( $part, $wrap );
                foreach ( $rows as $row ) {
                    $ensure_space( $leading + 1 );
                    if ( '' !== $row ) {
                        $page .= $text_command( $row, $font, $size, $x, $y );
                    }
                    $y -= $leading;
                }
                if ( $part_index < count( $parts ) - 1 ) {
                    $y -= 1.5;
                }
            }
        };

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }
            $type = sanitize_key( $block['type'] ?? 'text' );

            if ( 'spacer' === $type ) {
                $y -= max( 4.0, min( 24.0, (float) ( $block['height'] ?? 9 ) ) );
                continue;
            }

            if ( 'page_break' === $type ) {
                // Keep contractual appendices visually separate even if there
                // would still be room on the preceding page.
                $finish_page();
                $start_page();
                continue;
            }

            if ( 'section' === $type ) {
                $ensure_space( 32 );
                $y -= 4;
                $page .= sprintf( "q 0.945 g 50 %.2F 495 24 re f Q\n", $y - 17 );
                $page .= $text_command( $block['title'] ?? '', 'F2', 11, 59, $y - 10 );
                $y -= 31;
                continue;
            }

            if ( 'row' === $type ) {
                $label = self::clean_line( $block['label'] ?? '' );
                $value = (string) ( $block['value'] ?? '' );
                $value_parts = preg_split( "/\r\n?|\n/u", $value ) ?: [ $value ];
                $value_rows = [];
                foreach ( $value_parts as $value_part ) {
                    $value_part = self::clean_line( $value_part );
                    if ( '' === $value_part ) {
                        $value_rows[] = '';
                    } else {
                        $value_rows = array_merge( $value_rows, self::wrap_line( $value_part, 59 ) );
                    }
                }
                $row_height = max( 16.0, 13.0 * max( 1, count( $value_rows ) ) );
                $ensure_space( $row_height + 2 );
                if ( '' !== $label ) {
                    $page .= $text_command( $label, 'F2', 9.2, 58, $y );
                }
                $value_y = $y;
                foreach ( $value_rows as $value_row ) {
                    if ( '' !== $value_row ) {
                        $page .= $text_command( $value_row, 'F1', 9.5, 184, $value_y );
                    }
                    $value_y -= 13;
                }
                $y -= $row_height;
                continue;
            }

            if ( 'bullet' === $type ) {
                $text = self::clean_line( $block['text'] ?? '' );
                $rows = self::wrap_line( $text, 78 );
                $needed = 13.0 * max( 1, count( $rows ) );
                $ensure_space( $needed + 2 );
                $page .= $text_command( '-', 'F2', 10, 60, $y );
                $row_y = $y;
                foreach ( $rows as $row ) {
                    $page .= $text_command( $row, 'F1', 9.5, 74, $row_y );
                    $row_y -= 13;
                }
                $y -= $needed;
                continue;
            }

            if ( 'notice' === $type ) {
                $text = self::clean_line( $block['text'] ?? '' );
                $rows = self::wrap_line( $text, 79 );
                $height = max( 36.0, 15.0 + 13.0 * count( $rows ) );
                $ensure_space( $height + 6 );
                $page .= sprintf( "q 0.985 g 0.75 G 50 %.2F 495 %.2F re B Q\n", $y - $height + 7, $height );
                $row_y = $y - 9;
                foreach ( $rows as $row ) {
                    $page .= $text_command( $row, 'F1', 9.4, 61, $row_y );
                    $row_y -= 13;
                }
                $y -= $height + 5;
                continue;
            }

            $draw_wrapped( $block['text'] ?? '', ! empty( $block['bold'] ) ? 'F2' : 'F1', (float) ( $block['size'] ?? 9.7 ), 58, 84, 13.5 );
        }

        if ( $signature ) {
            $ensure_space( 126 );
            $page .= $text_command( 'Dokumentationsunterschrift', 'F2', 9.2, 58, $y );
            $y -= 12;
            $available_width = 220.0;
            $scale = min( $available_width / max( 1, (float) $signature['width'] ), 85.0 / max( 1, (float) $signature['height'] ) );
            $draw_w = max( 1.0, $signature['width'] * $scale );
            $draw_h = max( 1.0, $signature['height'] * $scale );
            $page .= sprintf( "q %.2F 0 0 %.2F 58 %.2F cm /Sig Do Q\n", $draw_w, $draw_h, $y - $draw_h );
            $y -= $draw_h + 10;
        }

        $finish_page();

        $page_ids = [];
        $page_count = count( $pages );
        foreach ( $pages as $page_index => $stream ) {
            $footer_text = $footer;
            if ( '' !== $footer_text ) {
                $stream .= $text_command( $footer_text, 'F1', 7.8, 50, 30 );
            }
            $stream .= $text_command( 'Seite ' . ( $page_index + 1 ) . ' / ' . $page_count, 'F1', 7.8, 485, 30 );
            $stream .= "0.88 G 0.4 w 50 43 m 545 43 l S\n";

            $resource_xobject = $signature ? ' /XObject << /Sig ' . $signature_object_id . ' 0 R >>' : '';
            $content_id = $add_object( '<< /Length ' . strlen( $stream ) . ">>\nstream\n" . $stream . "endstream" );
            $page_id = $add_object(
                '<< /Type /Page /Parent ' . $pages_id . ' 0 R /MediaBox [0 0 595 842] ' .
                '/Resources << /Font << /F1 ' . $font_id . ' 0 R /F2 ' . $font_bold_id . ' 0 R >>' . $resource_xobject . ' >> ' .
                '/Contents ' . $content_id . ' 0 R >>'
            );
            $page_ids[] = $page_id;
        }

        $objects[ $pages_id - 1 ] = '<< /Type /Pages /Kids [' . implode( ' ', array_map( static fn( $id ) => $id . ' 0 R', $page_ids ) ) . '] /Count ' . count( $page_ids ) . ' >>';
        $objects[ $catalog_id - 1 ] = '<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [ 0 ];
        foreach ( $objects as $index => $object ) {
            $id = $index + 1;
            $offsets[ $id ] = strlen( $pdf );
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen( $pdf );
        $pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ( $id = 1; $id <= count( $objects ); $id++ ) {
            $pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
        }
        $pdf .= 'trailer' . "\n<< /Size " . ( count( $objects ) + 1 ) . ' /Root ' . $catalog_id . " 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    private static function decode_signature( $signature_jpeg ) {
        if ( ! is_string( $signature_jpeg ) || '' === $signature_jpeg ) {
            return null;
        }
        if ( ! preg_match( '#^data:image/jpeg;base64,([A-Za-z0-9+/=\r\n]+)$#', $signature_jpeg, $matches ) ) {
            return null;
        }
        $bytes = base64_decode( preg_replace( '/\s+/', '', $matches[1] ), true );
        if ( false === $bytes || strlen( $bytes ) > 225280 ) {
            return null;
        }
        $info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data is treated as no signature.
        if ( ! is_array( $info ) || IMAGETYPE_JPEG !== ( $info[2] ?? 0 ) ) {
            return null;
        }
        $width  = absint( $info[0] ?? 0 );
        $height = absint( $info[1] ?? 0 );
        if ( ! $width || ! $height || $width > 1400 || $height > 500 ) {
            return null;
        }
        return [ 'bytes' => $bytes, 'width' => $width, 'height' => $height ];
    }

    public static function html_to_lines( $html ) {
        $html = wp_kses_post( (string) $html );
        $html = preg_replace( '#<(br|/p|/div|/h[1-6]|/li|/tr)>#i', "$0\n", $html );
        $html = preg_replace( '#<li[^>]*>#i', '• ', $html );
        $text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( "/\r\n?|\x{2028}|\x{2029}/u", "\n", $text );
        $text = preg_replace( "/[\t ]+/u", ' ', $text );
        $raw  = preg_split( "/\n+/u", trim( (string) $text ) );
        $out  = [];
        foreach ( $raw as $line ) {
            $line = trim( $line );
            if ( '' !== $line ) {
                $out[] = $line;
            }
        }
        return $out;
    }

    private static function clean_line( $line ) {
        $line = html_entity_decode( wp_strip_all_tags( (string) $line ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $line = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $line );
        return trim( (string) $line );
    }

    private static function wrap_line( $line, $length ) {
        if ( '' === $line ) {
            return [ '' ];
        }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            $words = preg_split( '/\s+/u', $line ) ?: [];
            $rows = [];
            $current = '';
            foreach ( $words as $word ) {
                $candidate = '' === $current ? $word : $current . ' ' . $word;
                if ( mb_strlen( $candidate, 'UTF-8' ) > $length && '' !== $current ) {
                    $rows[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
            if ( '' !== $current ) {
                $rows[] = $current;
            }
            return $rows ?: [ '' ];
        }
        return explode( "\n", wordwrap( $line, $length, "\n", true ) );
    }

    private static function encode( $text ) {
        if ( function_exists( 'iconv' ) ) {
            $encoded = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
            if ( false !== $encoded ) {
                return $encoded;
            }
        }
        return preg_replace( '/[^\x20-\x7E]/', '?', $text );
    }

    private static function pdf_escape( $text ) {
        return str_replace( [ '\\', '(', ')', "\r", "\n" ], [ '\\\\', '\\(', '\\)', '', ' ' ], (string) $text );
    }
}
