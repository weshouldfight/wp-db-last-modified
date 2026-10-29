<?php

/*
Plugin Name: Last Modified
Description: Logs changes to content, media, menus, users and settings and lists them in the dashboard
Version:     2.1.1
Author:      Christoph Mandl
Author URI:  https://www.studioh8.de
Update URI:  false
*/


// What WordPress and plugins write on their own, without anyone changing content

const DBLM_SKIP_TYPES   = array( 'revision', 'oembed_cache', 'customize_changeset', 'nav_menu_item', 'acf-field' );
const DBLM_SKIP_META    = '/^_(edit_lock|edit_last|encloseme|pingme|wp_old_slug|wp_old_date|wp_desired_post_slug|wp_trash_meta_\w+|yoast_wpseo_(content_score|linkdex|estimated-reading-time-minutes|wordproof_timestamp))$/';
const DBLM_SKIP_OPTIONS = '/^_(site_)?transient_|^(cron|rewrite_rules|user_count|active_plugins|recently_activated|uninstall_plugins|template|stylesheet|current_theme|theme_switched|nav_menu_options|recently_edited|can_compress_scripts|db_upgraded|https_detection_errors|recovery_keys|recovery_mode_email_last_sent|admin_email_lifespan|auto_core_update_notified|dblm_since)$|^action_scheduler_|_children$|\.lock$|_lock_/';

// Ordered by weight: if one request does several things to the same object, the heaviest wins

const DBLM_ACTIONS = array(
	'updated'     => 'bearbeitet',
	'published'   => 'veröffentlicht',
	'restored'    => 'wiederhergestellt',
	'trashed'     => 'in den Papierkorb gelegt',
	'created'     => 'angelegt',
	'deleted'     => 'gelöscht',
	'activated'   => 'aktiviert',
	'deactivated' => 'deaktiviert',
);


// Log table, also created when the plugin is copied over an active older version

function dblm_table() {
	global $wpdb;
	return $wpdb->prefix . 'dblm_log';
}

add_action( 'plugins_loaded', 'dblm_install' );

function dblm_install() {
	global $wpdb;
	if ( get_option( 'dblm_since' ) ) {
		return;
	}
	$created = $wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . dblm_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		logged_at datetime NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_name varchar(250) NOT NULL DEFAULT '',
		object_type varchar(20) NOT NULL,
		object_subtype varchar(100) NOT NULL DEFAULT '',
		object_id bigint(20) unsigned NOT NULL DEFAULT 0,
		object_label varchar(100) NOT NULL DEFAULT '',
		object_name text NOT NULL,
		action varchar(20) NOT NULL,
		details text NOT NULL,
		PRIMARY KEY (id),
		KEY logged_at (logged_at),
		KEY object (object_type, object_id)
	) " . $wpdb->get_charset_collate() );
	if ( false !== $created ) {
		add_option( 'dblm_since', time() );
	}
}

register_uninstall_hook( __FILE__, 'dblm_uninstall' );

function dblm_uninstall() {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . dblm_table() );
	delete_option( 'dblm_since' );
}


// Collect the changes of one request, one entry per object, and write them at shutdown

function dblm_note( $type, $id, $name, $action, $detail = '', $subtype = '', $label = '' ) {
	global $dblm_changes;
	$key = "$type:$subtype:$id";
	if ( empty( $dblm_changes[ $key ] ) ) {
		$dblm_changes[ $key ] = array( 'action' => $action, 'details' => array() );
		add_action( 'shutdown', 'dblm_save' );
	}
	$change  = &$dblm_changes[ $key ];
	$weights = array_keys( DBLM_ACTIONS );
	if ( array_search( $action, $weights, true ) > array_search( $change['action'], $weights, true ) ) {
		$change['action'] = $action;
	}
	$change = array_merge( $change, compact( 'type', 'id', 'name', 'subtype', 'label' ) );
	if ( $detail ) {
		$change['details'][ $detail ] = true;
	}
}

