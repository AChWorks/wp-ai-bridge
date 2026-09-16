#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

forbidden='(^|[^[:alnum:]_])(shell_exec|exec|system|passthru|proc_open|popen|eval|file_put_contents|fopen|fwrite|unlink|rename|copy|mkdir|rmdir)[[:space:]]*\('

if grep -R -nE "$forbidden" src --include='*.php' --exclude='class-media-abilities.php' --exclude='class-source-editing-abilities.php'; then
    echo "ERROR: forbidden direct execution/filesystem primitive found in production source." >&2
    exit 1
fi

media_write_count="$(grep -cF 'file_put_contents( $tmp_name, $bytes )' src/Abilities/class-media-abilities.php || true)"
media_temp_count="$(grep -cF 'wp_tempnam( $filename )' src/Abilities/class-media-abilities.php || true)"
media_base64_temp_count="$(grep -cF '$tmp_name = wp_tempnam( $filename );' src/Abilities/class-media-abilities.php || true)"
media_import_temp_count="$(grep -Ec '\$temp_file[[:space:]]*=[[:space:]]*wp_tempnam\([[:space:]]*\$filename[[:space:]]*\);' src/Abilities/class-media-abilities.php || true)"
media_all_write_count="$(grep -cF 'file_put_contents(' src/Abilities/class-media-abilities.php || true)"
if [[ "$media_write_count" != "1" || "$media_all_write_count" != "1" || "$media_temp_count" != "2" || "$media_base64_temp_count" != "1" || "$media_import_temp_count" != "1" ]]; then
    echo "ERROR: media upload filesystem exception no longer matches the single bounded WordPress temp-file write." >&2
    exit 1
fi

# URL media uses a second WordPress staging allocation, never another direct writer,
# an unchecked HTTP client, or a caller-controlled filesystem/command primitive.
media_forbidden='(^|[^[:alnum:]_])(shell_exec|exec|system|passthru|proc_open|popen|eval|fopen|fwrite|unlink|rename|copy|mkdir|rmdir|curl_exec|curl_init|fsockopen|stream_socket_client|file_get_contents|wp_remote_get|wp_remote_post|wp_remote_request)[[:space:]]*\('
if grep -nE "$media_forbidden" src/Abilities/class-media-abilities.php; then
    echo "ERROR: media import introduced an unbounded execution/filesystem/HTTP primitive." >&2
    exit 1
fi
if [[ "$(grep -cF 'wp_safe_remote_get(' src/Abilities/class-media-abilities.php || true)" != "1" ]]; then
    echo "ERROR: media import must retain exactly one safe WordPress HTTP request surface." >&2
    exit 1
fi

# Issue #67 permits one exact package_url schema seam only on extension-lifecycle.
# The implementation must retain one bounded safe-HTTP stream into one WordPress temp allocation;
# generic HTTP/filesystem primitives and package_url on any other Ability remain forbidden.
external_package_provider='src/Abilities/class-extension-abilities.php'
if [[ ! -f "$external_package_provider" ]]; then
    echo "ERROR: bounded external-package provider is missing." >&2
    exit 1
