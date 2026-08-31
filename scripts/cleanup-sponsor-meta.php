<?php
/**
 * One-time cleanup after replacing the sponsor reconciler with inline sync.
 *
 * Removes reconciler-era locks, journals, and statuses, and detaches
 * duplicated tracker IDs so posts stuck in needs_action can synchronize
 * again. When several posts share one bs_sponsor_advertisement_id (old-plugin
 * duplicates copied it freely), the oldest post keeps the tracker and the
 * copies are detached; each copy then creates its own tracker on its next
 * save if it is still marked sponsored.
 *
 * Usage (dry run by default, prints what would change):
 *   wp eval-file scripts/cleanup-sponsor-meta.php
 * Apply the changes:
 *   wp eval-file scripts/cleanup-sponsor-meta.php apply
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this through WP-CLI: wp eval-file scripts/cleanup-sponsor-meta.php [apply]\n");
    exit(1);
}

global $wpdb;

$apply = isset($args) && is_array($args) && in_array('apply', $args, true);
$label = $apply ? 'DELETE' : 'DRY RUN, would delete';

// 1. Reconciler-era option locks.
$lock_patterns = array(
    '_broadstreet_sponsor_lock_%',
    '_broadstreet_sponsor_advertiser_create_lock_%',
);
foreach ($lock_patterns as $pattern) {
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
        $pattern
    ));
    echo "{$label}: {$count} lock options matching {$pattern}\n";
    if ($apply && $count > 0) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $pattern
        ));
    }
}

// 2. Reconciler journals, fingerprints, ownership stamps, and stale statuses.
// Statuses regenerate on the next save; ownership stamps are obsolete.
$obsolete_meta_keys = array(
    '_bs_sponsor_tracker_create_attempt',
    '_bs_sponsor_tracker_move_attempt',
    '_bs_sponsor_reconciliation_fingerprint',
    '_bs_sponsor_reconciliation_status',
    '_bs_sponsor_remote_owner_post_id',
    '_bs_sponsor_advertiser_create_attempt',
);
foreach ($obsolete_meta_keys as $meta_key) {
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        $meta_key
    ));
    echo "{$label}: {$count} rows of {$meta_key}\n";
    if ($apply && $count > 0) {
        delete_post_meta_by_key($meta_key);
    }
}

// 3. Tracker IDs shared by more than one post. The oldest post keeps the
// tracker; every other post is detached (tracker ID and advertiser stamp
// removed) so it creates its own tracker on its next save.
$shared = $wpdb->get_col(
    "SELECT meta_value FROM {$wpdb->postmeta}
     WHERE meta_key = 'bs_sponsor_advertisement_id'
       AND meta_value REGEXP '^[1-9][0-9]*$'
     GROUP BY meta_value HAVING COUNT(DISTINCT post_id) > 1"
);

if (!$shared) {
    echo "No tracker IDs are shared between posts.\n";
} else {
    foreach ($shared as $advertisement_id) {
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'bs_sponsor_advertisement_id' AND pm.meta_value = %s
             ORDER BY p.post_date ASC, p.ID ASC",
            (string) $advertisement_id
        ));
        $keeper = array_shift($post_ids);
        echo "Tracker {$advertisement_id}: keeping post {$keeper}, "
            . ($apply ? 'detaching' : 'DRY RUN, would detach')
            . ' posts ' . implode(', ', $post_ids) . "\n";
        if ($apply) {
            foreach ($post_ids as $post_id) {
                delete_post_meta($post_id, 'bs_sponsor_advertisement_id');
                delete_post_meta($post_id, '_bs_sponsor_remote_advertiser_id');
            }
        }
    }
}

echo $apply
    ? "Cleanup applied.\n"
    : "Dry run complete. Re-run with 'apply' to make these changes.\n";