function dblm_save() {
	global $wpdb, $dblm_changes;
	$user  = wp_get_current_user();
	$who   = $user->exists() ? $user->display_name : ( defined( 'WP_CLI' ) && WP_CLI ? 'WP-CLI' : ( wp_doing_cron() ? 'automatisch' : 'ohne Anmeldung' ) );
	$now   = current_time( 'mysql', true );
	$table = dblm_table();
	foreach ( (array) $dblm_changes as $c ) {
		// New and deleted things need no list of fields, only the status or role a new one starts with
		$details = array_keys( $c['details'] );
		if ( 'deleted' === $c['action'] ) {
			$details = array();
		} elseif ( 'created' === $c['action'] ) {
			$details = preg_grep( '/^(Status|Rolle): /', $details );
		}
		$last   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE object_type = %s AND object_id = %d AND object_subtype = %s ORDER BY id DESC LIMIT 1", $c['type'], $c['id'], $c['subtype'] ) );

		// Saving the same thing again within a few minutes (autosave, several clicks on "Aktualisieren") extends the last entry
		if ( $last && 'updated' === $c['action'] && 'updated' === $last->action && $last->user_name === $who && strtotime( $last->logged_at ) > time() - 15 * MINUTE_IN_SECONDS ) {
			$wpdb->update( $table, array(
				'logged_at'   => $now,
				'object_name' => $c['name'],
				'details'     => implode( ', ', array_unique( array_filter( array_merge( explode( ', ', $last->details ), $details ) ) ) ),
			), array( 'id' => $last->id ) );
		} else {
			$wpdb->insert( $table, array(
				'logged_at'      => $now,
				'user_id'        => $user->ID,
				'user_name'      => $who,
				'object_type'    => $c['type'],
				'object_subtype' => $c['subtype'],
				'object_id'      => $c['id'],
				'object_label'   => $c['label'],
				'object_name'    => $c['name'],
				'action'         => $c['action'],
				'details'        => implode( ', ', $details ),
			) );
		}
	}
	$dblm_changes = array();
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE logged_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) ) ) );
}


// Posts of all types, media, custom fields

function dblm_note_post( $post, $action, $detail = '' ) {
	$post = $post ? get_post( $post ) : null;
	if ( ! $post || 'auto-draft' === $post->post_status || in_array( $post->post_type, DBLM_SKIP_TYPES, true ) ) {
		return;
	}
	$type = get_post_type_object( $post->post_type );
	dblm_note( 'post', $post->ID, $post->post_title, $action, $detail, $post->post_type, $type ? $type->labels->singular_name : $post->post_type );
}

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'new' === $old || 'auto-draft' === $old ) {
		$action = 'created';
	} elseif ( 'trash' === $new || 'trash' === $old ) {
		$action = 'trash' === $new ? 'trashed' : 'restored';
	} else {
		$action = 'publish' === $new && 'publish' !== $old ? 'published' : 'updated';
	}
	$status = get_post_status_object( $new );
	dblm_note_post( $post, $action, $old !== $new && ! in_array( $new, array( 'publish', 'trash' ), true ) && $status ? 'Status: ' . $status->label : '' );
}, 10, 3 );

add_action( 'add_attachment', function ( $id ) {
	dblm_note_post( $id, 'created' );
} );

add_action( 'edit_attachment', function ( $id ) {
	dblm_note_post( $id, 'updated' );
} );

add_action( 'delete_post', function ( $id, $post ) {
	dblm_note_post( $post, 'deleted' );
}, 10, 2 );

foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
	add_action( $hook, function ( $meta_id, $post_id, $key, $value ) {
		// ACF keeps a "_name" => "field_…" reference next to every value, the value alone is enough
		if ( preg_match( DBLM_SKIP_META, $key ) || ( '_' === $key[0] && is_string( $value ) && 0 === strpos( $value, 'field_' ) ) ) {
			return;
		}
		dblm_note_post( $post_id, 'updated', dblm_field_label( $post_id, $key ) );
	}, 10, 4 );
}