fi
package_url_schema_files="$(grep -R -lF "'package_url' => array(" src/Abilities --include='*.php' || true)"
if [[ "$package_url_schema_files" != "$external_package_provider" || "$(grep -cF "'package_url' => array(" "$external_package_provider" || true)" != "1" ]]; then
    printf '%s\n' "$package_url_schema_files"
    echo "ERROR: package_url must remain one exact extension-lifecycle schema field." >&2
    exit 1
fi
php bin/check-external-package-carrier-taint.php "$external_package_provider"
# Tokenize executable PHP syntax and fail closed on ordinary callable dispatch. Besides rejecting
# dynamic invocation syntax, protect forbidden callable names passed through any call/constructor,
# taint local variables derived from those names, and reject PHP internal callback-taking APIs by
# reflection. The only internal callback exception is the exact array_filter() seam pinned below.
external_package_identifier_inventory="$(
    php -r '
$source = file_get_contents( $argv[1] );
if ( false === $source ) {
    fwrite( STDERR, "ERROR: could not read external package provider.\n" );
    exit( 2 );
}
$name_tokens = array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE );
$ignored_tokens = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG );
$dynamic_callable_tokens = array( T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_END_HEREDOC );
$protected_callable_names = array_fill_keys(
    array(
        "shell_exec", "exec", "system", "passthru", "proc_open", "popen", "eval",
        "file_put_contents", "fopen", "fwrite", "unlink", "rename", "copy", "mkdir", "rmdir",
        "curl_exec", "curl_init", "fsockopen", "stream_socket_client", "file_get_contents", "wp_tempnam",
        "wp_remote_request", "wp_remote_get", "wp_remote_post", "wp_remote_head",
        "wp_safe_remote_request", "wp_safe_remote_get", "wp_safe_remote_post", "wp_safe_remote_head",
        "call_user_func", "call_user_func_array", "forward_static_call", "forward_static_call_array"
    ),
    true
);
$tokens = array();
foreach ( token_get_all( $source ) as $token ) {
    if ( is_array( $token ) && in_array( $token[0], $ignored_tokens, true ) ) {
        continue;
    }
    $tokens[] = $token;
}
$token_text = static function ( $token ) {
    return is_array( $token ) ? $token[1] : $token;
};
$normalize_name = static function ( $token ) {
    $parts = preg_split( "/\\\\+/", strtolower( is_array( $token ) ? $token[1] : (string) $token ) );
    return end( $parts );
};
$literal_value = static function ( $token ) {
    $text = $token[1];
    $value = substr( $text, 1, -1 );
    return 34 === ord( $text[0] ) ? stripcslashes( $value ) : str_replace( "\\\\", "\\", $value );
};
$slice_has_protected_callable = static function ( $slice ) use ( $protected_callable_names, $literal_value ) {
    $values = array();
    foreach ( $slice as $token ) {
        if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
            $values[] = strtolower( $literal_value( $token ) );
        }
    }
    $count = count( $values );
    for ( $i = 0; $i < $count; ++$i ) {
        $joined = "";
        for ( $j = $i; $j < $count && $j < $i + 4; ++$j ) {
            $joined .= $values[ $j ];
            if ( isset( $protected_callable_names[ $joined ] ) ) {
                return true;
            }
        }
    }
    return false;
};

// Taint local variables that are ever derived from a protected callable name. Taint is monotonic:
// later reuse cannot make a previously dangerous source silently acceptable to a callback surface.
$assignments = array();
$token_count = count( $tokens );
for ( $i = 0; $i < $token_count - 2; ++$i ) {
    if ( ! is_array( $tokens[ $i ] ) || T_VARIABLE !== $tokens[ $i ][0] || "=" !== $token_text( $tokens[ $i + 1 ] ) ) {
        continue;
    }
    $depth = array( "(" => 0, "[" => 0, "{" => 0 );
    $expression = array();
    for ( $j = $i + 2; $j < $token_count; ++$j ) {
        $part = $tokens[ $j ];
        $part_text = $token_text( $part );
        if ( ";" === $part_text && 0 === $depth["("] && 0 === $depth["["] && 0 === $depth["{"] ) {
            break;
        }
        if ( "(" === $part_text ) {
            ++$depth["("];
        } elseif ( ")" === $part_text ) {
            --$depth["("];
        } elseif ( "[" === $part_text ) {
            ++$depth["["];
        } elseif ( "]" === $part_text ) {
            --$depth["["];
        } elseif ( "{" === $part_text ) {
            ++$depth["{"];
        } elseif ( "}" === $part_text ) {
            --$depth["{"];
        }
        $expression[] = $part;
    }
    $assignments[] = array( $tokens[ $i ][1], $expression );
}
$tainted_variables = array();
do {
    $changed = false;
    foreach ( $assignments as $assignment ) {
        $variable = $assignment[0];
        $expression = $assignment[1];
        if ( isset( $tainted_variables[ $variable ] ) ) {
            continue;
        }
        $tainted = $slice_has_protected_callable( $expression );
        if ( ! $tainted ) {
            foreach ( $expression as $part ) {
                if ( is_array( $part ) && T_VARIABLE === $part[0] && isset( $tainted_variables[ $part[1] ] ) ) {
                    $tainted = true;
                    break;
                }
            }
        }
        if ( $tainted ) {
            $tainted_variables[ $variable ] = true;
            $changed = true;
        }
    }
} while ( $changed );

// PHP adds callback-taking APIs over time. Derive the global internal callback-function surface
// from the runtime signature. Constructors are class-qualified and safe to inventory by class;
// method names are receiver-dependent, so they are handled structurally below instead of globally.
$internal_callback_functions = array();
foreach ( get_defined_functions()["internal"] as $function_name ) {
    try {
        $reflection = new ReflectionFunction( $function_name );
    } catch ( Throwable $error ) {
        fwrite( STDERR, "ERROR: could not reflect PHP internal callback surface.\n" );
        exit( 4 );
    }
    foreach ( $reflection->getParameters() as $parameter ) {
        $type = $parameter->getType();
        if ( null !== $type && false !== stripos( (string) $type, "callable" ) ) {
            $internal_callback_functions[ strtolower( $function_name ) ] = true;
            break;
        }
    }
}
$internal_callback_constructors = array();
foreach ( get_declared_classes() as $class_name ) {
    try {
        $class = new ReflectionClass( $class_name );
    } catch ( Throwable $error ) {
        fwrite( STDERR, "ERROR: could not reflect PHP internal callback class surface.\n" );
        exit( 5 );
    }
    if ( ! $class->isInternal() ) {
        continue;
    }
    $constructor = $class->getConstructor();
    if ( null === $constructor ) {
        continue;
    }
    foreach ( $constructor->getParameters() as $parameter ) {
        $type = $parameter->getType();
        if ( null !== $type && false !== stripos( (string) $type, "callable" ) ) {
            $parts = preg_split( "/\\\\+/", strtolower( $class_name ) );
            $internal_callback_constructors[ end( $parts ) ] = true;
            break;
        }
    }
}

$parentheses = array();
for ( $i = 0; $i < $token_count; ++$i ) {
    $token = $tokens[ $i ];
    $text = $token_text( $token );
    if ( "(" === $text ) {
        $previous = $i > 0 ? $tokens[ $i - 1 ] : null;
        $before_previous = $i > 1 ? $tokens[ $i - 2 ] : null;
        $receiver = $i > 2 ? $tokens[ $i - 3 ] : null;
        $dynamic_callable = is_array( $previous )
            ? in_array( $previous[0], $dynamic_callable_tokens, true )
            : in_array( $previous, array( ")", "]", "}", "\"" ), true );
        if ( $dynamic_callable ) {
            fwrite( STDERR, "ERROR: external package provider must not use dynamic function/callable invocation.\n" );
            exit( 3 );
        }
        $is_call = false;
        $call_name = "";
        if ( is_array( $previous ) && in_array( $previous[0], $name_tokens, true ) ) {
            $before_id = is_array( $before_previous ) ? $before_previous[0] : null;
            if ( T_FUNCTION !== $before_id ) {
                $is_call = true;
                $call_name = $normalize_name( $previous );
                if ( T_NEW === $before_id ) {
                    if ( isset( $internal_callback_constructors[ $call_name ] ) ) {
                        fwrite( STDERR, "ERROR: external package provider must not construct a PHP internal callback dispatcher.\n" );
                        exit( 6 );
                    }
                } elseif ( T_OBJECT_OPERATOR === $before_id || ( defined( "T_NULLSAFE_OBJECT_OPERATOR" ) && T_NULLSAFE_OBJECT_OPERATOR === $before_id ) || T_DOUBLE_COLON === $before_id ) {
                    $receiver_name = is_array( $receiver ) && in_array( $receiver[0], $name_tokens, true ) ? $normalize_name( $receiver ) : "";
                    if ( "__invoke" === $call_name || ( T_DOUBLE_COLON === $before_id && "fromcallable" === $call_name && "closure" === $receiver_name ) ) {
                        fwrite( STDERR, "ERROR: external package provider must not use callable conversion/invocation methods.\n" );
                        exit( 7 );
                    }
                } else {
                    if ( isset( $internal_callback_functions[ $call_name ] ) && "array_filter" !== $call_name ) {
                        fwrite( STDERR, "ERROR: external package provider must not use PHP internal callback-dispatch functions.\n" );
                        exit( 8 );
                    }
                    if ( preg_match( "/^array_u?(diff|intersect)(_|$)/", $call_name ) ) {
                        fwrite( STDERR, "ERROR: external package provider must not use array comparison callback families.\n" );
                        exit( 9 );
                    }
                }
            }
        }
        $parentheses[] = array( "start" => $i, "call" => $is_call, "name" => $call_name );
    } elseif ( ")" === $text && ! empty( $parentheses ) ) {
        $frame = array_pop( $parentheses );
        if ( $frame["call"] ) {
            $arguments = array_slice( $tokens, $frame["start"] + 1, $i - $frame["start"] - 1 );
            $allowed_temp_helper_probe = "function_exists" === $frame["name"]
                && 1 === count( $arguments )
                && is_array( $arguments[0] )
                && T_CONSTANT_ENCAPSED_STRING === $arguments[0][0]
                && "wp_tempnam" === strtolower( $literal_value( $arguments[0] ) );
            if ( ! $allowed_temp_helper_probe && $slice_has_protected_callable( $arguments ) ) {
                fwrite( STDERR, "ERROR: external package provider must not pass protected callable names through call arguments.\n" );
                exit( 10 );
            }
            foreach ( $arguments as $argument ) {
                if ( is_array( $argument ) && T_VARIABLE === $argument[0] && isset( $tainted_variables[ $argument[1] ] ) ) {
                    fwrite( STDERR, "ERROR: external package provider must not pass protected callable-derived variables through call arguments.\n" );
                    exit( 11 );
                }
            }
        }
    }
    if ( is_array( $token ) ) {
        if ( T_EVAL === $token[0] ) {
            echo "eval\n";
        } elseif ( in_array( $token[0], $name_tokens, true ) ) {
            echo $normalize_name( $token ), "\n";
        }
    }
}
' "$external_package_provider"
)"
external_package_http_helper_pattern='^wp_(safe_)?remote_(request|get|post|head)$'
external_package_safe_get_pattern='^wp_safe_remote_get$'
external_package_http_helper_count="$(printf '%s\n' "$external_package_identifier_inventory" | grep -Ec "$external_package_http_helper_pattern" || true)"
external_package_safe_get_count="$(printf '%s\n' "$external_package_identifier_inventory" | grep -Ec "$external_package_safe_get_pattern" || true)"
if [[ "$external_package_http_helper_count" != "1" || "$external_package_safe_get_count" != "1" || "$(grep -cF "wp_tempnam( 'wp-ai-bridge-package.zip' )" "$external_package_provider" || true)" != "1" ]]; then
    printf '%s\n' "$external_package_identifier_inventory" | grep -E "$external_package_http_helper_pattern" || true
    echo "ERROR: external package installation must retain exactly one wp_safe_remote_get() request and one WordPress temp allocation." >&2
    exit 1