// Names a field after its ACF label, repeater rows after the repeater, otherwise after its key

function dblm_field_label( $post_id, $key ) {
	$labels = array(
		'_thumbnail_id'               => 'Beitragsbild',
		'_wp_page_template'           => 'Template',
		'_wp_attached_file'           => 'Datei',
		'_wp_attachment_metadata'     => 'Bilddaten',
		'_wp_attachment_backup_sizes' => 'Bilddaten',
		'_wp_attachment_image_alt'    => 'Alternativtext',
		'_yoast_wpseo_title'          => 'SEO-Titel',
		'_yoast_wpseo_metadesc'       => 'Meta-Beschreibung',
	);
	if ( isset( $labels[ $key ] ) ) {
		return $labels[ $key ];
	}
	$ref   = function_exists( 'acf_get_field' ) ? get_post_meta( $post_id, '_' . preg_replace( '/_\d+_.*/', '', $key ), true ) : '';
	$field = $ref ? acf_get_field( $ref ) : false;
	return $field ? $field['label'] : $key;
}


// Categories, tags, menus

function dblm_note_term( $term, $taxonomy, $action ) {
	$term = get_term( $term, $taxonomy );
	$tax  = get_taxonomy( $taxonomy );
	if ( $term instanceof WP_Term && $tax ) {
		dblm_note( 'term', $term->term_id, $term->name, $action, '', $taxonomy, $tax->labels->singular_name );
	}
}

add_action( 'created_term', function ( $id, $tt_id, $taxonomy ) {
	dblm_note_term( $id, $taxonomy, 'created' );
}, 10, 3 );

add_action( 'edited_term', function ( $id, $tt_id, $taxonomy ) {
	dblm_note_term( $id, $taxonomy, 'updated' );
}, 10, 3 );

add_action( 'delete_term', function ( $id, $tt_id, $taxonomy, $term ) {
	dblm_note_term( $term, $taxonomy, 'deleted' );
}, 10, 4 );

// Menu items are posts, but the change belongs to the menu

add_action( 'wp_update_nav_menu_item', function ( $menu_id ) {
	dblm_note_term( $menu_id, 'nav_menu', 'updated' );
} );

add_action( 'set_object_terms', function ( $id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	$tax = get_taxonomy( $taxonomy );
	if ( $tax && ( array_diff( $tt_ids, $old_tt_ids ) || ( ! $append && array_diff( $old_tt_ids, $tt_ids ) ) ) ) {
		dblm_note_post( $id, 'updated', $tax->labels->name );
	}
}, 10, 6 );


// Comments

function dblm_note_comment( $comment, $action, $detail = '' ) {
	$comment = get_comment( $comment );
	if ( $comment ) {
		dblm_note( 'comment', $comment->comment_ID, sprintf( '%s zu „%s“', $comment->comment_author, get_the_title( $comment->comment_post_ID ) ), $action, $detail, '', 'Kommentar' );
	}
}

add_action( 'wp_insert_comment', function ( $id ) {
	dblm_note_comment( $id, 'created' );
} );

add_action( 'edit_comment', function ( $id ) {
	dblm_note_comment( $id, 'updated' );
} );

add_action( 'transition_comment_status', function ( $new, $old, $comment ) {
	$labels = array( 'approved' => 'freigegeben', 'unapproved' => 'ausstehend', 'spam' => 'Spam', 'trash' => 'Papierkorb' );
	dblm_note_comment( $comment, 'updated', 'Status: ' . ( $labels[ $new ] ?? $new ) );
}, 10, 3 );

add_action( 'delete_comment', function ( $id ) {
	dblm_note_comment( $id, 'deleted' );
} );


// Accounts

function dblm_note_user( $id, $action, $detail = '' ) {
	$user = get_userdata( $id );
	if ( $user ) {
		dblm_note( 'user', $user->ID, $user->display_name, $action, $detail, '', 'Konto' );
	}
}