fi
external_package_forbidden='^(shell_exec|exec|system|passthru|proc_open|popen|eval|file_put_contents|fopen|fwrite|unlink|rename|copy|mkdir|rmdir|curl_exec|curl_init|fsockopen|stream_socket_client|file_get_contents|wp_remote_get|wp_remote_post|wp_remote_request|wp_remote_head|call_user_func|call_user_func_array|forward_static_call|forward_static_call_array|array_map|array_reduce|array_walk|array_walk_recursive|array_udiff|array_udiff_assoc|array_udiff_uassoc|array_uintersect|array_uintersect_assoc|array_uintersect_uassoc|array_diff_uassoc|array_intersect_uassoc|usort|uasort|uksort|preg_replace_callback|preg_replace_callback_array|iterator_apply|register_shutdown_function|register_tick_function|set_error_handler|set_exception_handler|spl_autoload_register|header_register_callback|ob_start|session_set_save_handler|pcntl_signal|add_filter)$'
if printf '%s\n' "$external_package_identifier_inventory" | grep -E "$external_package_forbidden"; then
    echo "ERROR: external package installation introduced an unbounded execution/filesystem/HTTP/callback primitive." >&2
    exit 1
fi

# The provider has three legitimate callback-bearing surfaces. Pin their exact current shape so
# they cannot become an indirect forbidden-primitive dispatcher while the lexical inventory passes.
external_package_array_filter_count="$(printf '%s\n' "$external_package_identifier_inventory" | grep -Ec '^array_filter$' || true)"
external_package_add_action_count="$(printf '%s\n' "$external_package_identifier_inventory" | grep -Ec '^add_action$' || true)"
external_package_register_ability_count="$(printf '%s\n' "$external_package_identifier_inventory" | grep -Ec '^wp_register_ability$' || true)"
external_package_redirect_guard_assignment_count="$(grep -Ec '\$redirect_guard[[:space:]]*=[[:space:]]*function[[:space:]]*\(' "$external_package_provider" || true)"
external_package_redirect_guard_all_assignment_count="$(grep -Ec '\$redirect_guard[[:space:]]*=' "$external_package_provider" || true)"
external_package_execute_callback_count="$(grep -cF "'execute_callback'" "$external_package_provider" || true)"
external_package_permission_callback_count="$(grep -cF "'permission_callback'" "$external_package_provider" || true)"
if [[ "$external_package_array_filter_count" != "1" || "$(grep -cF "array_filter( \$registered, 'is_object' )" "$external_package_provider" || true)" != "1" || "$external_package_add_action_count" != "1" || "$(grep -cF "add_action( 'requests-requests.before_redirect', \$redirect_guard, PHP_INT_MAX, 4 );" "$external_package_provider" || true)" != "1" || "$external_package_redirect_guard_assignment_count" != "1" || "$external_package_redirect_guard_all_assignment_count" != "1" || "$external_package_register_ability_count" != "2" || "$external_package_execute_callback_count" != "2" || "$external_package_permission_callback_count" != "2" || "$(grep -cF "'execute_callback'    => array( \$this, 'read' )," "$external_package_provider" || true)" != "1" || "$(grep -cF "'execute_callback'    => array( \$this, 'mutate' )," "$external_package_provider" || true)" != "1" || "$(grep -cF "'permission_callback' => array( \$this, 'can_read' )," "$external_package_provider" || true)" != "1" || "$(grep -cF "'permission_callback' => array( \$this, 'can_mutate' )," "$external_package_provider" || true)" != "1" ]]; then
    echo "ERROR: external package provider callback-bearing surfaces changed outside their fixed direct-call contract." >&2
    exit 1