add_action( 'user_register', function ( $id ) {
	dblm_note_user( $id, 'created' );
} );

add_action( 'profile_update', function ( $id, $old ) {
	dblm_note_user( $id, 'updated', $old->user_pass !== get_userdata( $id )->user_pass ? 'Passwort' : '' );
}, 10, 2 );

add_action( 'after_password_reset', function ( $user ) {
	dblm_note_user( $user->ID, 'updated', 'Passwort' );
} );

add_action( 'set_user_role', function ( $id, $role ) {
	dblm_note_user( $id, 'updated', 'Rolle: ' . translate_user_role( wp_roles()->role_names[ $role ] ?? $role ) );
}, 10, 2 );

add_action( 'delete_user', function ( $id ) {
	dblm_note_user( $id, 'deleted' );
} );


// Settings, plugins, theme. Plugins keep caches and timestamps in options, so only
// count what an administrator saves in a form or through the API, or WP-CLI.

foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
	add_action( $hook, function ( $option ) {
		$cli  = defined( 'WP_CLI' ) && WP_CLI;
		$save = did_action( 'init' ) && 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && ! wp_doing_cron() && 'heartbeat' !== ( $_POST['action'] ?? '' ) && current_user_can( 'manage_options' );
		if ( ( $cli || $save ) && ! preg_match( DBLM_SKIP_OPTIONS, $option ) ) {
			dblm_note( 'option', 0, 'Einstellungen', 'updated', $option );
		}
	} );
}

foreach ( array( 'activated_plugin' => 'activated', 'deactivated_plugin' => 'deactivated' ) as $hook => $action ) {
	add_action( $hook, function ( $file ) use ( $action ) {
		$plugin = get_file_data( WP_PLUGIN_DIR . '/' . $file, array( 'name' => 'Plugin Name' ) );
		dblm_note( 'plugin', 0, $plugin['name'] ?: $file, $action, '', $file, 'Plugin' );
	} );
}

add_action( 'switch_theme', function ( $name, $theme ) {
	dblm_note( 'theme', 0, $name, 'activated', '', $theme->get_stylesheet(), 'Theme' );
}, 10, 2 );


// Output to dashboard: the three latest changes, older ones folded away

add_action( 'wp_dashboard_setup', function () {
	if ( current_user_can( 'edit_others_posts' ) ) {
		wp_add_dashboard_widget( 'last_modified_widget', 'Letzte Änderungen', 'dblm_widget' );
	}
} );

function dblm_widget() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . dblm_table() . ' ' . dblm_visible() . ' ORDER BY logged_at DESC, id DESC LIMIT 50' );
	?>
	<style>
		#last_modified_widget ul { margin: 0; }
		#last_modified_widget li { display: flex; gap: 8px; margin-bottom: 10px; }
		#last_modified_widget .dashicons { color: #8c8f94; }
		#last_modified_widget small { display: block; color: #646970; font-size: 12px; }
		#last_modified_widget .deleted { color: #d63638; }
		#last_modified_widget summary { margin-bottom: 10px; color: #2271b1; cursor: pointer; }
	</style>
	<?php
	if ( ! $rows ) {
		echo '<p>Noch keine Änderungen.</p>';
	}
	dblm_list( array_slice( $rows, 0, 3 ) );
	if ( count( $rows ) > 3 ) {
		echo '<details><summary>Ältere anzeigen</summary>';
		dblm_list( array_slice( $rows, 3 ) );
		echo '</details>';
	}
	echo '<p><small>Protokoll seit ' . esc_html( wp_date( 'j. F Y, H:i', get_option( 'dblm_since' ) ) ) . ' Uhr</small></p>';
}

// Administrators see everything, everyone else only what they may edit themselves:
// their post types, their taxonomies and comments. Accounts, settings, plugins and menus stay hidden.

function dblm_visible() {
	if ( current_user_can( 'manage_options' ) ) {
		return '';
	}
	$types      = array_filter( get_post_types( array(), 'objects' ), function ( $type ) {
		return current_user_can( $type->cap->edit_posts );
	} );
	$taxonomies = array_filter( get_taxonomies( array(), 'objects' ), function ( $tax ) {
		return current_user_can( $tax->cap->manage_terms );
	} );
	$in = function ( $names ) {
		return "'" . implode( "','", array_map( 'esc_sql', array_keys( $names ) ) ) . "'";
	};
	return "WHERE ( object_type = 'post' AND object_subtype IN (" . $in( $types ) . ') )'
		. " OR ( object_type = 'term' AND object_subtype IN (" . $in( $taxonomies ) . ') )'
		. ( current_user_can( 'moderate_comments' ) ? " OR object_type = 'comment'" : '' );
}

function dblm_list( $rows ) {
	echo '<ul>';
	foreach ( $rows as $row ) {
		$time    = strtotime( $row->logged_at . ' UTC' );
		$days    = array( wp_date( 'Y-m-d' ) => 'Heute', wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) => 'Gestern' );
		$when    = ( $days[ wp_date( 'Y-m-d', $time ) ] ?? wp_date( 'j.n.Y', $time ) ) . ', ' . wp_date( 'H:i', $time );
		$details = $row->details ? explode( ', ', $row->details ) : array();
		if ( count( $details ) > 4 ) {
			$details = array_merge( array_slice( $details, 0, 3 ), array( ( count( $details ) - 3 ) . ' weitere' ) );
		}
		$title = esc_html( $row->object_name ?: '(ohne Titel)' );
		$link  = dblm_link( $row );
		printf(
			'<li><span class="dashicons %s"></span><div>%s<small>%s <span class="%s">%s</span>%s · <span title="%s">%s</span> · %s</small></div></li>',
			esc_attr( dblm_icon( $row ) ),
			$link ? '<a href="' . esc_url( $link ) . '">' . $title . '</a>' : $title,
			esc_html( $row->object_label ),
			in_array( $row->action, array( 'deleted', 'trashed' ), true ) ? 'deleted' : '',
			esc_html( DBLM_ACTIONS[ $row->action ] ?? $row->action ),
			$details ? esc_html( ' (' . implode( ', ', $details ) . ')' ) : '',
			esc_attr( wp_date( 'j. F Y, H:i:s', $time ) ),
			esc_html( $when ),
			esc_html( $row->user_name )
		);
	}
	echo '</ul>';
}

function dblm_icon( $row ) {
	$type  = 'post' === $row->object_type ? get_post_type_object( $row->object_subtype ) : null;
	$icons = array( 'page' => 'admin-page', 'attachment' => 'admin-media', 'nav_menu' => 'menu', 'term' => 'tag', 'comment' => 'admin-comments', 'user' => 'admin-users', 'option' => 'admin-settings', 'plugin' => 'admin-plugins', 'theme' => 'admin-appearance' );
	if ( $type && is_string( $type->menu_icon ) && 0 === strpos( $type->menu_icon, 'dashicons-' ) ) {
		return $type->menu_icon;
	}
	return 'dashicons-' . ( $icons[ $row->object_subtype ] ?? $icons[ $row->object_type ] ?? 'admin-post' );
}

function dblm_link( $row ) {
	$id = (int) $row->object_id;
	switch ( $row->object_type ) {
		case 'post':
			return get_edit_post_link( $id, 'raw' );
		case 'term':
			return 'nav_menu' === $row->object_subtype ? ( is_nav_menu( $id ) ? admin_url( 'nav-menus.php?menu=' . $id ) : '' ) : get_edit_term_link( $id, $row->object_subtype );
		case 'user':
			return get_userdata( $id ) ? get_edit_user_link( $id ) : '';
		case 'comment':
			return get_edit_comment_link( $id );
	}
	return '';
}