fi

# Issue #46 source editing is a fixed-purpose installed-extension lifecycle, not a generic filesystem proxy.
source_editor='src/Abilities/class-source-editing-abilities.php'
if [[ ! -f "$source_editor" ]]; then
    echo "ERROR: bounded source-editing provider is missing." >&2
    exit 1
fi
if [[ "$(grep -cF 'new \WP_Filesystem_Direct( null )' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one direct WordPress filesystem constructor." >&2
    exit 1
fi
if [[ "$(grep -cF 'new \SplFileObject(' "$source_editor" || true)" != "2" ]]; then
    echo "ERROR: source editing must retain exactly the stable coordination lock plus the exact target-inode lock." >&2
    exit 1
fi
if [[ "$(grep -cF "new \\SplFileObject( \$target['canonical_path'], 'rb' )" "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one read-only advisory lock on the confined target inode." >&2
    exit 1
fi
if [[ "$(grep -cF 'new \SplFileObject( $this->coordination_lock_path( $canonical_path )' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one stable path-keyed coordination lock." >&2
    exit 1
fi
if [[ "$(grep -cF -- '->flock( LOCK_EX | LOCK_NB )' "$source_editor" || true)" != "2" || "$(grep -cF -- '->flock( LOCK_UN )' "$source_editor" || true)" != "2" ]]; then
    echo "ERROR: source editing stable/path-inode advisory lock counts changed unexpectedly." >&2
    exit 1
fi
if [[ "$(grep -cF 'wp_remote_get(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing runtime validation must retain one bounded Core-compatible loopback request call site." >&2
    exit 1
fi
if [[ "$(grep -cF "wp_is_file_mod_allowed( 'wp_ai_bridge_source_editing' )" "$source_editor" || true)" != "2" ]]; then
    echo "ERROR: source editing must recheck WordPress file-modification policy for normal execution and recovery." >&2
    exit 1
fi
if grep -nE "['\"](server_path|file_path|absolute_path|filesystem_path|ftp_password|ssh_password|private_key)['\"][[:space:]]*=>" "$source_editor"; then
    echo "ERROR: source editing exposed a caller-selected server path or filesystem credential field." >&2
    exit 1
fi
# F-001 remediation permits only the exact fixed-purpose path-CAS primitives below. They stage
# complete bytes privately, quarantine the current pathname, and publish with hard-link no-replace
# semantics so a non-cooperating writer that recreates the live path is never overwritten.
if grep -nE '(^|[^[:alnum:]_])(shell_exec|exec|system|passthru|proc_open|popen|eval|file_put_contents|copy|mkdir|rmdir|symlink)[[:space:]]*\(' "$source_editor"; then
    echo "ERROR: source editing grew an execution/filesystem primitive outside its fixed guarded replacement surface." >&2
    exit 1
fi
if [[ "$(grep -cF '@fopen(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one exclusive private stage-file open." >&2
    exit 1
fi
if [[ "$(grep -cF '@fwrite(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one bounded stage-file write loop." >&2
    exit 1
fi
if [[ "$(grep -cF '@rename(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must retain exactly one live-path-to-quarantine rename boundary." >&2
    exit 1
fi
if [[ "$(grep -cF '@link(' "$source_editor" || true)" != "3" ]]; then
    echo "ERROR: source editing must retain exactly three no-replace hard-link call sites (probe, publish, restore)." >&2
    exit 1
fi
if [[ "$(grep -cF '@unlink(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing guarded-replacement cleanup surface changed unexpectedly." >&2
    exit 1
fi
if [[ "$(grep -cF '@chmod(' "$source_editor" || true)" != "2" || "$(grep -cF '@chown(' "$source_editor" || true)" != "1" || "$(grep -cF '@chgrp(' "$source_editor" || true)" != "1" ]]; then
    echo "ERROR: source editing must preserve mode/owner/group only through the single staged replacement metadata path." >&2
    exit 1
fi

# Issues #34/#36/#58 need exact-row compare-and-swap against fixed metadata tables. Issue #72
# additionally needs one temporary, fixed-purpose identity-migration store over Core options/posts/postmeta.
# Direct database use remains forbidden outside these explicitly confined persistence surfaces.
metadata_store='src/Support/class-post-meta-store.php'
term_metadata_store='src/Support/class-term-meta-store.php'
user_comment_metadata_store='src/Support/class-user-comment-meta-store.php'
identity_migration_store='src/Support/class-identity-migration.php'
if [[ ! -f "$metadata_store" || ! -f "$term_metadata_store" || ! -f "$user_comment_metadata_store" || ! -f "$identity_migration_store" ]]; then
    echo "ERROR: one or more bounded database persistence surfaces are missing." >&2
    exit 1
fi
unexpected_db_files="$(grep -R -lF '$wpdb' src --include='*.php' | grep -vFx "$metadata_store" | grep -vFx "$term_metadata_store" | grep -vFx "$user_comment_metadata_store" | grep -vFx "$identity_migration_store" || true)"
if [[ -n "$unexpected_db_files" ]]; then
    printf '%s\n' "$unexpected_db_files"
    echo "ERROR: direct database access found outside the bounded metadata/migration stores." >&2
    exit 1
fi

# Issue #72 migration SQL is temporary and fixed-purpose. It may touch only the Core options,
# posts, and postmeta tables, may not consume request-selected SQL/table/column/query material,
# and may use only the enumerated database primitives required for exact identity migration.
if grep -nE '\$_(GET|POST|REQUEST|COOKIE|FILES|SERVER)' "$identity_migration_store"; then
    echo "ERROR: identity migration must not consume request-selected input." >&2
    exit 1
fi
if grep -nE '\$wpdb->[A-Za-z_][A-Za-z0-9_]*' "$identity_migration_store" | grep -vE '\$wpdb->(options|posts|postmeta|get_row|get_results|get_col|get_var|insert|update|delete|query|prepare|esc_like)([^A-Za-z0-9_]|$)'; then
    echo "ERROR: identity migration uses a database member outside its fixed Core identity-migration surface." >&2
    exit 1
fi
if grep -nE 'function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\([^)]*\$(sql|table|column|query|where)([^A-Za-z0-9_]|$)' "$identity_migration_store"; then
    echo "ERROR: identity migration must not accept SQL/table/column/query inputs." >&2
    exit 1
fi
if grep -nE '\$wpdb->(users|usermeta|comments|commentmeta|terms|termmeta|term_taxonomy|term_relationships|links)([^A-Za-z0-9_]|$)' "$identity_migration_store"; then
    echo "ERROR: identity migration escaped its options/posts/postmeta table boundary." >&2
    exit 1
fi
if grep -nE '(CREATE|ALTER|DROP|TRUNCATE)[[:space:]]+(TABLE|DATABASE)|RENAME[[:space:]]+TABLE' "$identity_migration_store"; then
    echo "ERROR: identity migration must never mutate database schema or physical table identity." >&2
    exit 1
fi
if [[ "$(grep -cF "'wp_native_builder_bridge_settings'" "$identity_migration_store" || true)" -lt "1" || "$(grep -cF "'wp_ai_bridge_settings'" "$identity_migration_store" || true)" -lt "1" || "$(grep -cF "'wpnb_doc'" "$identity_migration_store" || true)" -lt "1" || "$(grep -cF "'wpai_doc'" "$identity_migration_store" || true)" -lt "1" ]]; then
    echo "ERROR: identity migration lost its explicit old-to-new storage boundary." >&2
    exit 1
fi

if [[ -f "$metadata_store" ]]; then
    if grep -nE '\$wpdb->(get_[A-Za-z0-9_]*|replace|esc_like)([^A-Za-z0-9_]|$)' "$metadata_store"; then
        echo "ERROR: post-meta store grew a database read/generic primitive outside its fixed physical-row/CAS design." >&2
        exit 1
    fi
    if grep -nE '\$wpdb->[A-Za-z_][A-Za-z0-9_]*' "$metadata_store" | grep -vE '\$wpdb->(postmeta|update|delete|insert|prepare|query)([^A-Za-z0-9_]|$)'; then
        echo "ERROR: post-meta store uses a database member outside its fixed postmeta persistence surface." >&2
        exit 1
    fi
    if grep -nE 'function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\([^)]*\$(sql|table|column|query|where)([^A-Za-z0-9_]|$)' "$metadata_store"; then
        echo "ERROR: post-meta store must not accept caller-selected SQL/table/column/query inputs." >&2
        exit 1
    fi
    if grep -nE '\$(sql|prepared_sql|set_sql|raw_sql)[[:space:]]*=' "$metadata_store"; then
        echo "ERROR: post-meta raw CAS SQL must remain complete literal prepared templates, not assembled SQL fragments." >&2
        exit 1
    fi

    prepare_count="$(grep -cF '$wpdb->prepare(' "$metadata_store" || true)"
    query_count="$(grep -cF '$wpdb->query(' "$metadata_store" || true)"
    binary_key_count="$(grep -cF 'CAST(meta_key AS BINARY) = CAST(%s AS BINARY)' "$metadata_store" || true)"
    binary_value_count="$(grep -cF 'CAST(meta_value AS BINARY) = CAST(%s AS BINARY)' "$metadata_store" || true)"
    null_value_count="$(grep -cF 'meta_value IS NULL' "$metadata_store" || true)"
    set_null_count="$(grep -cF 'SET meta_value = NULL' "$metadata_store" || true)"
    set_string_count="$(grep -cF 'SET meta_value = %s' "$metadata_store" || true)"
    if [[ "$prepare_count" != "6" || "$query_count" != "6" || "$binary_key_count" != "6" || "$binary_value_count" != "3" || "$null_value_count" != "3" || "$set_null_count" != "2" || "$set_string_count" != "2" ]]; then
        echo "ERROR: post-meta raw SQL must remain exactly the six fixed byte-exact update/delete CAS branches with explicit NULL handling." >&2
        exit 1
    fi
fi

# Issue #36 writes only termmeta; native term tables appear only in identity joins. Do not relax the
# postmeta assertions above: the shipped postmeta CAS surface remains unchanged.
if grep -nE '\$wpdb->[A-Za-z_][A-Za-z0-9_]*' "$term_metadata_store" | grep -vE '\$wpdb->(termmeta|term_taxonomy|terms|last_error|insert_id|get_results|get_var|prepare|query)([^A-Za-z0-9_]|$)'; then
    echo "ERROR: term-meta store exceeded its fixed termmeta persistence surface." >&2
    exit 1
fi
if grep -nE 'function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*[[:space:]]*\([^)]*\$(sql|table|column|query|where)([^A-Za-z0-9_]|$)|\$(sql|prepared_sql|set_sql|raw_sql)[[:space:]]*=' "$term_metadata_store"; then
    echo "ERROR: term-meta store must not accept or assemble SQL fragments." >&2
    exit 1
fi
term_prepare_count="$(grep -cF '$wpdb->prepare(' "$term_metadata_store" || true)"
term_query_count="$(grep -cF '$wpdb->query(' "$term_metadata_store" || true)"
term_read_count="$(grep -cF '$wpdb->get_results(' "$term_metadata_store" || true)"
term_unique_read_count="$(grep -cF '$wpdb->get_var(' "$term_metadata_store" || true)"
term_binary_key_count="$(grep -cF 'CAST(m.meta_key AS BINARY) = CAST(%s AS BINARY)' "$term_metadata_store" || true)"
term_binary_value_count="$(grep -cF 'CAST(m.meta_value AS BINARY) = CAST(%s AS BINARY)' "$term_metadata_store" || true)"
term_null_count="$(grep -cF 'meta_value IS NULL' "$term_metadata_store" || true)"
term_set_null_count="$(grep -cF 'SET m.meta_value = NULL' "$term_metadata_store" || true)"
term_set_string_count="$(grep -cF 'SET m.meta_value = %s' "$term_metadata_store" || true)"
if [[ "$term_prepare_count" != "11" || "$term_query_count" != "8" || "$term_read_count" != "2" || "$term_unique_read_count" != "1" || "$term_binary_key_count" != "6" || "$term_binary_value_count" != "3" || "$term_null_count" != "3" || "$term_set_null_count" != "2" || "$term_set_string_count" != "2" ]]; then
    echo "ERROR: term-meta persistence must retain two bounded reads, one native uniqueness check, six identity-bound CAS branches, and two conditional insert branches." >&2
    exit 1
fi
if [[ "$(grep -cF 'SELECT meta_id, term_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2' "$term_metadata_store" || true)" != "1" || "$(grep -cF 'SELECT MIN(meta_key) AS meta_key, COUNT(*) AS row_count FROM %i WHERE term_id = %d AND meta_key IS NOT NULL GROUP BY CAST(meta_key AS BINARY) ORDER BY CAST(meta_key AS BINARY) LIMIT %d OFFSET %d' "$term_metadata_store" || true)" != "1" ]]; then
    echo "ERROR: term-meta physical reads lost their fixed bounds or value-free list contract." >&2
    exit 1
fi
if grep -nE '(get|add|update|delete)_(option|user_meta|post_meta)[[:space:]]*\(' src/Abilities/class-term-meta-abilities.php "$term_metadata_store"; then
    echo "ERROR: term metadata must not grow a separate options/user/post metadata surface." >&2
    exit 1
fi

# These names are forbidden in AI-exposed Ability schemas. OAuth protocol responses
# legitimately use access_token, but no OAuth bearer material may become an Ability input.
if grep -R -nE "['\"](server_path|file_path|shell_command|sql_query|application_password|session_token|access_token|refresh_token|authorization_code|api_secret)['\"][[:space:]]*=>" src/Abilities --include='*.php'; then
    echo "ERROR: forbidden generic path/command/secret schema field found in an exposed Ability." >&2
    exit 1
fi

# Issue #34 deliberately adds generic post_meta access, not a generic WordPress data-store
# backdoor. Keep options/user-meta APIs out of that provider so future edits cannot silently
# expand its authority without an explicit architectural change.
metadata_provider='src/Abilities/class-post-meta-abilities.php'
if [[ -f "$metadata_provider" ]]; then
    if grep -nE '(^|[^[:alnum:]_])(get|add|update|delete)_option[[:space:]]*\(' "$metadata_provider"; then
        echo "ERROR: Advanced Metadata provider must not expose generic WordPress options." >&2
        exit 1
    fi
    if grep -nE '(^|[^[:alnum:]_])(get|add|update|delete)_user_meta[[:space:]]*\(' "$metadata_provider"; then
        echo "ERROR: Advanced Metadata provider must not expose generic user metadata." >&2
        exit 1
    fi
fi

# The consent form posts to the same WordPress origin, then redirects to ChatGPT's
# fixed OAuth callback. Chromium applies form-action across that redirect chain,
# so the callback origin must remain explicitly allowed without broadening the CSP.
oauth_consent_csp_count="$(grep -cF "form-action 'self' https://chatgpt.com" src/Auth/class-oauth-server.php || true)"
if [[ "$oauth_consent_csp_count" != "1" ]]; then
    echo "ERROR: OAuth consent CSP must explicitly allow the fixed ChatGPT callback origin exactly once." >&2
    exit 1
fi

if grep -Fq "form-action 'self';" src/Auth/class-oauth-server.php; then
    echo "ERROR: OAuth consent CSP regressed to same-origin-only form navigation and can block the ChatGPT callback redirect." >&2
    exit 1
fi

php bin/check-term-meta-confinement.php
php bin/check-user-comment-meta-confinement.php

echo "PASS: static safety surface audit."
